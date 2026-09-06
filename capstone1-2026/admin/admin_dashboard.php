<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';
require_once '../config/csrf.php';
require_once '../config/availability.php';
require_once '../config/mailer.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: ../admin/admin_login.php");
    exit();
}

/* ── Mark-all-read (AJAX, no page reload) ──
   Persists the timestamp to the admins table so the unread count doesn't
   revert on refresh — previously "read" was only ever a client-side DOM
   change with nothing saved server-side. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_notifications_read') {
    csrfVerify();
    $stmt = $conn->prepare("UPDATE admins SET notif_last_read = NOW() WHERE admin_id = ?");
    $stmt->bind_param("i", $_SESSION['admin_id']);
    $stmt->execute();
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit();
}

/* ── Mark a single pending notification as read ──
   Uses the booking's created_at on the server instead of trusting a
   client-supplied timestamp. Because unread state is stored as one
   per-admin cutoff timestamp, reading one notification also marks any
   older pending notifications as read, but never newer ones. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_notification_read') {
    csrfVerify();
    $bid = intval($_POST['booking_id'] ?? 0);

    $stmt = $conn->prepare("SELECT created_at, status FROM bookings WHERE booking_id = ?");
    $stmt->bind_param("i", $bid);
    $stmt->execute();
    $notification = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $marked = false;
    if ($notification && strtolower(trim($notification['status'])) === 'pending') {
        $upd = $conn->prepare("UPDATE admins
            SET notif_last_read = CASE
                WHEN notif_last_read IS NULL OR notif_last_read < ? THEN ?
                ELSE notif_last_read
            END
            WHERE admin_id = ?");
        $createdAt = $notification['created_at'];
        $upd->bind_param("ssi", $createdAt, $createdAt, $_SESSION['admin_id']);
        $upd->execute();
        $marked = $upd->affected_rows >= 0;
        $upd->close();
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'marked' => $marked]);
    exit();
}

$admin_name = $_SESSION['admin_name'];
$success = '';
$error   = '';

/* ── Security settings schema compatibility ── */
$colCheck = $conn->query("SHOW COLUMNS FROM admins LIKE 'two_factor_enabled'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE admins ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER otp_email");
}

/* ── Activity-tracking schema compatibility ── */
$colCheck = $conn->query("SHOW COLUMNS FROM bookings LIKE 'cancelled_at'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE bookings ADD COLUMN cancelled_at DATETIME NULL AFTER confirmed_at");
}
$colCheck = $conn->query("SHOW COLUMNS FROM rooms LIKE 'badge_updated_at'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE rooms ADD COLUMN badge_updated_at DATETIME NULL AFTER badge");
}

/* ── Email settings schema compatibility ── */
$conn->query("
    CREATE TABLE IF NOT EXISTS email_settings (
        id TINYINT PRIMARY KEY DEFAULT 1,
        confirmation_emails TINYINT(1) NOT NULL DEFAULT 1,
        cancellation_emails TINYINT(1) NOT NULL DEFAULT 1,
        admin_alerts TINYINT(1) NOT NULL DEFAULT 1,
        sender_name VARCHAR(100) NOT NULL DEFAULT 'CoraVergel Resort',
        sender_email VARCHAR(150) NOT NULL DEFAULT 'coravergelresort@gmail.com'
    )
");
$conn->query("INSERT IGNORE INTO email_settings (id) VALUES (1)");
$email_settings = $conn->query("SELECT * FROM email_settings WHERE id=1")->fetch_assoc();

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Shared room-form config for the Add/Edit Room modals.
const ROOM_STATUS_OPTIONS = ['Available', 'Occupied', 'Maintenance'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    csrfVerify();
    $bid = intval($_POST['cancel_booking']);

    $stmt = $conn->prepare("SELECT booking_ref, guest_name, guest_email, room_type, check_in, check_out, total_price, status FROM bookings WHERE booking_id=?");
    $stmt->bind_param("i", $bid);
    $stmt->execute();
    $cancel_booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$cancel_booking) {
        $_SESSION['flash_error'] = "Booking not found.";
    } elseif ($cancel_booking['status'] === 'cancelled') {
        $_SESSION['flash_success'] = "That booking is already cancelled.";
    } else {
        $stmt = $conn->prepare("UPDATE bookings SET status='cancelled', cancelled_at=NOW() WHERE booking_id=?");
        $stmt->bind_param("i", $bid);
        $stmt->execute();
        $stmt->close();

        // Cancellation email is best-effort; the database status change remains successful
        // even if SMTP is temporarily unavailable.
        if (!empty($email_settings['cancellation_emails'])) {
            $cancel_email = buildBookingCancellationEmail(
                $cancel_booking['guest_name'],
                $cancel_booking['room_type'],
                $cancel_booking['check_in'],
                $cancel_booking['check_out'],
                $cancel_booking['total_price'],
                $cancel_booking['booking_ref']
            );
            $sent = sendMail(
                $cancel_booking['guest_email'],
                $cancel_booking['guest_name'],
                "CoraVergel Resort — Booking Cancelled",
                $cancel_email
            );

            $_SESSION['flash_success'] = $sent
                ? "Booking cancelled and the guest was notified by email."
                : "Booking cancelled, but the cancellation email could not be sent.";
        } else {
            $_SESSION['flash_success'] = "Booking cancelled. (Cancellation emails are turned off in Settings.)";
        }
    }
    header("Location: admin_dashboard.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking'])) {
    csrfVerify();
    $bid = intval($_POST['confirm_booking']);

    /* ── Wrapped in a transaction with row locking so two admins confirming
       the same room's last unit at the same instant can't both pass the
       availability check before either commit. Requires the bookings and
       rooms tables to use the InnoDB engine — MyISAM ignores locks/transactions
       silently, so this protection only holds if the tables are InnoDB. ── */
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT booking_ref, room_type, COALESCE(room_count,1) room_count, check_in, check_out, guests, total_price, guest_name, guest_email, status FROM bookings WHERE booking_id = ? FOR UPDATE");
        $stmt->bind_param("i", $bid);
        $stmt->execute();
        $booking_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$booking_info) {
            $conn->rollback();
            $_SESSION['flash_error'] = "Booking not found.";
        } elseif ($booking_info['status'] !== 'pending') {
            // Already confirmed (or cancelled) — a refresh landed here again.
            // Don't re-run the update or resend the email.
            $conn->rollback();
            $_SESSION['flash_success'] = "That booking is already " . $booking_info['status'] . ".";
        } else {
            // Lock the room row itself too, so a second confirm attempt for
            // the same room type has to wait for this transaction to finish
            // before it can even read availability.
            $lockStmt = $conn->prepare("SELECT room_id FROM rooms WHERE room_name = ? FOR UPDATE");
            $lockStmt->bind_param("s", $booking_info['room_type']);
            $lockStmt->execute();
            $lockStmt->close();

            if (!isRoomAvailable($conn, $booking_info['room_type'], $booking_info['check_in'], $booking_info['check_out'], $bid, (int)$booking_info['room_count'])) {
                $conn->rollback();
                $_SESSION['flash_error'] = "Can't confirm — " . htmlspecialchars($booking_info['room_type']) . " has no units left for " . fmtAdminDate($booking_info['check_in']) . " to " . fmtAdminDate($booking_info['check_out']) . ". Cancel a conflicting booking first, or contact the guest about different dates.";
            } else {
                $upd = $conn->prepare("UPDATE bookings SET status='confirmed', confirmed_at=NOW() WHERE booking_id=?");
                $upd->bind_param("i", $bid);
                $upd->execute();
                $upd->close();
                $conn->commit();

                try {
                    if (!empty($email_settings['confirmation_emails'])) {
                        $email_html = buildBookingConfirmationEmail(
                            $booking_info['guest_name'],
                            $booking_info['room_type'],
                            $booking_info['check_in'],
                            $booking_info['check_out'],
                            $booking_info['guests'],
                            $booking_info['total_price'],
                            $booking_info['booking_ref']
                        );
                        if (sendMail($booking_info['guest_email'], $booking_info['guest_name'], "Your CoraVergel Resort Booking is Confirmed", $email_html)) {
                            $_SESSION['flash_success'] = "Booking confirmed and email sent to " . htmlspecialchars($booking_info['guest_email']) . ".";
                        } else {
                            $_SESSION['flash_success'] = "Booking confirmed, but the email failed to send.";
                        }
                    } else {
                        $_SESSION['flash_success'] = "Booking confirmed. (Confirmation emails are turned off in Settings.)";
                    }
                } catch (Exception $e) {
                    error_log("Booking confirm email error: " . $e->getMessage());
                    $_SESSION['flash_success'] = "Booking confirmed, but the email failed to send.";
                }
            }
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Booking confirm transaction error: " . $e->getMessage());
        $_SESSION['flash_error'] = "Something went wrong confirming this booking. Please try again.";
    }

    header("Location: admin_dashboard.php");
    exit();
}

/* ── Helper: handle a single uploaded room photo.
   Returns ['primary' => filename|null, 'error' => string|null] ── */
function handleRoomPhotoUploads($files) {
    $result = ['primary' => null, 'error' => null];
    if (empty($files['name'][0])) return $result; // nothing uploaded

    $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
    $upload_dir = '../assets/images/rooms/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    // Only one photo per room — take the first file, ignore any others.
    if ($files['error'][0] !== UPLOAD_ERR_OK) return $result;
    $file_type = mime_content_type($files['tmp_name'][0]);
    if (!in_array($file_type, $allowed_types)) {
        $result['error'] = "Room photo must be a JPG, PNG, or WEBP file.";
        return $result;
    }
    if ($files['size'][0] > 5 * 1024 * 1024) {
        $result['error'] = "Room photo must be under 5MB.";
        return $result;
    }
    $ext = pathinfo($files['name'][0], PATHINFO_EXTENSION);
    $filename = 'room_' . uniqid() . '.' . strtolower($ext);
    if (move_uploaded_file($files['tmp_name'][0], $upload_dir . $filename)) {
        $result['primary'] = $filename;
    }
    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_room') {
    csrfVerify();
    $room_name   = htmlspecialchars(strip_tags(trim($_POST['room_name'])), ENT_QUOTES, 'UTF-8');
    $price       = (float) $_POST['price'];
    $total_units = intval($_POST['total_units']);
    $capacity    = intval($_POST['capacity'] ?? 4);
    $badge       = htmlspecialchars(trim($_POST['badge'] ?? 'Available'), ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars(strip_tags(trim($_POST['description'] ?? '')), ENT_QUOTES, 'UTF-8');

    if (empty($room_name) || $price <= 0 || $total_units < 1) {
        $error = "Please fill in all room fields with valid values.";
    } elseif ($capacity < 1) {
        $error = "Please enter a valid guest capacity.";
    } else {
        $upload = handleRoomPhotoUploads($_FILES['images'] ?? ['name' => []]);
        if ($upload['error']) {
            $error = $upload['error'];
        } else {
            $image_filename = $upload['primary'];
            try {
                $stmt = $conn->prepare("INSERT INTO rooms (room_name, price, total_units, description, image, capacity, badge) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sdissis", $room_name, $price, $total_units, $description, $image_filename, $capacity, $badge);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_success'] = "Room type added successfully.";
                header("Location: admin_dashboard.php");
                exit();
            } catch (mysqli_sql_exception $e) {
                $error = "Could not add room — \"$room_name\" already exists.";
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_room') {
    csrfVerify();
    $room_id     = intval($_POST['room_id']);
    $room_name   = htmlspecialchars(strip_tags(trim($_POST['room_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $price       = (float) $_POST['price'];
    $total_units = intval($_POST['total_units']);
    $capacity    = intval($_POST['capacity'] ?? 4);
    $badge       = htmlspecialchars(trim($_POST['badge'] ?? 'Available'), ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars(strip_tags(trim($_POST['description'] ?? '')), ENT_QUOTES, 'UTF-8');

    if (empty($room_name) || $price <= 0 || $total_units < 1) {
        $error = "Please fill in all room fields with valid values.";
    } elseif ($capacity < 1) {
        $error = "Please enter a valid guest capacity.";
    } else {
        // Get the current image before changing anything so it can be removed
        // only after a replacement image has been successfully saved to the DB.
        $currentRoomStmt = $conn->prepare("SELECT image, badge FROM rooms WHERE room_id=?");
        $currentRoomStmt->bind_param("i", $room_id);
        $currentRoomStmt->execute();
        $currentRoom = $currentRoomStmt->get_result()->fetch_assoc();
        $currentRoomStmt->close();

        if (!$currentRoom) {
            $error = "Room not found.";
        } else {
            $old_image    = trim((string)($currentRoom['image'] ?? ''));
            $prevBadge    = $currentRoom['badge'] ?? null;
            $badgeChanged = $prevBadge !== $badge;

            $upload = handleRoomPhotoUploads($_FILES['images'] ?? ['name' => []]);
            if ($upload['error']) {
                $error = $upload['error'];
            } else {
                $new_image = $upload['primary'];
                $new_upload_path = $new_image !== null
                    ? '../assets/images/rooms/' . basename($new_image)
                    : null;

                try {
                    // A new image is uploaded first. The old file is deleted only
                    // after the database update succeeds, so a failed update cannot
                    // leave the room without its original image.
                    if ($new_image !== null) {
                        $stmt = $conn->prepare("UPDATE rooms SET room_name=?, price=?, total_units=?, description=?, image=?, capacity=?, badge=? WHERE room_id=?");
                        $stmt->bind_param("sdissisi", $room_name, $price, $total_units, $description, $new_image, $capacity, $badge, $room_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE rooms SET room_name=?, price=?, total_units=?, description=?, capacity=?, badge=? WHERE room_id=?");
                        $stmt->bind_param("sdisisi", $room_name, $price, $total_units, $description, $capacity, $badge, $room_id);
                    }

                    $stmt->execute();
                    $stmt->close();

                    if ($badgeChanged) {
                        $touch = $conn->prepare("UPDATE rooms SET badge_updated_at=NOW() WHERE room_id=?");
                        $touch->bind_param("i", $room_id);
                        $touch->execute();
                        $touch->close();
                    }

                    // Only now remove the previous image from disk. The new image
                    // has its own generated filename, so it will not be mistaken
                    // for the old file.
                    if ($new_image !== null && $old_image !== '') {
                        $old_image_name = basename($old_image);
                        $old_image_path = '../assets/images/rooms/' . $old_image_name;
                        if ($old_image_name !== '' && file_exists($old_image_path)) {
                            unlink($old_image_path);
                        }
                    }

                    $_SESSION['flash_success'] = $new_image !== null
                        ? "Room updated successfully. The previous room image was replaced."
                        : "Room updated successfully.";
                    header("Location: admin_dashboard.php");
                    exit();
                } catch (Throwable $e) {
                    // If the DB update fails after a new file was uploaded, remove
                    // only that new file and keep the previous image intact.
                    if ($new_upload_path && file_exists($new_upload_path)) {
                        unlink($new_upload_path);
                    }
                    error_log("Room update error: " . $e->getMessage());
                    $error = "Could not update the room. Your previous image was kept.";
                }
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_room'])) {
    csrfVerify();
    $room_id = intval($_POST['delete_room']);

    // Fetch image filenames before removing the row so we can clean up disk too
    $stmt = $conn->prepare("SELECT image, gallery FROM rooms WHERE room_id = ?");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $img_result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($img_result) {
        $files_to_delete = array_filter(array_merge(
            [$img_result['image']],
            explode(',', $img_result['gallery'] ?? '')
        ));
        $upload_dir = '../assets/images/rooms/';
        foreach ($files_to_delete as $fname) {
            $fname = trim($fname);
            $path = $upload_dir . $fname;
            if ($fname !== '' && file_exists($path)) {
                unlink($path);
            }
        }
    }

    $stmt = $conn->prepare("DELETE FROM rooms WHERE room_id = ?");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $stmt->close();
    $_SESSION['flash_success'] = "Room type removed.";
    header("Location: admin_dashboard.php");
    exit();
}

/* ══════════════════════════════════════
   ACCOUNT SETTINGS — profile, password, OTP delivery, trusted devices
══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    csrfVerify();
    $new_full_name = htmlspecialchars(trim($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $new_username  = trim($_POST['username'] ?? '');
    $new_email     = trim($_POST['email'] ?? '');

    if (empty($new_full_name) || empty($new_username) || empty($new_email)) {
        $_SESSION['flash_error'] = "Please fill in all profile fields.";
    } elseif (mb_strlen($new_full_name) < 2 || mb_strlen($new_full_name) > 50) {
        $_SESSION['flash_error'] = "Full name must be between 2 and 50 characters.";
    } elseif (!preg_match('/^[A-Za-z0-9._]{3,30}$/', $new_username)) {
        $_SESSION['flash_error'] = "Username must be 3–30 characters and may only contain letters, numbers, periods, and underscores.";
    } elseif (mb_strlen($new_email) > 100) {
        $_SESSION['flash_error'] = "Email address must not exceed 100 characters.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_error'] = "Please enter a valid email address.";
    } else {
        // Uniqueness check against OTHER admin accounts only
        $chk = $conn->prepare("SELECT admin_id FROM admins WHERE (username = ? OR email = ?) AND admin_id != ?");
        $chk->bind_param("ssi", $new_username, $new_email, $_SESSION['admin_id']);
        $chk->execute();
        $taken = $chk->get_result()->num_rows > 0;
        $chk->close();

        if ($taken) {
            $_SESSION['flash_error'] = "That username or email is already used by another admin account.";
        } else {
            $upd = $conn->prepare("UPDATE admins SET full_name = ?, username = ?, email = ? WHERE admin_id = ?");
            $upd->bind_param("sssi", $new_full_name, $new_username, $new_email, $_SESSION['admin_id']);
            $upd->execute();
            $upd->close();
            $_SESSION['admin_name'] = $new_full_name; // keep header/sidebar in sync immediately
            $_SESSION['flash_success'] = "Profile updated successfully.";
        }
    }
    header("Location: admin_dashboard.php");
    exit();
}

/* ── Email settings: which resort events send email, and sender identity ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_email_settings') {
    csrfVerify();
    $confirmation_emails = isset($_POST['confirmation_emails']) ? 1 : 0;
    $cancellation_emails = isset($_POST['cancellation_emails']) ? 1 : 0;
    $admin_alerts        = isset($_POST['admin_alerts']) ? 1 : 0;
    $sender_name         = htmlspecialchars(trim($_POST['sender_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $sender_email        = trim($_POST['sender_email'] ?? '');

    if (empty($sender_name) || empty($sender_email)) {
        $_SESSION['flash_error'] = "Please fill in the sender name and email.";
    } elseif (!filter_var($sender_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash_error'] = "Please enter a valid sender email address.";
    } else {
        $upd = $conn->prepare("UPDATE email_settings SET confirmation_emails=?, cancellation_emails=?, admin_alerts=?, sender_name=?, sender_email=? WHERE id=1");
        $upd->bind_param("iiiss", $confirmation_emails, $cancellation_emails, $admin_alerts, $sender_name, $sender_email);
        $upd->execute();
        $upd->close();
        $_SESSION['flash_success'] = "Email preferences updated successfully.";
    }
    header("Location: admin_dashboard.php");
    exit();
}

/* ── Change password: current password + OTP when this account has 2FA ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrfVerify();
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password     = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    $stmt = $conn->prepare("SELECT password, email, full_name, two_factor_enabled FROM admins WHERE admin_id = ?");
    $stmt->bind_param("i", $_SESSION['admin_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current_password, $row['password'])) {
        $_SESSION['flash_error'] = "Your current password is incorrect.";
    } elseif (strlen($new_password) < 8) {
        $_SESSION['flash_error'] = "New password must be at least 8 characters.";
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['flash_error'] = "New password and confirmation don't match.";
    } elseif (password_verify($new_password, $row['password'])) {
        $_SESSION['flash_error'] = "New password must be different from your current password.";
    } elseif (!empty($row['two_factor_enabled'])) {
        // Do not change the password yet. Require a fresh OTP for this sensitive action.
        $otp = (string) random_int(100000, 999999);
        $_SESSION['password_change_otp'] = password_hash($otp, PASSWORD_DEFAULT);
        $_SESSION['password_change_otp_expires'] = time() + 600; // 10 minutes
        $_SESSION['pending_password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
        $_SESSION['pending_password_change_admin_id'] = (int)$_SESSION['admin_id'];

        $safe_name = htmlspecialchars($row['full_name'] ?: 'Administrator', ENT_QUOTES, 'UTF-8');
        $mail_subject = 'CoraVergel Resort — Password Change Verification Code';
        $mail_body = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#222">'
            . '<h2>Password Change Verification</h2>'
            . '<p>Hello ' . $safe_name . ',</p>'
            . '<p>Use the verification code below to confirm your password change:</p>'
            . '<div style="font-size:30px;font-weight:bold;letter-spacing:8px;padding:16px;background:#f5f5f5;text-align:center">' . $otp . '</div>'
            . '<p>This code expires in 10 minutes. If you did not request a password change, please secure your account immediately.</p>'
            . '</div>';

        $sent = function_exists('sendMail') ? sendMail($row['email'], $row['full_name'] ?: 'Administrator', $mail_subject, $mail_body) : false;
        if ($sent) {
            $_SESSION['flash_success'] = "Verification code sent. Enter the OTP to complete your password change.";
            $_SESSION['show_password_otp_modal'] = true;
        } else {
            unset($_SESSION['password_change_otp'], $_SESSION['password_change_otp_expires'], $_SESSION['pending_password_hash'], $_SESSION['pending_password_change_admin_id']);
            $_SESSION['flash_error'] = "Unable to send the verification code. Your password was not changed.";
        }
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE admins SET password = ? WHERE admin_id = ?");
        $upd->bind_param("si", $hashed, $_SESSION['admin_id']);
        $upd->execute();
        $upd->close();
        $_SESSION['flash_success'] = "Password changed successfully.";
    }
    header("Location: admin_dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_password_change_otp') {
    csrfVerify();
    $otp = preg_replace('/\D/', '', $_POST['password_change_otp'] ?? '');
    $validPending = !empty($_SESSION['pending_password_hash'])
        && !empty($_SESSION['pending_password_change_admin_id'])
        && (int)$_SESSION['pending_password_change_admin_id'] === (int)$_SESSION['admin_id'];

    if (!$validPending) {
        $_SESSION['flash_error'] = "No password change verification is pending.";
    } elseif (empty($_SESSION['password_change_otp_expires']) || time() > $_SESSION['password_change_otp_expires']) {
        unset($_SESSION['password_change_otp'], $_SESSION['password_change_otp_expires'], $_SESSION['pending_password_hash'], $_SESSION['pending_password_change_admin_id']);
        $_SESSION['flash_error'] = "The verification code expired. Please start the password change again.";
    } elseif (strlen($otp) !== 6 || !password_verify($otp, $_SESSION['password_change_otp'])) {
        $_SESSION['flash_error'] = "The verification code is incorrect.";
        $_SESSION['show_password_otp_modal'] = true;
    } else {
        $upd = $conn->prepare("UPDATE admins SET password = ? WHERE admin_id = ?");
        $upd->bind_param("si", $_SESSION['pending_password_hash'], $_SESSION['admin_id']);
        $upd->execute();
        $upd->close();
        unset($_SESSION['password_change_otp'], $_SESSION['password_change_otp_expires'], $_SESSION['pending_password_hash'], $_SESSION['pending_password_change_admin_id'], $_SESSION['show_password_otp_modal']);
        $_SESSION['flash_success'] = "Password changed successfully and verified with 2FA.";
    }
    header("Location: admin_dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_2fa') {
    csrfVerify();
    $enabled = isset($_POST['two_factor_enabled']) ? 1 : 0;

    if ($enabled) {
        // Turning 2FA ON only ever increases security — apply immediately.
        $upd = $conn->prepare("UPDATE admins SET two_factor_enabled = ? WHERE admin_id = ?");
        $upd->bind_param("ii", $enabled, $_SESSION['admin_id']);
        $upd->execute();
        $upd->close();
        $_SESSION['flash_success'] = "Two-factor authentication enabled.";
    } else {
        // Turning 2FA OFF removes a security control — require a fresh OTP first,
        // same as change_password does, so a hijacked session alone can't disable it.
        $stmt = $conn->prepare("SELECT email, full_name, two_factor_enabled FROM admins WHERE admin_id = ?");
        $stmt->bind_param("i", $_SESSION['admin_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (empty($row['two_factor_enabled'])) {
            // Already off — nothing to confirm.
            $_SESSION['flash_success'] = "Two-factor authentication is already disabled.";
        } else {
            $otp = (string) random_int(100000, 999999);
            $_SESSION['twofa_disable_otp'] = password_hash($otp, PASSWORD_DEFAULT);
            $_SESSION['twofa_disable_otp_expires'] = time() + 600; // 10 minutes
            $_SESSION['pending_2fa_disable_admin_id'] = (int)$_SESSION['admin_id'];

            $safe_name = htmlspecialchars($row['full_name'] ?: 'Administrator', ENT_QUOTES, 'UTF-8');
            $mail_subject = 'CoraVergel Resort — Disable Two-Factor Authentication';
            $mail_body = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#222">'
                . '<h2>Disable Two-Factor Authentication</h2>'
                . '<p>Hello ' . $safe_name . ',</p>'
                . '<p>Use the verification code below to confirm you want to turn OFF two-factor authentication on your account:</p>'
                . '<div style="font-size:30px;font-weight:bold;letter-spacing:8px;padding:16px;background:#f5f5f5;text-align:center">' . $otp . '</div>'
                . '<p>This code expires in 10 minutes. If you did not request this, your password may be compromised — change it immediately.</p>'
                . '</div>';

            $sent = function_exists('sendMail') ? sendMail($row['email'], $row['full_name'] ?: 'Administrator', $mail_subject, $mail_body) : false;
            if ($sent) {
                $_SESSION['flash_success'] = "Verification code sent. Enter the OTP to confirm disabling 2FA.";
                $_SESSION['show_2fa_disable_otp_modal'] = true;
            } else {
                unset($_SESSION['twofa_disable_otp'], $_SESSION['twofa_disable_otp_expires'], $_SESSION['pending_2fa_disable_admin_id']);
                $_SESSION['flash_error'] = "Unable to send the verification code. Two-factor authentication was not disabled.";
            }
        }
    }
    header("Location: admin_dashboard.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_2fa_disable_otp') {
    csrfVerify();
    $otp = preg_replace('/\D/', '', $_POST['twofa_disable_otp'] ?? '');
    $validPending = !empty($_SESSION['twofa_disable_otp'])
        && !empty($_SESSION['pending_2fa_disable_admin_id'])
        && (int)$_SESSION['pending_2fa_disable_admin_id'] === (int)$_SESSION['admin_id'];

    if (!$validPending) {
        $_SESSION['flash_error'] = "No 2FA-disable verification is pending.";
    } elseif (empty($_SESSION['twofa_disable_otp_expires']) || time() > $_SESSION['twofa_disable_otp_expires']) {
        unset($_SESSION['twofa_disable_otp'], $_SESSION['twofa_disable_otp_expires'], $_SESSION['pending_2fa_disable_admin_id']);
        $_SESSION['flash_error'] = "The verification code expired. Please try disabling 2FA again.";
    } elseif (strlen($otp) !== 6 || !password_verify($otp, $_SESSION['twofa_disable_otp'])) {
        $_SESSION['flash_error'] = "The verification code is incorrect.";
        $_SESSION['show_2fa_disable_otp_modal'] = true;
    } else {
        $upd = $conn->prepare("UPDATE admins SET two_factor_enabled = 0 WHERE admin_id = ?");
        $upd->bind_param("i", $_SESSION['admin_id']);
        $upd->execute();
        $upd->close();

        $del = $conn->prepare("DELETE FROM remember_tokens WHERE admin_id = ?");
        $del->bind_param("i", $_SESSION['admin_id']);
        $del->execute();
        $del->close();

        unset($_SESSION['twofa_disable_otp'], $_SESSION['twofa_disable_otp_expires'], $_SESSION['pending_2fa_disable_admin_id']);
        $_SESSION['flash_success'] = "Two-factor authentication disabled and trusted devices cleared.";
    }
    header("Location: admin_dashboard.php"); exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_device'])) {
    csrfVerify();
    $rid = intval($_POST['revoke_device']);
    $del = $conn->prepare("DELETE FROM remember_tokens WHERE id = ? AND admin_id = ?");
    $del->bind_param("ii", $rid, $_SESSION['admin_id']);
    $del->execute();
    $del->close();
    $_SESSION['flash_success'] = "Device removed — it will need to verify with OTP on its next login.";
    header("Location: admin_dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_all_devices'])) {
    csrfVerify();
    $del = $conn->prepare("DELETE FROM remember_tokens WHERE admin_id = ?");
    $del->bind_param("i", $_SESSION['admin_id']);
    $del->execute();
    $del->close();
    $_SESSION['flash_success'] = "All trusted devices removed — every device will need to verify with OTP on its next login.";
    header("Location: admin_dashboard.php");
    exit();
}

/* ── Stats ── */
$total_bookings = $conn->query("SELECT COUNT(*) c FROM bookings")->fetch_assoc()['c'];
$confirmed      = $conn->query("SELECT COUNT(*) c FROM bookings WHERE status='confirmed'")->fetch_assoc()['c'];
$pending_count  = $conn->query("SELECT COUNT(*) c FROM bookings WHERE status='pending'")->fetch_assoc()['c'];
$cancelled      = $conn->query("SELECT COUNT(*) c FROM bookings WHERE status='cancelled'")->fetch_assoc()['c'];
$upcoming       = $conn->query("SELECT COUNT(*) c FROM bookings WHERE check_in>=CURDATE()")->fetch_assoc()['c'];

/* ── Revenue Analytics ── */
$revenue_result = $conn->query("SELECT COALESCE(SUM(total_price),0) rev FROM bookings WHERE status='confirmed' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
$total_revenue  = $revenue_result ? $revenue_result->fetch_assoc()['rev'] : 0;

$prev_revenue_result = $conn->query("SELECT COALESCE(SUM(total_price),0) rev FROM bookings WHERE status='confirmed' AND MONTH(created_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(created_at)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))");
$prev_revenue = $prev_revenue_result ? $prev_revenue_result->fetch_assoc()['rev'] : 0;
$revenue_change = $prev_revenue > 0 ? round((($total_revenue - $prev_revenue) / $prev_revenue) * 100) : 0;

// All-time confirmed revenue, shown on the Bookings page's Total Revenue card
$alltime_revenue_result = $conn->query("SELECT COALESCE(SUM(total_price),0) rev FROM bookings WHERE status='confirmed'");
$alltime_revenue = $alltime_revenue_result ? $alltime_revenue_result->fetch_assoc()['rev'] : 0;

/* ── Dashboard "lively" stats ── */
$checked_in_today = $conn->query("SELECT COUNT(*) c FROM bookings WHERE status='confirmed' AND check_in=CURDATE()")->fetch_assoc()['c'];

$today_rev_result = $conn->query("SELECT COALESCE(SUM(total_price),0) rev FROM bookings WHERE status='confirmed' AND DATE(created_at)=CURDATE()");
$today_revenue = $today_rev_result ? $today_rev_result->fetch_assoc()['rev'] : 0;
$yesterday_rev_result = $conn->query("SELECT COALESCE(SUM(total_price),0) rev FROM bookings WHERE status='confirmed' AND DATE(created_at)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)");
$yesterday_revenue = $yesterday_rev_result ? $yesterday_rev_result->fetch_assoc()['rev'] : 0;
$today_revenue_change = $yesterday_revenue > 0 ? round((($today_revenue - $yesterday_revenue) / $yesterday_revenue) * 100) : ($today_revenue > 0 ? 100 : 0);

$bookings_this_month_result = $conn->query("SELECT COUNT(*) c FROM bookings WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
$bookings_this_month = $bookings_this_month_result ? $bookings_this_month_result->fetch_assoc()['c'] : 0;
$bookings_last_month_result = $conn->query("SELECT COUNT(*) c FROM bookings WHERE MONTH(created_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(created_at)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))");
$bookings_last_month = $bookings_last_month_result ? $bookings_last_month_result->fetch_assoc()['c'] : 0;
$bookings_growth = $bookings_last_month > 0 ? round((($bookings_this_month - $bookings_last_month) / $bookings_last_month) * 100) : ($bookings_this_month > 0 ? 100 : 0);

/* ── Booking Overview: daily bookings for this month vs last month ── */
$booking_chart_this_month = [];
$booking_chart_last_month = [];
$booking_chart_this_month_label = date('F Y');
$booking_chart_last_month_label = date('F Y', strtotime('first day of last month'));

$thisMonthStart = date('Y-m-01');
$nextMonthStart = date('Y-m-01', strtotime('+1 month'));
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));

$daysThisMonth = (int)date('t');
$daysLastMonth = (int)date('t', strtotime($lastMonthStart));

$booking_chart_this_month = array_fill(1, $daysThisMonth, 0);
$booking_chart_last_month = array_fill(1, $daysLastMonth, 0);

$bc = $conn->prepare("
    SELECT DAY(created_at) AS day_num, COUNT(*) AS total
    FROM bookings
    WHERE created_at >= ? AND created_at < ?
    GROUP BY DAY(created_at)
    ORDER BY DAY(created_at)
");
if ($bc) {
    $bc->bind_param('ss', $thisMonthStart, $nextMonthStart);
    $bc->execute();
    $bcResult = $bc->get_result();
    while ($row = $bcResult->fetch_assoc()) {
        $day = (int)$row['day_num'];
        if (isset($booking_chart_this_month[$day])) {
            $booking_chart_this_month[$day] = (int)$row['total'];
        }
    }
    $bc->close();
}

$bc = $conn->prepare("
    SELECT DAY(created_at) AS day_num, COUNT(*) AS total
    FROM bookings
    WHERE created_at >= ? AND created_at < ?
    GROUP BY DAY(created_at)
    ORDER BY DAY(created_at)
");
if ($bc) {
    $bc->bind_param('ss', $lastMonthStart, $thisMonthStart);
    $bc->execute();
    $bcResult = $bc->get_result();
    while ($row = $bcResult->fetch_assoc()) {
        $day = (int)$row['day_num'];
        if (isset($booking_chart_last_month[$day])) {
            $booking_chart_last_month[$day] = (int)$row['total'];
        }
    }
    $bc->close();
}

/* ── Dashboard KPI data ── */
$total_bookings_result = $conn->query("SELECT COUNT(*) c FROM bookings");
$total_bookings = $total_bookings_result ? (int)$total_bookings_result->fetch_assoc()['c'] : 0;

$pending_bookings_result = $conn->query("SELECT COUNT(*) c FROM bookings WHERE status='pending'");
$pending_bookings = $pending_bookings_result ? (int)$pending_bookings_result->fetch_assoc()['c'] : 0;

$checked_in_today_result = $conn->query("
    SELECT COUNT(*) c
    FROM bookings
    WHERE status='confirmed'
      AND check_in = CURDATE()
");
$checked_in_today = $checked_in_today_result ? (int)$checked_in_today_result->fetch_assoc()['c'] : 0;

$total_rooms_result = $conn->query("SELECT COALESCE(SUM(total_units),0) c FROM rooms");
$total_rooms = $total_rooms_result ? (int)$total_rooms_result->fetch_assoc()['c'] : 0;

$total_revenue_result = $conn->query("
    SELECT COALESCE(SUM(total_price),0) c
    FROM bookings
    WHERE status='confirmed'
");
$total_revenue = $total_revenue_result ? (float)$total_revenue_result->fetch_assoc()['c'] : 0;

$occupied_today_result = $conn->query("
    SELECT COALESCE(SUM(COALESCE(room_count,1)),0) c
    FROM bookings
    WHERE status='confirmed'
      AND check_in <= CURDATE()
      AND check_out > CURDATE()
");
$occupied_today = $occupied_today_result ? (int)$occupied_today_result->fetch_assoc()['c'] : 0;

$available_today = max(0, $total_rooms - $occupied_today);
$occupancy_percent = $total_rooms > 0 ? round(($occupied_today / $total_rooms) * 100) : 0;

/* Today's Activity is built below (see "Dashboard: Recent Bookings + Today's Activity"),
   combining bookings + room-status events into one real feed. */

$room_revenue = [];
$rr = $conn->query("SELECT room_type, COALESCE(SUM(total_price),0) rev, COUNT(*) cnt FROM bookings WHERE status='confirmed' GROUP BY room_type ORDER BY rev DESC");
if ($rr) while ($row = $rr->fetch_assoc()) $room_revenue[] = $row;
$max_room_rev = !empty($room_revenue) ? max(array_column($room_revenue, 'rev')) : 1;

/* ── Avg stay ── */
$avg_stay_result = $conn->query("SELECT AVG(DATEDIFF(check_out,check_in)) avg_nights FROM bookings WHERE status='confirmed'");
$avg_stay = $avg_stay_result ? round($avg_stay_result->fetch_assoc()['avg_nights'], 1) : 0;

/* ── Occupancy (confirmed bookings this month / days in month as rough %) ── */
$occ_result = $conn->query("SELECT COUNT(*) c FROM bookings WHERE status='confirmed' AND check_in>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND check_in<=LAST_DAY(CURDATE())");
$occ_count       = $occ_result ? $occ_result->fetch_assoc()['c'] : 0;
$days_this_month = (int) date('t');
$occupancy       = min(100, round(($occ_count / max(1, $days_this_month)) * 100));

/* ── Chart data ── */
$room_stats = [];
$rs = $conn->query("SELECT room_type, COUNT(*) total FROM bookings GROUP BY room_type ORDER BY total DESC");
while ($row = $rs->fetch_assoc()) $room_stats[] = $row;

$monthly_stats = [];
$ms = $conn->query("SELECT DATE_FORMAT(created_at,'%b') month, COUNT(*) total, COALESCE(SUM(total_price),0) revenue FROM bookings WHERE YEAR(created_at)=YEAR(CURDATE()) GROUP BY MONTH(created_at) ORDER BY MONTH(created_at)");
while ($row = $ms->fetch_assoc()) $monthly_stats[] = $row;

/* ── Calendar: bookings per day this month ── */
$cal_bookings = [];
$cb = $conn->query("SELECT DAY(check_in) d, COUNT(*) cnt FROM bookings WHERE MONTH(check_in)=MONTH(CURDATE()) AND YEAR(check_in)=YEAR(CURDATE()) GROUP BY DAY(check_in)");
if ($cb) while ($row = $cb->fetch_assoc()) $cal_bookings[$row['d']] = $row['cnt'];

/* ── Upcoming check-ins (next 7 days) ──
   The bookings table has no user_id column — guests book without an
   account (see rooms.php), so guest_name/guest_email on the booking
   itself is the only identity info there is. No join needed. ── */
$upcoming_checkins = [];
$uc = $conn->query("SELECT booking_id, guest_name full_name, room_type, COALESCE(room_count,1) room_count, check_in, status
    FROM bookings
    WHERE check_in BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY check_in ASC LIMIT 5");
if ($uc) while ($row = $uc->fetch_assoc()) {
    // Normalize case — MySQL comparisons are case-insensitive by default
    // (so SQL COUNT queries work fine either way), but PHP's === below is
    // strict, so "Pending" vs "pending" would otherwise silently mismatch.
    $row['status'] = strtolower(trim($row['status']));
    $upcoming_checkins[] = $row;
}

$rooms_list = [];
$rq = $conn->query("SELECT room_id, room_name, price, total_units, description, image, capacity, badge, badge_updated_at, tags FROM rooms ORDER BY room_name");
while ($row = $rq->fetch_assoc()) {
    $confStmt = $conn->prepare("SELECT COUNT(*) c FROM bookings WHERE room_type = ? AND status = 'confirmed' AND confirmed_at IS NOT NULL AND DATE(confirmed_at) = CURDATE()");
    $confStmt->bind_param("s", $row['room_name']);
    $confStmt->execute();
    $row['confirmed_today'] = (int) $confStmt->get_result()->fetch_assoc()['c'];
    $confStmt->close();

    // New pending requests that came in today for this room — still awaiting action
    $pendStmt = $conn->prepare("SELECT COUNT(*) c FROM bookings WHERE room_type = ? AND status = 'pending' AND DATE(created_at) = CURDATE()");
    $pendStmt->bind_param("s", $row['room_name']);
    $pendStmt->execute();
    $row['pending_today'] = (int) $pendStmt->get_result()->fetch_assoc()['c'];
    $pendStmt->close();

    // Physical occupancy today — confirmed guests whose stay includes today
    $row['occupied_today'] = countOverlappingBookingsByStatus($conn, $row['room_name'], date('Y-m-d'), date('Y-m-d', strtotime('+1 day')), 'confirmed');

    $rooms_list[] = $row;
}
$rooms_total_units    = array_sum(array_column($rooms_list, 'total_units'));
$rooms_occupied_today  = array_sum(array_column($rooms_list, 'occupied_today'));
$rooms_available_today = max(0, $rooms_total_units - $rooms_occupied_today);

// Room "Type" isn't its own column — derive a category label from the room
// name (e.g. "Deluxe Room 101" -> "Deluxe", "Beachfront Villa 301" -> "Villa").
function deriveRoomType($name){
    $n = trim(preg_replace('/\s+/', ' ', preg_replace('/\d+/', '', $name)));
    $lower = strtolower($n);
    if (strpos($lower, 'family suite') !== false) return 'Family Suite';
    if (strpos($lower, 'villa') !== false)        return 'Villa';
    if (strpos($lower, 'suite') !== false)        return 'Suite';
    if (strpos($lower, 'deluxe') !== false)       return 'Deluxe';
    if (strpos($lower, 'standard') !== false)     return 'Standard';
    // Strip a leading size/descriptor word so e.g. "Large Bahay Kubo" and
    // "Small Bahay Kubo" both collapse to the same "Bahay Kubo" type.
    $sizeWords = ['Large','Small','Big','Mini','Grand','Super','Petite','Junior','Beachfront'];
    foreach ($sizeWords as $w) {
        if (stripos($n, $w) === 0) {
            $n = trim(substr($n, strlen($w)));
            break;
        }
    }
    $n2 = trim(preg_replace('/\bRoom\b/i', '', $n));
    return $n2 !== '' ? $n2 : $n;
}
$room_type_options = [];
foreach ($rooms_list as $rm) {
    $t = deriveRoomType($rm['room_name']);
    if (!in_array($t, $room_type_options, true)) $room_type_options[] = $t;
}
sort($room_type_options);

/* ── Bookings ──
   No user_id on bookings — guest_name is the guest's identity as entered
   on the booking form. ── */
$bookings = [];
$bq = $conn->query("SELECT booking_id, booking_ref, guest_name full_name, guest_email, id_type, id_photo, contact_number, room_type, COALESCE(room_count,1) room_count, check_in, check_out, guests, adults, children, status, created_at, confirmed_at, cancelled_at, payment_method, payment_reference, payment_receipt, COALESCE(total_price,0) total_price
    FROM bookings
    ORDER BY created_at DESC");
    while ($row = $bq->fetch_assoc()) {
    $row['status'] = strtolower(trim($row['status']));
    $bookings[] = $row;
}

/* ── Notifications ── */
$notif_last_read = null;
$stmt = $conn->prepare("SELECT notif_last_read FROM admins WHERE admin_id = ?");
$stmt->bind_param("i", $_SESSION['admin_id']);
$stmt->execute();
$notif_last_read = $stmt->get_result()->fetch_assoc()['notif_last_read'] ?? null;
$stmt->close();

$notifications = [];
$nq = $conn->query("SELECT 'booking' notif_type, booking_id, guest_name full_name, room_type, check_in, check_out, status, created_at, confirmed_at,
    CASE WHEN LOWER(TRIM(status)) = 'confirmed' AND confirmed_at IS NOT NULL THEN confirmed_at ELSE created_at END AS notif_at
    FROM bookings
    ORDER BY notif_at DESC LIMIT 15");
while ($row = $nq->fetch_assoc()) {
    $row['status'] = strtolower(trim($row['status']));
    $notifications[] = $row;
}

usort($notifications, fn($a,$b) => strtotime($b['notif_at']) - strtotime($a['notif_at']));
$notifications = array_slice($notifications, 0, 20);

function notif_is_unread($n, $notif_last_read) {
    if ($n['status'] !== 'pending') return false;
    if (!$notif_last_read) return true;
    return strtotime($n['created_at']) > strtotime($notif_last_read);
}

$unread_count = array_reduce($notifications, function($c,$n) use ($notif_last_read) {
    return notif_is_unread($n, $notif_last_read) ? $c+1 : $c;
}, 0);

/* ── Dashboard: Recent Bookings + Today's Activity ──
   Builds one real feed of today's events from actual timestamps —
   not just "bookings touching today" guessed from check-in/out dates. ── */
$recent_bookings = array_slice($bookings, 0, 5);

$today_activity = [];
foreach ($bookings as $b) {
    $desc = $b['full_name'].' — '.$b['room_type'];

    if (!empty($b['created_at']) && date('Y-m-d', strtotime($b['created_at'])) === date('Y-m-d')) {
        $today_activity[] = [
            'type'   => 'booking',
            'title'  => 'New booking received',
            'desc'   => $desc,
            'ref'    => $b['booking_ref'],
            'status' => $b['status'],
            'at'     => $b['created_at'],
        ];
    }
    if (!empty($b['confirmed_at']) && date('Y-m-d', strtotime($b['confirmed_at'])) === date('Y-m-d')) {
        $today_activity[] = [
            'type'   => 'payment',
            'title'  => 'Payment received',
            'desc'   => $desc,
            'ref'    => $b['booking_ref'],
            'status' => $b['status'],
            'at'     => $b['confirmed_at'],
        ];
    }
    if (!empty($b['cancelled_at']) && date('Y-m-d', strtotime($b['cancelled_at'])) === date('Y-m-d')) {
        $today_activity[] = [
            'type'   => 'cancelled',
            'title'  => 'Booking cancelled',
            'desc'   => $desc,
            'ref'    => $b['booking_ref'],
            'status' => 'cancelled',
            'at'     => $b['cancelled_at'],
        ];
    }
    if ($b['status'] === 'confirmed' && $b['check_in'] === date('Y-m-d')) {
        $today_activity[] = [
            'type'   => 'checkin',
            'title'  => 'Guest checked in',
            'desc'   => $b['full_name'],
            'ref'    => $b['booking_ref'],
            'status' => 'confirmed',
            'at'     => $b['check_in'].' 00:00:00', // exact check-in time isn't tracked; sorts with other same-day events by date only
        ];
    }
}

foreach ($rooms_list as $rm) {
    if (($rm['badge'] ?? '') === 'Maintenance' && !empty($rm['badge_updated_at'])
        && date('Y-m-d', strtotime($rm['badge_updated_at'])) === date('Y-m-d')) {
        $today_activity[] = [
            'type'   => 'maintenance',
            'title'  => 'Room maintenance',
            'desc'   => $rm['room_name'],
            'ref'    => null,
            'status' => 'maintenance',
            'at'     => $rm['badge_updated_at'],
        ];
    }
}

usort($today_activity, fn($a, $b) => strtotime($b['at']) - strtotime($a['at']));
$today_activity = array_slice($today_activity, 0, 6);

/* ── Login Activity ── */
$login_history = [];
$lh = $conn->query("SELECT id, admin_id, username, ip_address, user_agent, login_method, logged_in_at
    FROM login_history
    ORDER BY logged_in_at DESC
    LIMIT 100");
if ($lh) while ($row = $lh->fetch_assoc()) $login_history[] = $row;

$total_logins = count($login_history);
$last_login   = !empty($login_history) ? $login_history[0]['logged_in_at'] : null;
$remembered_logins = count(array_filter($login_history, fn($l) => $l['login_method'] === 'remembered'));

/* ── Account Settings data ── */
$stmt = $conn->prepare("SELECT full_name, username, email, otp_email, two_factor_enabled FROM admins WHERE admin_id = ?");
$stmt->bind_param("i", $_SESSION['admin_id']);
$stmt->execute();
$current_admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

$trusted_devices = [];
$td = $conn->prepare("SELECT id, expires_at FROM remember_tokens WHERE admin_id = ? ORDER BY expires_at DESC");
$td->bind_param("i", $_SESSION['admin_id']);
$td->execute();
$tdRes = $td->get_result();
while ($row = $tdRes->fetch_assoc()) $trusted_devices[] = $row;
$td->close();

function parseUserAgent($ua) {
    if (empty($ua)) return 'Unknown device';
    $browser = 'Unknown browser';
    if (stripos($ua, 'Edg/') !== false)        $browser = 'Edge';
    elseif (stripos($ua, 'Chrome/') !== false) $browser = 'Chrome';
    elseif (stripos($ua, 'Firefox/') !== false)$browser = 'Firefox';
    elseif (stripos($ua, 'Safari/') !== false) $browser = 'Safari';

    $os = 'Unknown OS';
    if (stripos($ua, 'Windows') !== false)      $os = 'Windows';
    elseif (stripos($ua, 'Mac OS') !== false)   $os = 'macOS';
    elseif (stripos($ua, 'Android') !== false)  $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
    elseif (stripos($ua, 'Linux') !== false)    $os = 'Linux';

    return $browser . ' on ' . $os;
}

/* ── Booking confirmation email — matches site's navy/gold design system ── */
function buildBookingConfirmationEmail($guest_name, $room_type, $check_in, $check_out, $guests, $total_price, $booking_ref) {
    $ci = date('m-d-Y', strtotime($check_in));
    $co = date('m-d-Y', strtotime($check_out));
    $total = number_format($total_price, 2);

    return '
    <div style="background:#f5f2ed;padding:32px 16px;font-family:\'DM Sans\',Arial,sans-serif;">
      <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #f0ede8;">

        <!-- Header -->
        <div style="background:#1a1a2e;padding:32px 28px;text-align:center;">
          <div style="font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:#c8a96e;margin-bottom:8px;">CoraVergel Resort</div>
          <div style="font-family:\'Cormorant Garamond\',Georgia,serif;font-size:28px;font-weight:600;color:#ffffff;">Booking Confirmed</div>
        </div>

        <!-- Body -->
        <div style="padding:28px;">
          <span style="display:inline-block;padding:4px 12px;border-radius:20px;background:#e8f5e9;color:#2e7d32;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:18px;">Confirmed</span>

          <p style="font-size:14px;color:#1a1a2e;line-height:1.6;margin:14px 0;">
            Hi ' . htmlspecialchars($guest_name) . ',<br>
            Great news your stay at <strong>CoraVergel Resort</strong> is officially confirmed. We can&rsquo;t wait to welcome you.
          </p>

          <!-- Room card -->
          <div style="background:#fafaf8;border:1px solid #f0ede8;border-radius:10px;padding:18px 20px;margin:20px 0;">
            <div style="font-family:\'Cormorant Garamond\',Georgia,serif;font-size:18px;font-weight:600;color:#1a1a2e;margin-bottom:14px;">' . htmlspecialchars($room_type) . '</div>

            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
              <tr>
                <td width="50%" style="padding-bottom:12px;">
                  <div style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#aaa;margin-bottom:3px;">Check-in</div>
                  <div style="font-weight:600;color:#1a1a2e;">' . $ci . '</div>
                  <div style="color:#aaa;font-size:11px;">From 2:00 PM</div>
                </td>
                <td width="50%" style="padding-bottom:12px;">
                  <div style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#aaa;margin-bottom:3px;">Check-out</div>
                  <div style="font-weight:600;color:#1a1a2e;">' . $co . '</div>
                  <div style="color:#aaa;font-size:11px;">Until 12:00 PM</div>
                </td>
              </tr>
              <tr>
                <td>
                  <div style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#aaa;margin-bottom:3px;">Guests</div>
                  <div style="font-weight:600;color:#1a1a2e;">' . intval($guests) . ' guest' . ($guests != 1 ? 's' : '') . '</div>
                </td>
                <td>
                  <div style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#aaa;margin-bottom:3px;">Booking Ref</div>
                  <div style="font-weight:600;color:#1a1a2e;">#' . htmlspecialchars($booking_ref) . '</div>
                </td>
              </tr>
            </table>
          </div>

          <!-- Total -->
          <div style="background:#1a1a2e;border-radius:10px;padding:14px 20px;display:table;width:100%;box-sizing:border-box;margin-bottom:24px;">
            <div style="display:table-cell;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:rgba(255,255,255,.5);vertical-align:middle;">Total Amount</div>
            <div style="display:table-cell;text-align:right;font-size:18px;font-weight:700;color:#c8a96e;vertical-align:middle;">&#8369;' . $total . '</div>
          </div>

          <!-- Reminders -->
          <div style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#aaa;margin-bottom:10px;">Before You Arrive</div>
          <ul style="margin:0 0 24px;padding:0;list-style:none;font-size:13px;color:#555;line-height:2;">
            <li>&bull; Please bring the valid ID you submitted during booking</li>
            <li>&bull; No outside food &amp; beverages allowed</li>
            <li>&bull; Free swimming included for overnight guests</li>
            <li>&bull; Quiet hours: 10:00 PM &ndash; 6:00 AM</li>
          </ul>

          <p style="font-size:13px;color:#555;line-height:1.6;">
            Questions or need to make changes? Call us at <strong>+320 2512</strong>.
          </p>
        </div>

        <!-- Footer -->
        <div style="background:#fafaf8;border-top:1px solid #f0ede8;padding:20px 28px;text-align:center;">
          <div style="font-family:\'Cormorant Garamond\',Georgia,serif;font-size:15px;font-weight:600;color:#1a1a2e;">CoraVergel Resort</div>
          <div style="font-size:11px;color:#aaa;margin-top:4px;">Barosong, Tigbauan, Iloilo City, Philippines</div>
          <div style="font-size:11px;color:#aaa;">coravergelresort@gmail.com</div>
        </div>

      </div>
    </div>';
}

function buildBookingCancellationEmail($guest_name, $room_type, $check_in, $check_out, $total_price, $booking_ref) {
    $ci = date('m-d-Y', strtotime($check_in));
    $co = date('m-d-Y', strtotime($check_out));
    $total = number_format((float)$total_price, 2);

    return '
    <div style="background:#f5f2ed;padding:32px 16px;font-family:\'DM Sans\',Arial,sans-serif;">
      <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #f0ede8;">

        <!-- Header -->
        <div style="background:#1a1a2e;padding:32px 28px;text-align:center;">
          <div style="font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:#c8a96e;margin-bottom:8px;">CoraVergel Resort</div>
          <div style="font-family:\'Cormorant Garamond\',Georgia,serif;font-size:28px;font-weight:600;color:#ffffff;">Booking Cancelled</div>
        </div>

        <!-- Body -->
        <div style="padding:28px;">
          <span style="display:inline-block;padding:4px 12px;border-radius:20px;background:#fdecea;color:#c0392b;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:18px;">&#10005; Cancelled</span>

          <p style="font-size:14px;color:#1a1a2e;line-height:1.6;margin:14px 0;">
            Hi ' . htmlspecialchars($guest_name) . ',<br>
            Your reservation at <strong>CoraVergel Resort</strong> has been cancelled. If this wasn&rsquo;t what you expected, please reach out and we&rsquo;ll be glad to help.
          </p>

          <!-- Room card -->
          <div style="background:#fafaf8;border:1px solid #f0ede8;border-radius:10px;padding:18px 20px;margin:20px 0;">
            <div style="font-family:\'Cormorant Garamond\',Georgia,serif;font-size:18px;font-weight:600;color:#1a1a2e;margin-bottom:14px;">' . htmlspecialchars($room_type) . '</div>

            <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
              <tr>
                <td width="50%" style="padding-bottom:12px;">
                  <div style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#aaa;margin-bottom:3px;">Check-in</div>
                  <div style="font-weight:600;color:#1a1a2e;">' . $ci . '</div>
                </td>
                <td width="50%" style="padding-bottom:12px;">
                  <div style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#aaa;margin-bottom:3px;">Check-out</div>
                  <div style="font-weight:600;color:#1a1a2e;">' . $co . '</div>
                </td>
              </tr>
              <tr>
                <td>
                  <div style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#aaa;margin-bottom:3px;">Booking Ref</div>
                  <div style="font-weight:600;color:#1a1a2e;">#' . htmlspecialchars($booking_ref) . '</div>
                </td>
                <td>
                  <div style="font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#aaa;margin-bottom:3px;">Amount</div>
                  <div style="font-weight:600;color:#1a1a2e;">&#8369;' . $total . '</div>
                </td>
              </tr>
            </table>
          </div>

          <p style="font-size:13px;color:#555;line-height:1.6;">
            If you believe this cancellation was made in error, please call us at <strong>+320 2512</strong> or reply to this email.
          </p>
        </div>

        <!-- Footer -->
        <div style="background:#fafaf8;border-top:1px solid #f0ede8;padding:20px 28px;text-align:center;">
          <div style="font-family:\'Cormorant Garamond\',Georgia,serif;font-size:15px;font-weight:600;color:#1a1a2e;">CoraVergel Resort</div>
          <div style="font-size:11px;color:#aaa;margin-top:4px;">21 Barosong, Tigbauan, Iloilo City, Philippines</div>
          <div style="font-size:11px;color:#aaa;">+320 2512 &middot; coravergelresort@gmail.com</div>
        </div>

      </div>
    </div>';
}

function fmtAdminDate($d) {
    return date('m-d-Y', strtotime($d));
}

function human_time_diff($ts) {
    $d = max(0, time()-$ts);
    if ($d<60)     return 'Just now';
    if ($d<3600)   return floor($d/60).'m ago';
    if ($d<86400)  return floor($d/3600).'h ago';
    if ($d<604800) return floor($d/86400).'d ago';
    return date('M d, Y',$ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard</title>
<link rel="icon" href="../assets/images/logo/cv_logo.png" sizes="any">
<link rel="stylesheet" href="../assets/css/admin.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">

    <div class="sb-brand">
        <img class="sb-logo" src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort">
        <div class="sb-brand-text">
            <span class="sb-name">CoraVergel Resort</span>
            <span class="sb-sub">Admin Panel</span>
        </div>
    </div>
    <div class="sb-nav">
        <button class="sb-item active" onclick="showSection('overview',this)">
            <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
        </button>
        <button class="sb-item" onclick="showSection('bookings',this)">
            <i class="fa-solid fa-calendar-check"></i><span>Bookings</span>
            <?php if($pending_count>0): ?><span class="sb-badge"><?=$pending_count?></span><?php endif; ?>
        </button>
        <button class="sb-item" onclick="showSection('rooms',this)">
            <i class="fa-solid fa-bed"></i><span>Rooms</span>
        </button>
        <button class="sb-item" onclick="showSection('notifications',this)">
            <i class="fa-solid fa-bell"></i><span>Notifications</span>
            <?php if($unread_count>0): ?><span class="sb-badge"><?=$unread_count?></span><?php endif; ?>
        </button>
        <div class="sb-group-label">ACCOUNT</div>
        <button class="sb-item" onclick="showSection('settings',this)">
            <i class="fa-solid fa-gear"></i><span>Settings</span>
        </button>
        <div class="sb-group-label">SITE</div>
        <a href="../user/index.php" class="sb-item" target="_blank">
            <i class="fa-solid fa-globe"></i><span>View Website</span>
        </a>
    </div>
    <div class="sb-footer">
        <div class="sb-admin">
            <div class="sb-admin-info">
                <span class="sb-admin-name"><?=htmlspecialchars($admin_name)?></span>
                <span class="sb-admin-role">Administrator</span>
            </div>
        </div>
        <a href="admin_login.php" class="sb-logout">
            <i class="fa-solid fa-right-from-bracket"></i> Sign Out
        </a>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>
<!-- ══ MAIN ══ -->
<div class="main-wrap">
    <!-- Topbar -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle nav-hamburger" id="adminHamburger" onclick="toggleSidebar()" aria-label="Toggle menu">
                <span></span><span></span><span></span>
            </button>
            <div class="topbar-title">
                <h1 id="topbarTitleText">Dashboard</h1>
                <p id="topbarTitleSub">Welcome back, <?=htmlspecialchars($admin_name)?>. Here's what's happening today.</p>
            </div>
        </div>
        <div class="topbar-right">
            <button type="button" class="rooms-add-btn" id="roomsAddBtn"
                    onclick="openAddRoomModal()" style="display:none;">
                <i class="fa-solid fa-plus"></i>
                <span>Add New Room</span>
            </button>
            <div id="csrfTokenHolder" style="display:none"><?= csrfField() ?></div>
            <div class="notif-wrap" id="notifWrap">
                <button class="notif-bell" onclick="toggleNotif(event)">
                    <i class="fa-regular fa-bell"></i>
                    <?php if($unread_count>0): ?><span class="notif-count"><?=$unread_count?></span><?php endif; ?>
                </button>
                <div class="notif-panel" id="notifPanel">
                    <div class="notif-panel-head">
                        <div class="notif-panel-title">
                            <i class="fa-solid fa-bell"></i> Notifications
                            <?php if($unread_count>0): ?><span class="notif-unread-pill"><?=$unread_count?> new</span><?php endif; ?>
                        </div>
                        <?php if($unread_count>0): ?>
                        <button class="notif-mark-all" onclick="markAllRead()">Mark all read</button>
                        <?php endif; ?>
                    </div>
                    <div class="notif-list">
                        <?php if(empty($notifications)): ?>
                        <div class="notif-empty"><i class="fa-regular fa-bell-slash"></i><p>No notifications yet</p></div>
                        <?php else: ?>
                        <?php foreach($notifications as $n):
                            $ago       = human_time_diff(strtotime($n['notif_at']));
                            $is_unread = notif_is_unread($n, $notif_last_read);
                            $icon      = $n['status']==='confirmed'?'fa-circle-check':($n['status']==='cancelled'?'fa-ban':'fa-clock');
                            $icon_cls  = $n['status']==='confirmed'?'ni--green':($n['status']==='cancelled'?'ni--red':'ni--gold');
                        ?>
                        <div class="notif-item <?=$is_unread?'notif-item--unread':''?>"
                             onclick="markNotificationRead(<?=$n['booking_id']?>); goToBooking(<?=$n['booking_id']?>)">
                            <div class="ni-icon <?=$icon_cls?>"><i class="fa-solid <?=$icon?>"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">
                                    <?php if($n['status']==='pending'): ?>
                                        <strong><?=htmlspecialchars($n['full_name'])?></strong> made a new booking
                                    <?php elseif($n['status']==='confirmed'): ?>
                                        Booking confirmed for <strong><?=htmlspecialchars($n['full_name'])?></strong>
                                    <?php else: ?>
                                        Booking cancelled — <strong><?=htmlspecialchars($n['full_name'])?></strong>
                                    <?php endif; ?>
                                </div>
                                <div class="ni-meta">
                                    <span class="ni-room"><i class="fa-solid fa-bed"></i> <?=htmlspecialchars($n['room_type'])?></span>
                                    <span class="ni-sep">·</span>
                                    <span><?=date('M j',strtotime($n['check_in']))?> - <?=date('M j',strtotime($n['check_out']))?></span>
                                </div>
                                <div class="ni-time" data-notif-time="<?=htmlspecialchars($n['notif_at'], ENT_QUOTES, 'UTF-8')?>"><?=$ago?></div>
                            </div>
                            <?php if($is_unread): ?><div class="ni-dot"></div><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="notif-panel-foot">
                        <button onclick="showSection('notifications',document.querySelectorAll('.sb-item')[3]);closeNotif();">
                            View all notifications <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Alerts -->
    <?php if($success): ?>
    <div class="dash-alert dash-alert--success" id="dashAlert">
        <i class="fa-solid fa-circle-check"></i> <?=htmlspecialchars($success)?>
    </div>
    <?php endif; ?>
    <?php if($error): ?>
    <div class="dash-alert dash-alert--error" id="dashAlert">
        <i class="fa-solid fa-circle-exclamation"></i> <?=htmlspecialchars($error)?>
    </div>
    <?php endif; ?>

    <!-- OVERVIEW -->
    <section class="dash-section active" id="section-overview">
        <div class="kpi-row kpi-row--auto">
            <div class="kpi-card kpi-clickable" onclick="showSection('bookings',document.querySelectorAll('.sb-item')[1])">
                <div class="kpi-icon" style="background:#fdf1de;color:#c8860d"><i class="fa-solid fa-calendar-days"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total Bookings</div>
                    <div class="kpi-value"><?=$total_bookings?></div>
                    <div class="kpi-change <?=$bookings_growth>0?'up':($bookings_growth<0?'down':'neutral')?>">
                        <i class="fa-solid fa-arrow-<?=$bookings_growth>=0?'up':'down'?>"></i> <?=abs($bookings_growth)?>% from last month
                    </div>
                </div>
            </div>
            <div class="kpi-card kpi-clickable" onclick="filterByStatus('pending');showSection('bookings',document.querySelectorAll('.sb-item')[1])">
                <div class="kpi-icon" style="background:#fff8e1;color:#e65100"><i class="fa-solid fa-clipboard-list"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pending Bookings</div>
                    <div class="kpi-value" style="color:<?=$pending_count>0?'#e65100':'#1a1a2e'?>"><?=$pending_count?></div>
                    <div class="kpi-change neutral">Awaiting confirmation</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#e8f5e9;color:#2e7d32"><i class="fa-solid fa-user-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Checked In Today</div>
                    <div class="kpi-value"><?=$checked_in_today?></div>
                    <div class="kpi-change neutral">Guests</div>
                </div>
            </div>
            <div class="kpi-card kpi-clickable" onclick="showSection('rooms',document.querySelectorAll('.sb-item')[2])">
                <div class="kpi-icon" style="background:#f0f4ff;color:#1a1a2e"><i class="fa-solid fa-bed"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total Rooms</div>
                    <div class="kpi-value"><?=$total_rooms?></div>
                    <div class="kpi-change neutral">Rooms</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#e8f5e9;color:#2e7d32"><i class="fa-solid fa-sack-dollar"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Today's Revenue</div>
                    <div class="kpi-value">₱<?=number_format($today_revenue,0)?></div>
                    <div class="kpi-change <?=$today_revenue_change>0?'up':($today_revenue_change<0?'down':'neutral')?>">
                        <i class="fa-solid fa-arrow-<?=$today_revenue_change>=0?'up':'down'?>"></i> <?=abs($today_revenue_change)?>% from yesterday
                    </div>
                </div>
            </div>
        </div>

        <div class="charts-row-new">
            <div class="chart-card-new booking-overview-reference">
                <div class="booking-overview-header">
                    <div>
                        <h3>Booking Overview</h3>
                    </div>
                    <div class="booking-month-select-wrap">
                        <select id="bookingMonthSelect" class="booking-month-select" aria-label="Booking overview month">
                            <option value="this">This Month</option>
                            <option value="last">Last Month</option>
                        </select>
                        <i class="fa-solid fa-chevron-down"></i>
                    </div>
                </div>
                <div class="booking-overview-legend">
                    <span><i class="legend-dot this-month"></i><span id="bookingThisLegend">This Month</span></span>
                    <span><i class="legend-dot last-month"></i><span id="bookingLastLegend">Last Month</span></span>
                </div>
                <div class="booking-overview-chart-wrap">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
            <div class="chart-card-new">
                <div class="chart-card-header-new">
                    <div><h3>Room Occupancy</h3><p>Today's status</p></div>
                </div>
                <div class="occ-donut-wrap">
                    <div class="occ-donut-chart">
                        <canvas id="occupancyChart"></canvas>
                        <div class="occ-donut-center">
                            <div class="occ-donut-pct"><?=$occupancy_percent?>%</div>
                            <div class="occ-donut-lbl">Occupied</div>
                        </div>
                    </div>
                    <div class="occ-legend">
                        <div class="occ-legend-item"><span class="occ-dot" style="background:#c8a96e"></span>Occupied<span class="occ-legend-val"><?=$occupied_today?> rooms</span></div>
                        <div class="occ-legend-item"><span class="occ-dot" style="background:#1a1a2e"></span>Available<span class="occ-legend-val"><?=$available_today?> rooms</span></div>
                    </div>
                </div>
                <div class="occ-footer-link">
                    <button class="view-all-link" onclick="showSection('rooms',document.querySelectorAll('.sb-item')[2])">View Room Availability <i class="fa-solid fa-arrow-right"></i></button>
                </div>
            </div>
        </div>

        <div class="charts-row-new">
            <div class="table-card" style="margin:0;">
                <div class="table-card-head">
                    <div><h3>Recent Bookings</h3><p>Latest reservations</p></div>
                    <button class="view-all-link" onclick="showSection('bookings',document.querySelectorAll('.sb-item')[1])">View All <i class="fa-solid fa-arrow-right"></i></button>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Booking Ref</th><th>Guest</th><th>Room</th><th>Check-in</th><th>Check-out</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php if(empty($recent_bookings)): ?>
                            <tr><td colspan="6" class="empty-cell">No bookings yet.</td></tr>
                            <?php else: foreach($recent_bookings as $rb): ?>
                            <tr>
                                <td><button class="ref-link" onclick="jumpToBookingRef('<?=htmlspecialchars($rb['booking_ref'],ENT_QUOTES)?>')">#<?=htmlspecialchars($rb['booking_ref'])?></button></td>
                                <td><?=htmlspecialchars($rb['full_name'])?></td>
                                <td><?=htmlspecialchars($rb['room_type'])?></td>
                                <td><?=date('M d, Y',strtotime($rb['check_in']))?></td>
                                <td><?=date('M d, Y',strtotime($rb['check_out']))?></td>
                                <td>
                                    <?php if($rb['status']==='confirmed'): ?>
                                        <span class="status-badge status--confirmed">Confirmed</span>
                                    <?php elseif($rb['status']==='cancelled'): ?>
                                        <span class="status-badge status--cancelled">Cancelled</span>
                                    <?php else: ?>
                                        <span class="status-badge status--pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="table-card" style="margin:0;">
                <div class="table-card-head">
                    <div><h3>Today's Activity</h3><p>Latest resort activity</p></div>
                    <button class="view-all-link" onclick="showSection('notifications',document.querySelectorAll('.sb-item')[3])">View All <i class="fa-solid fa-arrow-right"></i></button>
                </div>
                <div class="activity-list">
                    <?php if(empty($today_activity)): ?>
                        <div class="activity-empty">No activity today.</div>
                    <?php else: ?>
                        <?php
                            $activity_icons = [
                                'booking'     => 'fa-calendar-plus',
                                'payment'     => 'fa-credit-card',
                                'checkin'     => 'fa-user-check',
                                'cancelled'   => 'fa-xmark',
                                'maintenance' => 'fa-screwdriver-wrench',
                            ];
                        ?>
                        <?php foreach($today_activity as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon activity-icon--<?=htmlspecialchars($activity['type'])?>">
                                    <i class="fa-solid <?=$activity_icons[$activity['type']] ?? 'fa-calendar-check'?>"></i>
                                </div>
                                <div class="activity-body">
                                    <strong><?=htmlspecialchars($activity['title'])?></strong>
                                    <span><?=htmlspecialchars($activity['desc'])?></span>
                                </div>
                                <div class="activity-meta">
                                    <span class="status-badge status--<?=htmlspecialchars(strtolower($activity['status']))?>">
                                        <?=ucfirst(htmlspecialchars($activity['status']))?>
                                    </span>
                                    <span class="activity-time"><?=date('h:i A', strtotime($activity['at']))?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>


    </section>

    <!-- BOOKINGS -->
    <section class="dash-section" id="section-bookings">
        <div class="kpi-row kpi-row--auto">
            <div class="kpi-card kpi-clickable" onclick="filterByStatus('all')">
                <div class="kpi-icon" style="background:#f0f4ff;color:#1a1a2e"><i class="fa-solid fa-calendar-days"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">All Bookings</div>
                    <div class="kpi-value"><?=$total_bookings?></div>
                    <div class="kpi-change neutral">Total reservations</div>
                </div>
            </div>
            <div class="kpi-card kpi-clickable" onclick="filterByStatus('pending')">
                <div class="kpi-icon" style="background:#fff8e1;color:#e65100"><i class="fa-solid fa-clock"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pending</div>
                    <div class="kpi-value" style="color:<?=$pending_count>0?'#e65100':'#1a1a2e'?>"><?=$pending_count?></div>
                    <div class="kpi-change neutral">Awaiting confirmation</div>
                </div>
            </div>
            <div class="kpi-card kpi-clickable" onclick="filterByStatus('confirmed')">
                <div class="kpi-icon" style="background:#e8f5e9;color:#2e7d32"><i class="fa-solid fa-circle-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Confirmed</div>
                    <div class="kpi-value"><?=$confirmed?></div>
                    <div class="kpi-change neutral">Upcoming stays</div>
                </div>
            </div>
            <div class="kpi-card kpi-clickable" onclick="filterByStatus('cancelled')">
                <div class="kpi-icon" style="background:#fce4ec;color:#c62828"><i class="fa-solid fa-ban"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Cancelled</div>
                    <div class="kpi-value"><?=$cancelled?></div>
                    <div class="kpi-change neutral">Cancelled bookings</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#fdf6e8;color:#a07840"><i class="fa-solid fa-chart-line"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total Revenue</div>
                    <div class="kpi-value">₱<?=number_format($total_revenue,0)?></div>
                    <div class="kpi-change neutral">From <?=$total_bookings?> total bookings</div>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="book-tabs">
                <button class="book-tab active" id="btab-all" onclick="filterByStatus('all')">All Bookings</button>
                <button class="book-tab" id="btab-pending" onclick="filterByStatus('pending')">Pending</button>
                <button class="book-tab" id="btab-confirmed" onclick="filterByStatus('confirmed')">Confirmed</button>
                <button class="book-tab" id="btab-cancelled" onclick="filterByStatus('cancelled')">Cancelled</button>
            </div>
            <div class="table-card-head">
                <div><h3>All Bookings</h3><p id="bookingCount"><?=count($bookings)?> total</p></div>
                <div class="table-controls">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" placeholder="Search" id="bookingSearch" oninput="filterBookings()">
                    </div>
                    <select class="btn-filter" id="roomFilterSelect" onchange="filterByRoom(this.value)" style="cursor:pointer;">
                        <option value="all">All Rooms</option>
                        <?php foreach($rooms_list as $rm): ?>
                        <option value="<?=strtolower(htmlspecialchars($rm['room_name'],ENT_QUOTES))?>"><?=htmlspecialchars($rm['room_name'])?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="date-filter-wrap">
                        <button class="btn-filter" id="dateFilterBtn" onclick="toggleDateFilter()" onmousedown="event.stopPropagation()">
                            <div class="btn-filter-icon"><i class="fa-regular fa-calendar"></i></div>
                            <div class="btn-filter-text">
                                <span class="btn-filter-label">Date Range</span>
                                <span class="btn-filter-val" id="dateFilterLabel">All dates</span>
                            </div>
                            <i class="fa-solid fa-chevron-down btn-filter-chevron" id="dateFilterChevron"></i>
                        </button>
                        <input type="text" id="adminDateRange"
                            style="position:absolute;bottom:0;right:0;width:100%;height:0;opacity:0;pointer-events:none;border:0;padding:0;">
                    </div>
                </div>
            </div>
            <div class="table-wrap">
                <table id="bookingsTable">
                    <thead>
                        <tr><th>Booking Ref</th><th>Guest</th><th>Room</th><th>Check-in / Check-out</th><th>Capacity</th><th>Total</th><th>Status</th><th>Booked On</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($bookings)): ?>
                        <tr><td colspan="9" class="empty-cell">No bookings found.</td></tr>
                        <?php else: foreach($bookings as $i => $b):
                            $nights = (new DateTime($b['check_in']))->diff(new DateTime($b['check_out']))->days;
                        ?>
                        <tr class="b-row"
                            data-bid="<?=$b['booking_id']?>"
                            data-name="<?=strtolower(htmlspecialchars($b['full_name']))?>"
                            data-room="<?=strtolower(htmlspecialchars($b['room_type']))?>"
                            data-ref="<?=strtolower(htmlspecialchars($b['booking_ref']))?>"
                            data-status="<?=$b['status']?>"
                            data-checkin="<?=$b['check_in']?>"
                            data-checkout="<?=$b['check_out']?>">
                            <td class="row-num">
                                #<?=htmlspecialchars($b['booking_ref'])?>
                            </td>
                            <td>
                                <div class="guest-cell">
                                    <div>
                                        <div class="guest-name"><?=htmlspecialchars($b['full_name'])?></div>
                                        <?php if (!empty($b['guest_email'])): ?>
                                        <div class="guest-sub"><?=htmlspecialchars($b['guest_email'])?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($b['contact_number'])): ?>
                                        <div class="guest-sub"><?=htmlspecialchars($b['contact_number'])?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><span class="tag tag--room"><?=htmlspecialchars($b['room_type'])?></span><?php if ((int)($b['room_count'] ?? 1) > 1): ?><div class="guest-sub"><?=intval($b['room_count'])?> rooms</div><?php endif; ?></td>
                            <td>
                                <?=date('M d, Y',strtotime($b['check_in']))?><br>
                                <?=date('M d, Y',strtotime($b['check_out']))?>
                                <div class="guest-sub"><?=$nights?> Night<?=$nights!=1?'s':''?></div>
                            </td>
                            <td><i class="fa-regular fa-user" style="color:#000;);font-size:12px;margin-right:5px;"></i><?=$b['guests']?></td>
                            <td>₱<?=number_format($b['total_price'] ?? 0,2)?></td>
                            <td>
                                <?php if($b['status']==='confirmed'): ?>
                                    <span class="status-badge status--confirmed">Confirmed</span>
                                <?php elseif($b['status']==='cancelled'): ?>
                                    <span class="status-badge status--cancelled">Cancelled</span>
                                <?php else: ?>
                                    <span class="status-badge status--pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?=date('M d, Y',strtotime($b['created_at']))?><br><span style="color:#000;"><?=date('g:i A',strtotime($b['created_at']))?></span></td>
<?php
    $bm_checkin  = date('F j, Y',strtotime($b['check_in']));
    $bm_checkout = date('F j, Y',strtotime($b['check_out']));
    $bm_booked_date = date('F j, Y',strtotime($b['created_at']));
    $bm_booked_time = date('g:i A',strtotime($b['created_at']));
    $bm_idphoto  = !empty($b['id_photo']) ? '../assets/uploads/ids/' . $b['id_photo'] : '';
    $bm_adults   = (int) ($b['adults'] ?? 0);
    $bm_children = (int) ($b['children'] ?? 0);
    $bm_receipt  = !empty($b['payment_receipt']) ? '../assets/uploads/receipts/' . $b['payment_receipt'] : '';

    // Some guest-facing forms run htmlspecialchars() before INSERT, so a few
    // existing rows have literal "&#039;" etc. baked into the text itself
    // (e.g. id_type stored as "Driver&#039;s License"). Decoding here fixes
    // display for those rows without touching the database; it's a no-op on
    // rows that were stored clean.
    $bm_full_name  = html_entity_decode($b['full_name'], ENT_QUOTES);
    $bm_id_type    = html_entity_decode($b['id_type'] ?? '', ENT_QUOTES);
    $bm_paymethod  = html_entity_decode($b['payment_method'] ?? '', ENT_QUOTES);
    $bm_payref     = html_entity_decode($b['payment_reference'] ?? '', ENT_QUOTES);

    // Build the JS argument list with json_encode (not htmlspecialchars) so values
    // like Driver's License or Sean & Amy stay intact — .textContent never decodes
    // HTML entities, so an htmlspecialchars'd apostrophe used to render as the
    // literal text "&#039;" in the modal. json_encode handles JS-string escaping,
    // and htmlspecialchars() is applied once, only to keep the result safe inside
    // the double-quoted onclick="..." attribute.
    $bm_args = json_encode([
        $bm_full_name, $b['room_type'], $bm_checkin, $bm_checkout, $nights,
        $b['guests'], $bm_booked_date, $bm_booked_time, $b['guest_email'] ?? '',
        $bm_id_type, $b['contact_number'] ?? '', $b['status'], $bm_idphoto,
        $bm_adults, $bm_children, $bm_paymethod,
        $bm_payref, $bm_receipt, $b['booking_ref'],
        (float) ($b['total_price'] ?? 0), $b['booking_id'],
    ]);
    $bm_view_call = "openBookingModal.apply(null, " . htmlspecialchars($bm_args, ENT_QUOTES) . ")";
?>
                            <td>
                                <div class="row-actions">
                                    <button class="action-icon-btn" title="View details" onclick="<?=$bm_view_call?>">
                                        <i class="fa-regular fa-eye"></i>
                                    </button>
                                    <?php if($b['status']==='pending'): ?>
                                    <div class="action-menu" id="am-<?=$b['booking_id']?>">
                                        <button class="action-icon-btn" onclick="toggleMenu(<?=$b['booking_id']?>,event)">
                                            <i class="fa-solid fa-ellipsis-vertical"></i>
                                        </button>
                                        <div class="action-dropdown">
                                            <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Confirm this booking?')" style="margin:0;">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="confirm_booking" value="<?=$b['booking_id']?>">
                                                <button type="submit" class="ad-item ad-confirm" style="width:100%;border:none;background:none;text-align:left;font:inherit;cursor:pointer;">
                                                    <i class="fa-solid fa-circle-check"></i> Confirm
                                                </button>
                                            </form>
                                            <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Cancel this booking?')" style="margin:0;">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="cancel_booking" value="<?=$b['booking_id']?>">
                                                <button type="submit" class="ad-item ad-cancel" style="width:100%;border:none;background:none;text-align:left;font:inherit;cursor:pointer;">
                                                    <i class="fa-solid fa-ban"></i> Cancel
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <div class="empty-state" id="noBookings" style="display:none;">
                    <i class="fa-regular fa-calendar-xmark"></i>
                    <p>No bookings match your filters.</p>
                </div>
                <div class="pg-bar" id="bookingsPager">
                    <div class="pg-summary" id="pgSummary"></div>
                    <div class="pg-controls">
                        <button class="pg-arrow" id="pgPrev" onclick="pgGo(currentPage-1)"><i class="fa-solid fa-chevron-left"></i></button>
                        <div class="pg-nums" id="pgNums"></div>
                        <button class="pg-arrow" id="pgNext" onclick="pgGo(currentPage+1)"><i class="fa-solid fa-chevron-right"></i></button>
                        <select class="pg-size" id="pgSizeSelect" onchange="changePageSize(this.value)">
                            <option value="10">10 / page</option>
                            <option value="25">25 / page</option>
                            <option value="50">50 / page</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ROOMS -->
    <section class="dash-section" id="section-rooms">

        <div class="kpi-row kpi-row--auto">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#f0f4ff;color:#1a1a2e"><i class="fa-solid fa-bed"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total Rooms</div>
                    <div class="kpi-value"><?=$rooms_total_units?></div>
                    <div class="kpi-change neutral">All rooms</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:var(--green-bg);color:var(--green)"><i class="fa-solid fa-calendar-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label" style="color:var(--green)">Available</div>
                    <div class="kpi-value"><?=$rooms_available_today?></div>
                    <div class="kpi-change neutral"><?=$rooms_total_units>0?round(($rooms_available_today/$rooms_total_units)*100,1):0?>% available</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:var(--red-bg);color:var(--red)"><i class="fa-solid fa-user"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label" style="color:var(--red)">Occupied</div>
                    <div class="kpi-value"><?=$rooms_occupied_today?></div>
                    <div class="kpi-change neutral"><?=$rooms_total_units>0?round(($rooms_occupied_today/$rooms_total_units)*100,1):0?>% occupied</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:var(--blue-bg);color:var(--blue)"><i class="fa-solid fa-wrench"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label" style="color:var(--blue)">Maintenance</div>
                    <div class="kpi-value">0</div>
                    <div class="kpi-change neutral">0% maintenance</div>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="rooms-toolbar">
                <div class="search-box rooms-search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="roomsSearchInput" placeholder="Search" oninput="filterRoomsTable()">
                </div>
                <div class="rooms-select-wrap rooms-quick-select">
                    <select id="roomsStatusSelect" onchange="setRoomStatusQuick(this.value)">
                        <option value="all">All Status</option>
                        <option value="Available">Available</option>
                        <option value="Occupied">Occupied</option>
                        <option value="Maintenance">Maintenance</option>
                    </select>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="rooms-select-wrap rooms-quick-select">
                    <select id="roomsTypeSelect" onchange="setRoomTypeQuick(this.value)">
                        <option value="all">All Room Types</option>
                        <?php foreach ($room_type_options as $rt): ?>
                        <option value="<?=htmlspecialchars(strtolower($rt),ENT_QUOTES)?>"><?=htmlspecialchars($rt)?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fa-solid fa-chevron-down"></i>
                </div>
                <div class="rooms-filter-wrap" id="roomsFilterWrap">
                    <button type="button" class="rooms-filter-btn" id="roomsFilterBtn" onclick="toggleRoomsFilter(event)">
                        <i class="fa-solid fa-sliders"></i> Filter
                        <span class="rooms-filter-count" id="roomsFilterCount" style="display:none;">0</span>
                    </button>
                    <div class="rooms-filter-panel" id="roomsFilterPanel" onclick="event.stopPropagation()">
                        <div class="rooms-filter-head">
                            <div>
                                <strong>Filter Rooms</strong>
                                <span>Narrow down your room inventory.</span>
                            </div>
                            <button type="button" class="rooms-filter-close" aria-label="Close filters" onclick="toggleRoomsFilter(event)"><i class="fa-solid fa-xmark"></i></button>
                        </div>

                        <div class="rooms-filter-body">
                                <div class="rooms-filter-group">
                                    <label>Capacity (Guests)</label>
                                    <div class="rooms-select-wrap">
                                        <select id="roomsCapacitySelect" onchange="setRoomCapacity(this.value)">
                                            <option value="all">Any Capacity</option>
                                            <option value="2">1–2 Guests</option>
                                            <option value="4">3–4 Guests</option>
                                            <option value="6">5–6 Guests</option>
                                            <option value="7">7+ Guests</option>
                                        </select>
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </div>
                                </div>

                                <div class="rooms-filter-group">
                                    <label>Price per Night</label>
                                    <div class="rooms-price-fields rooms-price-fields--cards">
                                        <div class="rooms-price-input-card">
                                            <span>Min Price</span>
                                            <div><b>₱</b><input type="number" id="roomsMinPrice" min="0" placeholder="0" oninput="filterRoomsTable()"></div>
                                        </div>
                                        <span class="rooms-price-dash">—</span>
                                        <div class="rooms-price-input-card">
                                            <span>Max Price</span>
                                            <div><b>₱</b><input type="number" id="roomsMaxPrice" min="0" placeholder="20,000" oninput="filterRoomsTable()"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="rooms-filter-group rooms-filter-group--last">
                                    <label>Sort By</label>
                                    <div class="rooms-select-wrap">
                                        <select id="roomsSortBy" onchange="filterRoomsTable()">
                                            <option value="default">Default (A–Z)</option>
                                            <option value="priceAsc">Price: Low to High</option>
                                            <option value="priceDesc">Price: High to Low</option>
                                            <option value="capacityAsc">Capacity: Low to High</option>
                                            <option value="capacityDesc">Capacity: High to Low</option>
                                            <option value="availabilityDesc">Most Available</option>
                                            <option value="availabilityAsc">Least Available</option>
                                        </select>
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="rooms-filter-foot">
                                <button type="button" class="rooms-clear-btn" onclick="clearRoomsFilters()">Reset</button>
                                <button type="button" class="rooms-apply-btn" onclick="applyRoomsFilterPanel()"><i class="fa-solid fa-sliders"></i> Apply Filters</button>
                            </div>
                        </div>
                    </div>
                </div>
            <div class="table-wrap">
                <table id="roomsTable">
                    <thead>
                        <tr><th>Room</th><th>Type</th><th>Capacity</th><th>Price / Night</th><th>Status</th><th>Availability</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($rooms_list)): ?>
                        <tr><td colspan="7" class="empty-cell">No rooms yet. Add one to get started.</td></tr>
                        <?php else: foreach($rooms_list as $i => $rm):
                            $available   = max(0, $rm['total_units'] - $rm['occupied_today']);
                            $statusLabel = in_array($rm['badge'], ['Available', 'Occupied', 'Maintenance'], true)
                                ? $rm['badge']
                                : 'Available';
                            $statusClass = $statusLabel === 'Maintenance' ? 'status--maintenance' : ($statusLabel === 'Occupied' ? 'status--cancelled' : 'status--confirmed');
                            $imgUrl      = !empty($rm['image']) ? '../assets/images/rooms/'.$rm['image'] : null;
                            $roomType    = deriveRoomType($rm['room_name']);
                            $view_args   = json_encode([
                                $rm['room_name'], $imgUrl, (float)$rm['price'], (int)($rm['capacity'] ?? 0),
                                $statusLabel, (int)$available, (int)$rm['total_units'], $rm['description'] ?? ''
                            ]);
                            $view_call   = "openViewRoomModal.apply(null, " . htmlspecialchars($view_args, ENT_QUOTES) . ")";
                        ?>
                        <tr data-status="<?=htmlspecialchars($statusLabel,ENT_QUOTES)?>" data-type="<?=htmlspecialchars(strtolower($roomType),ENT_QUOTES)?>" data-search="<?=strtolower(htmlspecialchars($rm['room_name'],ENT_QUOTES))?>" data-capacity="<?=(int)($rm['capacity'] ?? 0)?>" data-price="<?=htmlspecialchars((string)$rm['price'],ENT_QUOTES)?>" data-available="<?=(int)$available?>" data-total-units="<?=(int)$rm['total_units']?>">
                            <td>
                                <div class="room-cell">
                                    <?php if($imgUrl): ?>
                                    <img src="<?=htmlspecialchars($imgUrl)?>" alt="" class="room-cell-img">
                                    <?php else: ?>
                                    <div class="room-cell-img room-cell-img--empty"><i class="fa-solid fa-image"></i></div>
                                    <?php endif; ?>
                                    <span class="room-cell-name"><?=htmlspecialchars($rm['room_name'])?></span>
                                </div>
                            </td>
                            <td class="room-type-cell"><?=htmlspecialchars($roomType)?></td>
                            <td><i class="fa-regular fa-user" style="color:#000;font-size:12px;margin-right:5px;"></i><?=(int)($rm['capacity'] ?? 0)?></td>
                            <td>₱<?=number_format($rm['price'],0)?></td>
                            <td><span class="status-badge <?=$statusClass?>"><?=htmlspecialchars($statusLabel)?></span></td>
                            <td><?=$available?> / <?=$rm['total_units']?></td>
                            <td>
                                <div class="row-actions">
                                    <button class="action-icon-btn" title="View details" onclick="<?=$view_call?>"><i class="fa-regular fa-eye"></i></button>
                                    <button class="action-icon-btn" title="Edit" onclick="openEditRoomModal(
                                        <?=$rm['room_id']?>,
                                        '<?=htmlspecialchars($rm['room_name'],ENT_QUOTES)?>',
                                        <?=$rm['price']?>,
                                        <?=$rm['total_units']?>,
                                        '<?=htmlspecialchars($rm['description'] ?? '', ENT_QUOTES)?>',
                                        <?=$imgUrl ? "'".htmlspecialchars($imgUrl,ENT_QUOTES)."'" : 'null'?>,
                                        <?=(int)($rm['capacity'] ?? 4)?>,
                                        '<?=htmlspecialchars($rm['badge'] ?? 'Available', ENT_QUOTES)?>'
                                    )"><i class="fa-solid fa-pen"></i></button>
                                    <div class="action-menu" id="ram-<?=$rm['room_id']?>">
                                        <button class="action-icon-btn" onclick="toggleMenu(<?=$rm['room_id']?>,event,'ram-')">
                                            <i class="fa-solid fa-ellipsis-vertical"></i>
                                        </button>
                                        <div class="action-dropdown">
                                            <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Delete this room? Existing bookings for it will remain in history but no new bookings can be made for it.')" style="margin:0;">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="delete_room" value="<?=$rm['room_id']?>">
                                                <button type="submit" class="ad-item" style="width:100%;border:none;background:none;text-align:left;font:inherit;cursor:pointer;color:#dc2626;">
                                                    <i class="fa-solid fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="rooms-pagination">
                <p id="roomsCountLabel">Showing 1 to <?=min(6,count($rooms_list))?> of <?=count($rooms_list)?> rooms</p>
                <div class="rooms-page-btns" id="roomsPagination"></div>
            </div>
        </div>
    </section>

    <!-- NOTIFICATIONS -->
    <section class="dash-section" id="section-notifications">
        <?php if($unread_count>0): ?>
        <div class="section-header section-header--aux-only">
            <button class="qa-action-btn qa-blue" onclick="markAllRead()"><i class="fa-solid fa-check-double"></i> Mark all read</button>
        </div>
        <?php endif; ?>

        <div class="notif-tabs">
            <button class="notif-tab active" id="ntab-all" onclick="filterNotifTab('all')">All <span class="notif-tab-count"><?=count($notifications)?></span></button>
            <button class="notif-tab" id="ntab-unread" onclick="filterNotifTab('unread')">Unread <span class="notif-tab-count"><?=$unread_count?></span></button>
        </div>

        <div class="notif-page-list" id="notifPageList">
            <?php if(empty($notifications)): ?>
            <div class="notif-empty" style="padding:60px 20px;"><i class="fa-regular fa-bell-slash"></i><p>No notifications yet</p></div>
            <?php else: foreach($notifications as $n):
                $ago       = human_time_diff(strtotime($n['notif_at']));
                $is_unread = notif_is_unread($n, $notif_last_read);
                $icon      = $n['status']==='confirmed'?'fa-circle-check':($n['status']==='cancelled'?'fa-ban':'fa-clock');
                $icon_cls  = $n['status']==='confirmed'?'ni--green':($n['status']==='cancelled'?'ni--red':'ni--gold');
                $border    = $n['status']==='confirmed'?'#2e7d32':($n['status']==='cancelled'?'#c62828':'#e65100');
            ?>
            <div class="notif-page-item <?=$is_unread?'notif-page-item--unread':''?>" data-unread="<?=$is_unread?'1':'0'?>"
                 style="border-left-color:<?=$border?>;" onclick="markNotificationRead(<?=$n['booking_id']?>); goToBooking(<?=$n['booking_id']?>)">
                <div class="ni-icon <?=$icon_cls?>"><i class="fa-solid <?=$icon?>"></i></div>
                <div class="ni-body">
                    <div class="ni-title">
                        <?php if($n['status']==='pending'): ?>
                            <strong><?=htmlspecialchars($n['full_name'])?></strong> made a new booking
                        <?php elseif($n['status']==='confirmed'): ?>
                            Booking confirmed for <strong><?=htmlspecialchars($n['full_name'])?></strong>
                        <?php else: ?>
                            Booking cancelled — <strong><?=htmlspecialchars($n['full_name'])?></strong>
                        <?php endif; ?>
                    </div>
                    <div class="ni-meta">
                        <span class="ni-room"><i class="fa-solid fa-bed"></i> <?=htmlspecialchars($n['room_type'])?></span>
                        <span class="ni-sep">·</span>
                        <span><?=date('M j',strtotime($n['check_in']))?> - <?=date('M j',strtotime($n['check_out']))?></span>
                    </div>
                    <div class="ni-time" data-notif-time="<?=htmlspecialchars($n['notif_at'], ENT_QUOTES, 'UTF-8')?>"><?=$ago?></div>
                </div>
                <?php if($is_unread): ?><div class="ni-dot"></div><?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </section>

    <!-- SETTINGS -->
    <section class="dash-section" id="section-settings">
        <div class="settings-tabs" role="tablist" aria-label="Settings categories">
            <button class="settings-tab active" type="button" data-tab="profile" onclick="openSettingsTab('profile',this)"><i class="fa-solid fa-user"></i><span>Profile</span></button>
            <button class="settings-tab" type="button" data-tab="security" onclick="openSettingsTab('security',this)"><i class="fa-solid fa-lock"></i><span>Security</span></button>
            <button class="settings-tab" type="button" data-tab="email" onclick="openSettingsTab('email',this)"><i class="fa-solid fa-envelope"></i><span>Email</span></button>
            <button class="settings-tab" type="button" data-tab="notifications" onclick="openSettingsTab('notifications',this)"><i class="fa-solid fa-bell"></i><span>Notifications</span></button>
            <button class="settings-tab" type="button" data-tab="system" onclick="openSettingsTab('system',this)"><i class="fa-solid fa-microchip"></i><span>System</span></button>
        </div>

        <!-- PROFILE -->
        <div class="settings-tab-panel active" id="settings-tab-profile">
            <div class="settings-grid settings-grid--profile">
                <div class="table-card settings-card">
                    <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--gold"><i class="fa-solid fa-id-badge"></i></div><div><h3>Profile Information</h3><p>Your name, username, and contact email.</p></div></div></div>
                    <form method="POST" action="admin_dashboard.php" class="settings-form settings-modern-form">
                        <?= csrfField() ?><input type="hidden" name="action" value="update_profile">
                        <div class="profile-avatar-row"><div class="profile-avatar-circle"><?=strtoupper(substr($current_admin['full_name'] ?? 'A',0,1))?></div><div><div class="profile-avatar-name"><?=htmlspecialchars($current_admin['full_name'] ?? '')?></div><div class="profile-avatar-role">Administrator</div></div></div>
                        <div class="room-field"><div class="room-field-lbl">Full Name</div><input type="text" name="full_name" maxlength="50" minlength="2" value="<?=htmlspecialchars($current_admin['full_name'] ?? '')?>" required></div>
                        <div class="room-fields-grid cols-2"><div class="room-field"><div class="room-field-lbl">Username</div><input type="text" name="username" minlength="3" maxlength="30" pattern="[A-Za-z0-9._]+" value="<?=htmlspecialchars($current_admin['username'] ?? '')?>" required></div><div class="room-field"><div class="room-field-lbl">Email Address</div><input type="email" name="email" maxlength="100" value="<?=htmlspecialchars($current_admin['email'] ?? '')?>" required></div></div>
                        <button type="submit" class="settings-primary-btn"><i class="fa-solid fa-check"></i> Save Profile</button>
                    </form>
                </div>
                <div class="table-card settings-card">
                    <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--navy"><i class="fa-solid fa-circle-info"></i></div><div><h3>Account Overview</h3><p>Current account status and activity.</p></div></div></div>
                    <div class="overview-list settings-overview"><div class="overview-row"><span>Account Status</span><span class="status-badge status--confirmed">Active</span></div><div class="overview-row"><span>Last Login</span><span><?= $last_login ? date('M d, Y g:i A', strtotime($last_login)) : '—' ?></span></div><div class="overview-row"><span>Total Logins</span><span><?=$total_logins?></span></div><div class="overview-row"><span>Trusted Devices</span><span><?=count($trusted_devices)?></span></div></div>
                </div>
            </div>
        </div>

        <!-- SECURITY -->
        <div class="settings-tab-panel" id="settings-tab-security">
            <div class="settings-grid">
                <div class="table-card settings-card">
                    <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--gold"><i class="fa-solid fa-key"></i></div><div><h3>Change Password</h3><p>Use a strong password you don't use anywhere else.</p></div></div></div>
                    <form method="POST" action="admin_dashboard.php" class="settings-form settings-modern-form" id="changePasswordForm" novalidate onsubmit="return validatePasswordForm(event)">
                        <?= csrfField() ?><input type="hidden" name="action" value="change_password">
                        <div class="room-field" id="field-current_password"><div class="room-field-lbl">Current Password</div><input type="password" name="current_password" id="currentPasswordInput" autocomplete="current-password"></div>
                        <span class="field-error-msg" id="err-current_password"><i class="fa-solid fa-circle-exclamation"></i><span class="fem-text"></span></span>
                        <div class="room-field" id="field-new_password"><div class="room-field-lbl">New Password</div><input type="password" name="new_password" id="newPasswordInput" autocomplete="new-password"></div>
                        <span class="field-error-msg" id="err-new_password"><i class="fa-solid fa-circle-exclamation"></i><span class="fem-text"></span></span>
                        <div class="room-field" id="field-confirm_password"><div class="room-field-lbl">Confirm New Password</div><input type="password" name="confirm_password" id="confirmPasswordInput" autocomplete="new-password"></div>
                        <span class="field-error-msg" id="err-confirm_password"><i class="fa-solid fa-circle-exclamation"></i><span class="fem-text"></span></span>
                        <ul class="pw-checklist" id="pwChecklist">
                            <li id="pwc-length"><i class="fa-solid fa-circle"></i> At least 8 characters long</li>
                            <li id="pwc-different"><i class="fa-solid fa-circle"></i> Different from your current password</li>
                        </ul>
                        <button type="submit" class="settings-primary-btn"><i class="fa-solid fa-key"></i> Update Password</button>
                    </form>
                </div>
                <div class="table-card settings-card">
                    <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--gold"><i class="fa-solid fa-shield-halved"></i></div><div><h3>Two-Factor Authentication</h3><p>Add an OTP verification step after your password.</p></div></div></div>
                    <form method="POST" action="admin_dashboard.php" class="settings-form settings-modern-form">
                        <input type="hidden" name="action" value="toggle_2fa"><?= csrfField() ?>
                        <div class="twofa-status <?= !empty($current_admin['two_factor_enabled']) ? 'twofa-status--on' : 'twofa-status--off' ?>">
                            <div><strong><i class="fa-solid <?= !empty($current_admin['two_factor_enabled']) ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i> <?= !empty($current_admin['two_factor_enabled']) ? '2FA Enabled' : '2FA Disabled' ?></strong><small><?= !empty($current_admin['two_factor_enabled']) ? 'OTP is required on untrusted devices.' : 'Your account only uses password authentication.' ?></small></div>
                            <label class="settings-switch"><input type="checkbox" name="two_factor_enabled" value="1" <?= !empty($current_admin['two_factor_enabled']) ? 'checked' : '' ?> onchange="this.form.querySelector('.twofa-save').style.display='inline-flex'"><span class="settings-switch-track"></span></label>
                        </div>
                        <div class="otp-delivery-locked"><div><span class="room-field-lbl">OTP Delivery</span><strong><?=htmlspecialchars($current_admin['email'] ?? '')?></strong><small>OTP codes are always sent to your verified administrator email. Change your account email in Profile to update this address.</small></div><i class="fa-solid fa-lock"></i></div>
                        <button type="submit" class="settings-primary-btn twofa-save"><i class="fa-solid fa-floppy-disk"></i> Save 2FA Settings</button>
                    </form>
                </div>
                <div class="table-card settings-card settings-card--wide">
                    <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--navy"><i class="fa-solid fa-mobile-screen-button"></i></div><div><h3>Trusted Devices</h3><p>Devices that skip OTP verification on login.</p></div></div><?php if (!empty($trusted_devices)): ?><form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Remove all trusted devices? Every device will need an OTP code on its next login.')"><input type="hidden" name="revoke_all_devices" value="1"><?= csrfField() ?><button type="submit" class="settings-danger-btn"><i class="fa-solid fa-trash"></i> Remove All</button></form><?php endif; ?></div>
                    <div class="table-wrap"><table><thead><tr><th>#</th><th>Status</th><th>Expires</th><th></th></tr></thead><tbody><?php if (empty($trusted_devices)): ?><tr><td colspan="4" class="empty-cell">No trusted devices - every login currently requires an OTP code.</td></tr><?php else: foreach ($trusted_devices as $i => $d): $expired = strtotime($d['expires_at']) < time(); ?><tr><td class="row-num"><?=$i+1?></td><td><?php if ($expired): ?><span class="status-badge status--cancelled">Expired</span><?php else: ?><span class="status-badge status--confirmed">Trusted</span><?php endif; ?></td><td class="text-muted"><?=date('M d, Y g:i A', strtotime($d['expires_at']))?></td><td><form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Remove this trusted device?')"><input type="hidden" name="revoke_device" value="<?=$d['id']?>"><?= csrfField() ?><button type="submit" class="settings-danger-btn settings-danger-btn--small"><i class="fa-solid fa-xmark"></i> Remove</button></form></td></tr><?php endforeach; endif; ?></tbody></table></div>
                </div>
                <div class="table-card settings-card settings-card--wide">
                    <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--navy"><i class="fa-solid fa-shield-halved"></i></div><div><h3>Login Activity</h3><p>Recent administrator sign-ins, devices, IP addresses, and login methods.</p></div></div></div>

                    <div class="table-card-head">
                        <div><h3>Login History</h3><p><?=$total_logins?> record<?=$total_logins!=1?'s':''?></p></div>
                        <div class="table-controls">
                            <div class="search-box">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <input type="text" placeholder="Search username or IP..." id="activitySearch" oninput="filterActivity()">
                            </div>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table id="activityTable">
                            <thead>
                                <tr><th>#</th><th>Username</th><th>Method</th><th>IP Address</th><th>Device</th><th>Date &amp; Time</th></tr>
                            </thead>
                            <tbody>
                                <?php if(empty($login_history)): ?>
                                <tr><td colspan="6" class="empty-cell">No login activity recorded yet.</td></tr>
                                <?php else: foreach($login_history as $i => $l): ?>
                                <tr class="act-row"
                                    data-username="<?=strtolower(htmlspecialchars($l['username']))?>"
                                    data-ip="<?=strtolower(htmlspecialchars($l['ip_address']))?>">
                                    <td class="row-num"><?=$i+1?></td>
                                    <td>
                                        <div class="guest-cell">
                                            <div class="guest-avatar"><?=strtoupper(substr($l['username'],0,2))?></div>
                                            <?=htmlspecialchars($l['username'])?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($l['login_method']==='remembered'): ?>
                                            <span class="status-badge status--confirmed">Remembered device</span>
                                        <?php else: ?>
                                            <span class="status-badge status--pending">OTP verified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted"><?=htmlspecialchars($l['ip_address'])?></td>
                                    <td class="text-muted"><?=htmlspecialchars(parseUserAgent($l['user_agent']))?></td>
                                    <td class="text-muted"><?=date('M d, Y g:i A',strtotime($l['logged_in_at']))?> <span style="color:#ccc;">· <?=human_time_diff(strtotime($l['logged_in_at']))?></span></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                        <div class="empty-state" id="noActivity" style="display:none;">
                            <i class="fa-regular fa-face-frown"></i>
                            <p>No login activity matches your search.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- EMAIL -->
        <div class="settings-tab-panel" id="settings-tab-email">
            <form method="POST" action="admin_dashboard.php" class="settings-grid">
                <?= csrfField() ?><input type="hidden" name="action" value="update_email_settings">
                <div class="table-card settings-card settings-card--wide">
                    <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--gold"><i class="fa-solid fa-envelope"></i></div><div><h3>Email Preferences</h3><p>Control which resort events should send email notifications.</p></div></div></div>
                    <div class="settings-toggle-list">
                        <div class="settings-toggle-row"><div class="setting-row-icon"><i class="fa-solid fa-calendar-check"></i></div><div><div class="settings-toggle-title">Booking Confirmation Emails</div><div class="settings-toggle-sub">Send guests an email when their reservation is confirmed.</div></div><label class="settings-switch"><input type="checkbox" name="confirmation_emails" <?=!empty($email_settings['confirmation_emails']) ? 'checked' : ''?>><span class="settings-switch-track"></span></label></div>
                        <div class="settings-toggle-row"><div class="setting-row-icon"><i class="fa-solid fa-ban"></i></div><div><div class="settings-toggle-title">Cancellation Emails</div><div class="settings-toggle-sub">Notify guests when a reservation is cancelled.</div></div><label class="settings-switch"><input type="checkbox" name="cancellation_emails" <?=!empty($email_settings['cancellation_emails']) ? 'checked' : ''?>><span class="settings-switch-track"></span></label></div>
                        <div class="settings-toggle-row"><div class="setting-row-icon"><i class="fa-solid fa-bell"></i></div><div><div class="settings-toggle-title">Admin Booking Alerts</div><div class="settings-toggle-sub">Receive an alert when a new booking request arrives. (Not yet sent — the guest-facing booking form isn't wired to this setting.)</div></div><label class="settings-switch"><input type="checkbox" name="admin_alerts" <?=!empty($email_settings['admin_alerts']) ? 'checked' : ''?>><span class="settings-switch-track"></span></label></div>
                    </div>
                </div>
                <div class="table-card settings-card">
                    <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--navy"><i class="fa-solid fa-at"></i></div><div><h3>Sender Information</h3><p>Name and address displayed on outgoing messages.</p></div></div></div>
                    <div class="room-field"><div class="room-field-lbl">Sender Name</div><input type="text" name="sender_name" maxlength="100" value="<?=htmlspecialchars($email_settings['sender_name'] ?? '')?>" required></div>
                    <div class="room-field"><div class="room-field-lbl">Sender Email</div><input type="email" name="sender_email" maxlength="150" value="<?=htmlspecialchars($email_settings['sender_email'] ?? '')?>" required></div>
                    <p class="settings-toggle-sub" style="margin-top:8px;">Note: this is a record of the sender identity shown here — the actual SMTP account used to send mail is set separately in <code>config/mailer.php</code>.</p>
                </div>
                <div class="settings-card--wide">
                    <button type="submit" class="settings-primary-btn"><i class="fa-solid fa-check"></i> Save Email Preferences</button>
                </div>
            </form>
        </div>


        <!-- NOTIFICATIONS -->
        <div class="settings-tab-panel" id="settings-tab-notifications">
            <div class="table-card settings-card settings-card--wide">
                <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--gold"><i class="fa-solid fa-bell"></i></div><div><h3>Notification Preferences</h3><p>Choose the events you want to see in the admin dashboard.</p></div></div></div>
                <div class="settings-toggle-list settings-toggle-list--two">
                    <div class="settings-toggle-row"><div class="setting-row-icon"><i class="fa-solid fa-clock"></i></div><div><div class="settings-toggle-title">Pending Bookings</div><div class="settings-toggle-sub">Show alerts for bookings awaiting confirmation.</div></div><label class="settings-switch"><input type="checkbox" checked><span class="settings-switch-track"></span></label></div>
                    <div class="settings-toggle-row"><div class="setting-row-icon"><i class="fa-solid fa-circle-check"></i></div><div><div class="settings-toggle-title">Confirmed Bookings</div><div class="settings-toggle-sub">Show a notification after a booking is confirmed.</div></div><label class="settings-switch"><input type="checkbox" checked><span class="settings-switch-track"></span></label></div>
                    <div class="settings-toggle-row"><div class="setting-row-icon"><i class="fa-solid fa-ban"></i></div><div><div class="settings-toggle-title">Cancelled Bookings</div><div class="settings-toggle-sub">Show a notification whenever a reservation is cancelled.</div></div><label class="settings-switch"><input type="checkbox" checked><span class="settings-switch-track"></span></label></div>
                    <div class="settings-toggle-row"><div class="setting-row-icon"><i class="fa-solid fa-shield-halved"></i></div><div><div class="settings-toggle-title">Security Activity</div><div class="settings-toggle-sub">Alert when a new login or remembered device is used.</div></div><label class="settings-switch"><input type="checkbox" checked><span class="settings-switch-track"></span></label></div>
                </div>
            </div>
        </div>

        <!-- RESERVATION -->
        <!-- SYSTEM -->
        <div class="settings-tab-panel" id="settings-tab-system">
            <div class="settings-grid">
                <div class="table-card settings-card settings-card--wide">
                    <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--navy"><i class="fa-solid fa-server"></i></div><div><h3>System Information</h3><p>Current runtime and database information.</p></div></div></div>
                    <div class="system-grid-detail"><div><span>PHP Version</span><strong><?=htmlspecialchars(PHP_VERSION)?></strong></div><div><span>Database</span><strong><?=htmlspecialchars($conn->server_info ?? 'MySQL')?></strong></div><div><span>Server Time</span><strong><?=date('M d, Y h:i A')?></strong></div><div><span>System Status</span><strong class="online-pill">Online</strong></div></div>
                </div>
                <div class="table-card settings-card">
                    <div class="settings-card-head"><div class="settings-title-wrap"><div class="settings-icon settings-icon--gold"><i class="fa-solid fa-database"></i></div><div><h3>Backup &amp; Maintenance</h3><p>Keep a safe copy of your resort data.</p></div></div></div>
                    <div class="maintenance-box"><div class="maintenance-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div><div><strong>Database Backup</strong><small>Manual backup controls can be connected to your server backup routine.</small></div></div><button type="button" class="backup-btn backup-btn--full"><i class="fa-solid fa-download"></i> Backup Now</button>
                </div>
            </div>
        </div>

        <div class="settings-footer-note"><i class="fa-solid fa-shield-halved"></i> Settings are protected by your administrator account and CSRF verification.</div>
    </section>

</div><!-- /main-wrap -->

<!-- BOOKING INFO MODAL -->
<div class="bmodal-overlay bmodal-panel-overlay" id="bookingModal" onclick="closeBookingModal()">
    <div class="bmodal-box bmodal-panel" onclick="event.stopPropagation()">
        <div class="bmodal-panel-top">
            <span class="bmodal-panel-title">Booking Details</span>
            <button class="bmodal-close" onclick="closeBookingModal()">✕</button>
        </div>
        <span id="bm-title" style="position:absolute;width:1px;height:1px;overflow:hidden;" aria-live="polite"></span>
        <span id="bm-nights" style="position:absolute;width:1px;height:1px;overflow:hidden;"></span>

        <div class="bmodal-panel-body">
            <div class="bmodal-panel-statusrow">
                <span class="bmodal-side-status" id="bm-status-badge">Confirmed</span>
                <span class="bmodal-panel-ref">Booking Ref: <b id="bm-ref"></b></span>
            </div>

            <div class="bmodal-panel-identity">
                <div class="bmodal-panel-avatar" id="bm-avatar" onclick="bmOpenIdPhoto()">
                    <img id="bm-avatar-img" class="bmodal-avatar-img" style="display:none;" alt="">
                    <span id="bm-avatar-letter"></span>
                </div>
                <div>
                    <div class="bmodal-panel-name" id="bm-name"></div>
                    <div class="bmodal-panel-contact"><i class="fa-regular fa-envelope"></i> <span id="bm-email"></span></div>
                    <div class="bmodal-panel-contact"><i class="fa-solid fa-phone"></i> <span id="bm-contact"></span></div>
                    <div class="bmodal-panel-contact bmodal-panel-id" id="bm-id-row" onclick="bmOpenIdPhoto()">
                        <i class="fa-solid fa-id-card"></i> ID: <span id="bm-idtype"></span>
                        <span class="bmodal-id-verified" id="bm-id-verified" style="display:none;">(Verified)</span>
                    </div>
                </div>
            </div>

            <div class="bmodal-section-lbl"><span class="bmodal-section-icon"><i class="fa-solid fa-clipboard-list"></i></span> Booking Information</div>
            <div class="bmodal-glist">
                <div class="bmodal-grow">
                    <span class="bmodal-grow-lbl">Room</span>
                    <span class="bmodal-grow-val" id="bm-room"></span>
                </div>
                <div class="bmodal-grow">
                    <span class="bmodal-grow-lbl">Check-in</span>
                    <span class="bmodal-grow-val" id="bm-checkin"></span>
                </div>
                <div class="bmodal-grow">
                    <span class="bmodal-grow-lbl">Check-out</span>
                    <span class="bmodal-grow-val" id="bm-checkout"></span>
                </div>
                <div class="bmodal-grow">
                    <span class="bmodal-grow-lbl">Guests</span>
                    <span class="bmodal-grow-val" id="bm-guests"></span>
                </div>
                <div class="bmodal-grow">
                    <span class="bmodal-grow-lbl">Total Price</span>
                    <span class="bmodal-grow-val" id="bm-price"></span>
                </div>
                <div class="bmodal-grow">
                    <span class="bmodal-grow-lbl">Payment Method</span>
                    <span class="bmodal-grow-val" id="bm-paymethod"></span>
                </div>
                <div class="bmodal-grow" id="bm-payref-field" style="display:none;">
                    <span class="bmodal-grow-lbl">Payment Reference</span>
                    <span class="bmodal-grow-val" id="bm-payref"></span>
                </div>
                <div class="bmodal-grow">
                    <span class="bmodal-grow-lbl">Payment Status</span>
                    <span class="bmodal-grow-val" id="bm-paystatus"></span>
                </div>
                <div class="bmodal-grow">
                    <span class="bmodal-grow-lbl">Booked On</span>
                    <span class="bmodal-grow-val"><span id="bm-booked-date"></span> · <span id="bm-booked-time"></span></span>
                </div>
            </div>
            <div class="bmodal-section-lbl"><span class="bmodal-section-icon"><i class="fa-solid fa-receipt"></i></span> Payment Receipt</div>
            <div class="bmodal-receipt-card" id="bm-receipt-card" style="display:none;">
                <div class="bmodal-receipt-thumb" onclick="openIdPhotoLightbox(document.getElementById('bm-receipt').src)">
                    <img id="bm-receipt" src="">
                </div>
                <div class="bmodal-receipt-info">
                    <div class="bmodal-receipt-name" id="bm-receipt-name"></div>
                    <div class="bmodal-receipt-date">Uploaded on <span id="bm-receipt-date"></span></div>
                </div>
                <a href="#" id="bm-receipt-download" class="bmodal-receipt-dl" download title="Download receipt"><i class="fa-solid fa-download"></i></a>
            </div>
            <div class="bmodal-receipt-empty" id="bm-receipt-empty2">No payment receipt uploaded.</div>

            <div class="bmodal-idphoto-hidden">
                <div id="bm-idphoto-wrap" style="display:none;" onclick="openIdPhotoLightbox(document.getElementById('bm-idphoto').src)">
                    <img id="bm-idphoto" src="">
                </div>
                <span id="bm-idphoto-type"></span>
                <span id="bm-idphoto-empty" style="display:none;"></span>
                <span id="bm-receipt-type"></span>
            </div>
        </div>

        <div class="bmodal-footer bmodal-panel-footer">
            <a href="#" class="bmodal-panel-btn bmodal-panel-btn--message" id="bm-message-btn" target="_blank" rel="noopener"><i class="fa-regular fa-paper-plane"></i> Send Message</a>
            <div class="bmodal-panel-btn-row">
                <form method="POST" action="admin_dashboard.php" id="bm-confirm-form" onsubmit="return confirm('Confirm this booking?')" style="display:none;">
                    <?= csrfField() ?>
                    <input type="hidden" name="confirm_booking" id="bm-confirm-id" value="">
                    <button type="submit" class="bmodal-panel-btn bmodal-panel-btn--confirm"><i class="fa-solid fa-circle-check"></i> Confirm Booking</button>
                </form>
                <form method="POST" action="admin_dashboard.php" id="bm-cancel-form" onsubmit="return confirm('Cancel this booking? This cannot be undone.')" style="display:none;">
                    <?= csrfField() ?>
                    <input type="hidden" name="cancel_booking" id="bm-cancel-id" value="">
                    <button type="submit" class="bmodal-panel-btn bmodal-panel-btn--cancel"><i class="fa-solid fa-ban"></i> Cancel Booking</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ID PHOTO LIGHTBOX -->
<div id="idPhotoLightbox" onclick="closeIdPhotoLightbox()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:100000;align-items:center;justify-content:center;padding:24px;">
    <button onclick="closeIdPhotoLightbox()" style="position:absolute;top:20px;right:24px;width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.12);border:none;color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;">✕</button>
    <img id="idPhotoLightboxImg" src="" style="max-width:90vw;max-height:90vh;border-radius:10px;object-fit:contain;" onclick="event.stopPropagation()">
</div>

<!-- TODAY'S GUESTS MODAL -->
<div class="bmodal-overlay" id="todayGuestsModal" onclick="closeTodayGuestsModal()">
    <div class="bmodal-box" style="max-width:400px;" onclick="event.stopPropagation()">
        <div class="bmodal-top">
            <div class="bmodal-top-row">
                <span class="bmodal-label" id="tg-modal-label">Confirmed / requested today</span>
                <button class="bmodal-close" onclick="closeTodayGuestsModal()">✕</button>
            </div>
            <div class="bmodal-identity">
                <div class="bmodal-icon"><i class="fa-solid fa-bed"></i></div>
                <div>
                    <div class="bmodal-title" id="tg-room-name">Room</div>
                    <div class="bmodal-guest"><?=date('M j, Y')?></div>
                </div>
            </div>
        </div>
        <div class="bmodal-body">
            <div id="tg-guest-list" style="display:flex;flex-direction:column;gap:8px;"></div>
            <div id="tg-empty" style="display:none;padding:24px 0;text-align:center;color:#bbb;font-size:13px;">
                <i class="fa-regular fa-calendar-xmark" style="font-size:22px;display:block;margin-bottom:8px;"></i>
                No guests booked for today.
            </div>
        </div>
        <div class="bmodal-footer">
            <button class="bmodal-close-btn" onclick="closeTodayGuestsModal()">Close</button>
        </div>
    </div>
</div>

<!-- VIEW ROOM MODAL -->
<div class="bmodal-overlay bmodal-panel-overlay" id="viewRoomModal" onclick="closeViewRoomModal()">
    <div class="bmodal-box bmodal-panel" onclick="event.stopPropagation()">
        <div class="bmodal-panel-top">
            <span class="bmodal-panel-title">Room Details</span>
            <button class="bmodal-close" onclick="closeViewRoomModal()">✕</button>
        </div>
        <div class="bmodal-panel-body room-details-body">
            <div class="bmodal-panel-identity room-summary-identity">
                <img id="vr-image" src="" alt="" class="room-summary-image" style="display:none;">
                <div class="bmodal-panel-avatar room-summary-icon" id="vr-icon"><i class="fa-solid fa-bed"></i></div>
                <div class="room-summary-main">
                    <div class="bmodal-panel-name room-summary-name" id="vr-name">Room</div>
                    <div class="room-summary-meta">
                        <span><i class="fa-solid fa-user-group"></i> <span id="vr-capacity">—</span></span>
                        <span><i class="fa-solid fa-layer-group"></i> <span id="vr-units">—</span> Units</span>
                    </div>
                </div>
                <div class="room-summary-price">
                    <strong id="vr-price">₱0</strong>
                    <span>per night</span>
                </div>
            </div>

            <div class="room-summary-stats">
                <div class="room-summary-stat room-summary-stat--status">
                    <span class="room-summary-stat-value"><span class="bmodal-side-status" id="vr-status">Available</span></span>
                    <span class="room-summary-stat-label">STATUS</span>
                </div>
                <div class="room-summary-stat">
                    <span class="room-summary-stat-value" id="vr-availability">—</span>
                    <span class="room-summary-stat-label">AVAILABILITY</span>
                </div>
                <div class="room-summary-stat room-summary-stat--description">
                    <span class="room-summary-stat-value" id="vr-desc-summary">—</span>
                    <span class="room-summary-stat-label">DESCRIPTION</span>
                </div>
            </div>

            <div class="bmodal-section-lbl" id="vr-desc-section" style="display:none;"><span class="bmodal-section-icon"><i class="fa-solid fa-quote-left"></i></span> Description</div>
            <div class="bmodal-requests" id="vr-desc" style="display:none;"></div>

            <div class="bmodal-section-lbl"><span class="bmodal-section-icon"><i class="fa-solid fa-user-check"></i></span> Confirmed Guests <span id="vr-confirmed-count" class="room-details-count">0</span></div>
            <div id="vr-confirmed-list" class="room-details-guests"></div>
            <div id="vr-confirmed-empty" class="bmodal-receipt-empty room-details-empty" style="display:none;">No confirmed guests for this room.</div>
        </div>
    </div>
</div>

<!-- ADD ROOM MODAL -->
<div class="room-modal-overlay" id="addRoomModal" onclick="if(event.target===this)closeAddRoomModal()">
  <div class="room-modal-box" onclick="event.stopPropagation()">
    <div class="room-modal-top">
      <div class="room-modal-top-row">
        <div>
          <div class="room-modal-title">Add New Room</div>
          <div class="room-modal-sub">Fill in the details below to add a new room.</div>
        </div>
        <button type="button" class="room-modal-close" onclick="closeAddRoomModal()"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>

    <form method="POST" action="admin_dashboard.php" enctype="multipart/form-data" id="addRoomForm">
      <input type="hidden" name="action" value="add_room">
      <?= csrfField() ?>
      <div class="room-modal-body room-modal-body--modern">

        <div class="room-field room-field--featured">
          <div class="room-field-lbl">Room Name / Number <span class="room-req">*</span></div>
          <input type="text" name="room_name" required placeholder="e.g. Deluxe Room 103">
        </div>

        <div class="room-fields-grid cols-desc-photo">
          <div class="room-field" style="position:relative;">
            <div class="room-field-lbl">Description</div>
            <textarea name="description" id="add-desc" maxlength="255" rows="4" placeholder="Describe the room, amenities, and other details..." oninput="roomCountChars('add-desc','add-desc-count')"></textarea>
            <div class="room-char-count" id="add-desc-count">0 / 255</div>
          </div>
          <div>
            <div class="room-field-lbl" style="margin-bottom:6px;">Room Image</div>
            <div class="room-photo-zone" id="addPhotoDropZone" style="position:relative;" onclick="document.getElementById('addRoomFileInput').click()">
              <img id="addPhotoPreviewImg" alt="" style="display:none;position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:5px;">
              <div id="addPhotoPlaceholder">
                <div class="rpz-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                <div class="rpz-title">Click to upload image</div>
                <div class="rpz-sub">JPG, PNG up to 5MB</div>
              </div>
              <button type="button" class="rpt-remove" id="addPhotoRemoveBtn" style="display:none;position:absolute;top:6px;right:6px;" onclick="clearPhotoSelection(event,'add')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="room-photo-change-link" id="add-photo-hint" style="display:none;" onclick="document.getElementById('addRoomFileInput').click()">Change image</div>
            <input type="file" id="addRoomFileInput" name="images[]" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="handlePhotoSelect(this,'add')">
          </div>
        </div>

        <div class="room-fields-grid cols-3">
          <div class="room-field">
            <div class="room-field-lbl">Capacity (Guests) <span class="room-req">*</span></div>
            <input type="number" name="capacity" min="1" required placeholder="e.g. 2">
          </div>
          <div class="room-field">
            <div class="room-field-lbl">Price per Night (₱) <span class="room-req">*</span></div>
            <input type="number" name="price" step="0.01" min="1" required placeholder="e.g. 4000">
          </div>
          <div class="room-field">
            <div class="room-field-lbl">Number of Rooms / Units <span class="room-req">*</span></div>
            <input type="number" name="total_units" min="1" required placeholder="e.g. 5">
          </div>
        </div>

        <div class="room-field">
          <div class="room-field-lbl">Room Status <span class="room-req">*</span></div>
          <select name="badge" required>
            <?php foreach(ROOM_STATUS_OPTIONS as $st): ?>
            <option value="<?=htmlspecialchars($st,ENT_QUOTES)?>"><?=htmlspecialchars($st)?></option>
            <?php endforeach; ?>
          </select>
        </div>

      </div>
      <div class="room-modal-footer">
        <button type="button" class="room-btn room-btn-ghost" onclick="closeAddRoomModal()">Cancel</button>
        <button type="submit" class="room-btn room-btn-gold">Add Room</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT ROOM MODAL -->
<div class="room-modal-overlay" id="editRoomModal" onclick="if(event.target===this)closeEditRoomModal()">
  <div class="room-modal-box" onclick="event.stopPropagation()">
    <div class="room-modal-top">
      <div class="room-modal-top-row">
        <div>
          <div class="room-modal-title">Edit Room</div>
          <div class="room-modal-sub">Update the room information.</div>
        </div>
        <button type="button" class="room-modal-close" onclick="closeEditRoomModal()"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>

    <form method="POST" action="admin_dashboard.php" enctype="multipart/form-data" id="editRoomForm">
      <input type="hidden" name="action" value="update_room">
      <input type="hidden" name="room_id" id="er-room-id">
      <?= csrfField() ?>
      <div class="room-modal-body room-modal-body--modern">

        <div class="room-field room-field--featured">
          <div class="room-field-lbl">Room Name / Number <span class="room-req">*</span></div>
          <input type="text" name="room_name" id="er-room-name" required placeholder="e.g. Deluxe Room 103">
        </div>

        <div class="room-fields-grid cols-desc-photo">
          <div class="room-field" style="position:relative;">
            <div class="room-field-lbl">Description</div>
            <textarea name="description" id="er-description" maxlength="255" rows="4" placeholder="Describe the room, amenities, and other details..." oninput="roomCountChars('er-description','er-desc-count')"></textarea>
            <div class="room-char-count" id="er-desc-count">0 / 255</div>
          </div>
          <div>
            <div class="room-field-lbl" style="margin-bottom:6px;">Room Image</div>
            <div class="room-photo-zone" id="editPhotoDropZone" style="position:relative;" onclick="document.getElementById('editRoomFileInput').click()">
              <img id="er-photo-preview" alt="" style="display:none;position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:5px;">
              <div id="er-photo-placeholder">
                <div class="rpz-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                <div class="rpz-title">Click to upload image</div>
                <div class="rpz-sub">JPG, PNG up to 5MB</div>
              </div>
            </div>
            <div class="room-photo-change-link" id="er-photo-hint" style="display:none;" onclick="document.getElementById('editRoomFileInput').click()">Change image</div>
            <input type="file" id="editRoomFileInput" name="images[]" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="handlePhotoSelect(this,'edit')">
          </div>
        </div>

        <div class="room-fields-grid cols-3">
          <div class="room-field">
            <div class="room-field-lbl">Capacity (Guests) <span class="room-req">*</span></div>
            <input type="number" name="capacity" id="er-capacity" min="1" required>
          </div>
          <div class="room-field">
            <div class="room-field-lbl">Price per Night (₱) <span class="room-req">*</span></div>
            <input type="number" name="price" id="er-price" step="0.01" min="1" required>
          </div>
          <div class="room-field">
            <div class="room-field-lbl">Number of Rooms / Units <span class="room-req">*</span></div>
            <input type="number" name="total_units" id="er-units" min="1" required>
          </div>
        </div>

        <div class="room-field">
          <div class="room-field-lbl">Room Status <span class="room-req">*</span></div>
          <select name="badge" id="er-badge" required>
            <?php foreach(ROOM_STATUS_OPTIONS as $st): ?>
            <option value="<?=htmlspecialchars($st,ENT_QUOTES)?>"><?=htmlspecialchars($st)?></option>
            <?php endforeach; ?>
          </select>
        </div>

      </div>
      <div class="room-modal-footer">
        <button type="button" class="room-btn room-btn-ghost" onclick="closeEditRoomModal()">Cancel</button>
        <button type="submit" class="room-btn room-btn-gold">Update Room</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<?php if (!empty($_SESSION['show_password_otp_modal'])): unset($_SESSION['show_password_otp_modal']); ?>
<div class="security-otp-modal is-open" id="passwordOtpModal" role="dialog" aria-modal="true" aria-labelledby="passwordOtpTitle">
    <div class="security-otp-backdrop"></div>
    <div class="security-otp-dialog">
        <div class="security-otp-icon"><i class="fa-solid fa-shield-halved"></i></div>
        <h3 id="passwordOtpTitle">Verify Password Change</h3>
        <p>For your protection, enter the 6-digit verification code sent to your administrator email.</p>
        <form method="POST" action="admin_dashboard.php" class="security-otp-form" id="passwordOtpForm">
            <?= csrfField() ?><input type="hidden" name="action" value="verify_password_change_otp">
            <input type="text" name="password_change_otp" id="passwordChangeOtp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required>
            <div class="security-otp-actions">
                <button type="submit" class="settings-primary-btn"><i class="fa-solid fa-check"></i> Verify & Change Password</button>
            </div>
        </form>
        <small class="security-otp-note">The code expires in 10 minutes.</small>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($_SESSION['show_2fa_disable_otp_modal'])): unset($_SESSION['show_2fa_disable_otp_modal']); ?>
<div class="security-otp-modal is-open" id="twofaDisableOtpModal" role="dialog" aria-modal="true" aria-labelledby="twofaDisableOtpTitle">
    <div class="security-otp-backdrop"></div>
    <div class="security-otp-dialog">
        <div class="security-otp-icon"><i class="fa-solid fa-shield-halved"></i></div>
        <h3 id="twofaDisableOtpTitle">Confirm Disabling 2FA</h3>
        <p>For your protection, enter the 6-digit verification code sent to your administrator email to turn off two-factor authentication.</p>
        <form method="POST" action="admin_dashboard.php" class="security-otp-form" id="twofaDisableOtpForm">
            <?= csrfField() ?><input type="hidden" name="action" value="verify_2fa_disable_otp">
            <input type="text" name="twofa_disable_otp" id="twofaDisableOtp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required>
            <div class="security-otp-actions">
                <button type="submit" class="settings-primary-btn"><i class="fa-solid fa-check"></i> Verify & Disable 2FA</button>
            </div>
        </form>
        <small class="security-otp-note">The code expires in 10 minutes.</small>
    </div>
</div>
<?php endif; ?>
<script>
const topbarTitles = {
    overview: { title: 'Dashboard', sub: <?=json_encode("Welcome back, {$admin_name}. Here's what's happening today.")?> },
    bookings: { title: 'Bookings', sub: 'Manage all reservations and booking details.' },
    rooms: { title: 'Rooms', sub: 'Manage room types, pricing, and unit inventory' },
    settings: { title: 'Settings', sub: 'Manage your resort, account, reservations, payments, notifications, and system preferences.' },
    notifications: { title: 'Notifications', sub: 'Stay updated with new bookings, confirmations, and cancellations' }
};
function showSection(name,el){
    // Leaving the Notifications sidebar marks everything that was visible
    // on the Notifications page as read, matching normal inbox behavior.
    const currentSection = document.querySelector('.dash-section.active');
    if (currentSection && currentSection.id === 'section-notifications' && name !== 'notifications') {
        markAllRead();
    }

    document.querySelectorAll('.dash-section').forEach(s=>s.classList.remove('active'));
    document.querySelectorAll('.sb-item').forEach(n=>n.classList.remove('active'));
    document.getElementById('section-'+name).classList.add('active');
    if(el) el.classList.add('active');
    localStorage.setItem('adminActiveSection', name);

    const meta = topbarTitles[name];
    if(meta){
        document.getElementById('topbarTitleText').textContent = meta.title;
        document.getElementById('topbarTitleSub').textContent = meta.sub;
    }

    // Show Add New Room only on the Rooms page
    const addRoomBtn = document.getElementById('roomsAddBtn');
    if(addRoomBtn){
        addRoomBtn.style.display = (name === 'rooms') ? 'inline-flex' : 'none';
    }
}
function openSettingsTab(name, el){
    document.querySelectorAll('.settings-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.settings-tab-panel').forEach(p=>p.classList.remove('active'));
    const panel = document.getElementById('settings-tab-' + name);
    if (!panel) return;
    if (el) el.classList.add('active');
    panel.classList.add('active');
    localStorage.setItem('adminSettingsTab', name);
}
(function(){
    const savedTab = localStorage.getItem('adminSettingsTab');
    if (savedTab && document.getElementById('settings-tab-' + savedTab)) {
        const btn = document.querySelector('.settings-tab[data-tab="' + savedTab + '"]');
        openSettingsTab(savedTab, btn);
    }
})();
function filterNotifTab(mode){
    document.querySelectorAll('.notif-tab').forEach(t=>t.classList.remove('active'));
    document.getElementById('ntab-'+mode).classList.add('active');
    document.querySelectorAll('.notif-page-item').forEach(item=>{
        item.style.display = (mode==='unread' && item.dataset.unread!=='1') ? 'none' : 'flex';
    });
}
function updatePwChecklist(){
    const newPw = document.getElementById('newPasswordInput')?.value || '';
    const curPw = document.getElementById('currentPasswordInput')?.value || '';
    setChecklistItem('pwc-length', newPw.length >= 8);
    setChecklistItem('pwc-different', newPw.length > 0 && newPw !== curPw);
}
function setChecklistItem(id, met){
    const li = document.getElementById(id);
    if (!li) return;
    li.classList.toggle('met', met);
    const icon = li.querySelector('i');
    if (icon) icon.className = met ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle';
}
['currentPasswordInput','newPasswordInput'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', updatePwChecklist);
});
updatePwChecklist();
function clearFieldError(name){
    document.getElementById('field-'+name)?.classList.remove('room-field--error');
    document.getElementById('err-'+name)?.classList.remove('show');
}
function setFieldError(name, msg){
    document.getElementById('field-'+name)?.classList.add('room-field--error');
    const err = document.getElementById('err-'+name);
    if (err) { err.querySelector('.fem-text').textContent = msg; err.classList.add('show'); }
}
['current_password','new_password','confirm_password'].forEach(name => {
    document.getElementById(name==='current_password' ? 'currentPasswordInput' : (name==='new_password' ? 'newPasswordInput' : 'confirmPasswordInput'))
        ?.addEventListener('input', () => clearFieldError(name));
});
function validatePasswordForm(e){
    e.preventDefault();
    ['current_password','new_password','confirm_password'].forEach(clearFieldError);

    const current = document.getElementById('currentPasswordInput');
    const newPw   = document.getElementById('newPasswordInput');
    const confirm_ = document.getElementById('confirmPasswordInput');
    let firstInvalid = null;

    if (!current.value) {
        setFieldError('current_password', 'Please enter your current password.');
        firstInvalid = firstInvalid || current;
    }
    if (!newPw.value) {
        setFieldError('new_password', 'Please enter a new password.');
        firstInvalid = firstInvalid || newPw;
    } else if (newPw.value.length < 8) {
        setFieldError('new_password', 'Must be at least 8 characters long.');
        firstInvalid = firstInvalid || newPw;
    }
    if (!confirm_.value) {
        setFieldError('confirm_password', 'Please confirm your new password.');
        firstInvalid = firstInvalid || confirm_;
    } else if (newPw.value && confirm_.value !== newPw.value) {
        setFieldError('confirm_password', "Doesn't match the new password above.");
        firstInvalid = firstInvalid || confirm_;
    }

    if (firstInvalid) { firstInvalid.focus(); return false; }
    if (!confirm('Update your password now?')) return false;

    document.getElementById('changePasswordForm').submit();
    return false;
}
function toggleSidebar(){
    const sidebar = document.getElementById('sidebar');
    const isDesktop = window.matchMedia('(min-width: 993px)').matches;
    if(isDesktop){
        sidebar.classList.toggle('collapsed');
        document.querySelector('.main-wrap').classList.toggle('collapsed');
    } else {
        sidebar.classList.toggle('open');
        document.getElementById('sidebarBackdrop').classList.toggle('open');
        document.body.classList.toggle('sidebar-lock');
    }
    document.getElementById('adminHamburger').classList.toggle('open');
}

const bookingThisMonthData = <?=json_encode(array_values($booking_chart_this_month))?>;
const bookingLastMonthData = <?=json_encode(array_values($booking_chart_last_month))?>;
const bookingThisMonthLabel = <?=json_encode($booking_chart_this_month_label)?>;
const bookingLastMonthLabel = <?=json_encode($booking_chart_last_month_label)?>;
const bookingThisMonthDays = <?=json_encode($daysThisMonth)?>;
const bookingLastMonthDays = <?=json_encode($daysLastMonth)?>;

const bookingOverviewCanvas = document.getElementById('monthlyChart');
const bookingMonthSelect = document.getElementById('bookingMonthSelect');

function buildBookingLabels(days, prefix) {
    return Array.from({length: days}, (_, i) => `${prefix} ${i + 1}`);
}

if (bookingOverviewCanvas) {
    const ctx = bookingOverviewCanvas.getContext('2d');
    const thisMonthGradient = ctx.createLinearGradient(0, 0, 0, 320);
    thisMonthGradient.addColorStop(0, 'rgba(199, 139, 25, .12)');
    thisMonthGradient.addColorStop(1, 'rgba(199, 139, 25, 0)');

    const bookingChart = new Chart(bookingOverviewCanvas, {
        type: 'line',
        data: {
            labels: buildBookingLabels(bookingThisMonthDays, 'Aug'),
            datasets: [
                {
                    label: 'This Month',
                    data: bookingThisMonthData,
                    borderColor: '#c78b19',
                    backgroundColor: thisMonthGradient,
                    borderWidth: 2,
                    pointRadius: 1.8,
                    pointHoverRadius: 4,
                    pointBackgroundColor: '#c78b19',
                    pointBorderWidth: 0,
                    tension: 0.25,
                    fill: true
                },
                {
                    label: 'Last Month',
                    data: bookingLastMonthData,
                    borderColor: '#c5cad6',
                    backgroundColor: 'transparent',
                    borderWidth: 1.6,
                    pointRadius: 1.7,
                    pointHoverRadius: 4,
                    pointBackgroundColor: '#c5cad6',
                    pointBorderWidth: 0,
                    tension: 0.25,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#17203a',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 10,
                    cornerRadius: 7,
                    displayColors: true,
                    callbacks: {
                        label: function(context) {
                            return ` ${context.dataset.label}: ${context.parsed.y} bookings`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    suggestedMax: 100,
                    ticks: {
                        stepSize: 20,
                        color: '#536079',
                        font: { size: 12 }
                    },
                    grid: {
                        color: '#dfe3ea',
                        borderDash: [3, 4],
                        drawBorder: false
                    },
                    border: { display: false },
                    title: { display: false }
                },
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        color: '#536079',
                        font: { size: 11 },
                        maxRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 7,
                        callback: function(value) {
                            const label = this.getLabelForValue(value);
                            const day = parseInt(label.split(' ')[1], 10);
                            return [1, 6, 11, 16, 21, 26, 31].includes(day) ? label : '';
                        }
                    }
                }
            }
        }
    });

    function updateBookingChart(mode) {
        const useThis = mode === 'this';
        const days = useThis ? bookingThisMonthDays : bookingLastMonthDays;
        const selectedPrefix = useThis ? <?=json_encode(date('M'))?> : <?=json_encode(date('M', strtotime('first day of last month')))?>;
        const comparisonPrefix = useThis ? <?=json_encode(date('M', strtotime('first day of last month')))?> : <?=json_encode(date('M', strtotime('-2 months')))?>;

        bookingChart.data.labels = buildBookingLabels(days, selectedPrefix);
        bookingChart.data.datasets[0].data = useThis ? bookingThisMonthData : bookingLastMonthData;
        bookingChart.data.datasets[1].data = useThis ? bookingLastMonthData : bookingThisMonthData;
        bookingChart.data.datasets[0].label = useThis ? 'This Month' : 'Last Month';
        bookingChart.data.datasets[1].label = useThis ? 'Last Month' : 'This Month';
        document.getElementById('bookingThisLegend').textContent = useThis ? 'This Month' : 'Last Month';
        document.getElementById('bookingLastLegend').textContent = useThis ? 'Last Month' : 'This Month';
        bookingChart.update();
    }

    bookingMonthSelect?.addEventListener('change', function() {
        updateBookingChart(this.value);
    });
}

const occupancyCanvas = document.getElementById('occupancyChart');
if (occupancyCanvas) {
    new Chart(occupancyCanvas, {
        type:'doughnut',
        data:{
            labels:['Occupied','Available'],
            datasets:[{
                data:[<?=$rooms_occupied_today?>, <?=$rooms_available_today?>],
                backgroundColor:['#c8a96e','#1a1a2e'],
                borderWidth:0, hoverOffset:6
            }]
        },
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{enabled:true}},cutout:'72%'}
    });
}

function jumpToBookingRef(ref){
    showSection('bookings', document.querySelectorAll('.sb-item')[1]);
    filterByStatus('all');
    const box = document.getElementById('bookingSearch');
    box.value = ref;
    filterBookings();
}

const calBookings = <?=json_encode($cal_bookings)?>;
let calYear=<?=date('Y')?>, calMonth=<?=date('n')-1?>;
const monthNames=['January','February','March','April','May','June','July','August','September','October','November','December'];



let adminPicker=null;
function toggleDateFilter(){
    const btn=document.getElementById('dateFilterBtn');
    const chevron=document.getElementById('dateFilterChevron');
    if(!adminPicker){
        adminPicker=flatpickr('#adminDateRange',{
            mode:'range',dateFormat:'Y-m-d',disableMobile:true,positionElement:btn,
            onChange:function(d){
                if(d.length===2){
                    dfpFrom=d[0].toISOString().split('T')[0];
                    dfpTo=d[1].toISOString().split('T')[0];
                    const fmt=x=>x.toLocaleDateString('en-US',{month:'short',day:'numeric'});
                    document.getElementById('dateFilterLabel').textContent=fmt(d[0])+' - '+fmt(d[1]);
                    applyFilters(); adminPicker.close();
                }
                // d.length === 1: mid-selection (user just clicked the first date
                // of a new range) — leave the previous filter active until they
                // finish picking, instead of silently clearing it here.
            },
            onOpen:function(){ btn.classList.add('active'); chevron.style.transform='rotate(180deg)'; },
            onClose:function(){ btn.classList.remove('active'); chevron.style.transform=''; },
            onReady:function(selectedDates, dateStr, instance){
                const clearRow = document.createElement('div');
                clearRow.className = 'fp-clear-row';
                clearRow.innerHTML = '<button type="button" class="fp-clear-btn"><i class="fa-solid fa-rotate-left"></i> Clear</button>';
                clearRow.querySelector('.fp-clear-btn').addEventListener('click', function(e){
                    e.stopPropagation();
                    instance.clear();
                    dfpFrom=''; dfpTo='';
                    document.getElementById('dateFilterLabel').textContent='All dates';
                    applyFilters();
                    instance.close();
                });
                instance.calendarContainer.appendChild(clearRow);
            }
        });
    }
    adminPicker.toggle();
}

let currentStatus='all', currentSearch='', currentRoom='all', dfpFrom='', dfpTo='';
let currentPage=1, pageSize=10;

function filterByStatus(status){
    currentStatus=status;
    document.querySelectorAll('.book-tab').forEach(t=>t.classList.remove('active'));
    document.getElementById('btab-'+status)?.classList.add('active');
    currentPage=1;
    applyFilters();
}
function filterByRoom(room){ currentRoom=room; currentPage=1; applyFilters(); }
function filterBookings(){ currentSearch=document.getElementById('bookingSearch').value.trim().toLowerCase(); currentPage=1; applyFilters(); }
function changePageSize(size){ pageSize=parseInt(size,10); currentPage=1; applyFilters(); }
function pgGo(page){ currentPage=page; applyFilters(); }

function applyFilters(){
    const rows=Array.from(document.querySelectorAll('.b-row'));
    const matched=[];
    rows.forEach(row=>{
        const ms=currentStatus==='all'||row.dataset.status===currentStatus;
        const mr=currentRoom==='all'||row.dataset.room===currentRoom;
        const searchTerm=currentSearch.replace(/^#/,'');
        const mq=!currentSearch||row.dataset.name.includes(currentSearch)||row.dataset.room.includes(currentSearch)||row.dataset.ref.includes(searchTerm);
        const md=(!dfpFrom&&!dfpTo)||(!dfpTo&&row.dataset.checkin>=dfpFrom)||(!dfpFrom&&row.dataset.checkout<=dfpTo)||(dfpFrom&&dfpTo&&row.dataset.checkin<=dfpTo&&row.dataset.checkout>=dfpFrom);
        if(ms&&mr&&mq&&md) matched.push(row); else row.style.display='none';
    });

    const total=matched.length;
    const totalPages=Math.max(1,Math.ceil(total/pageSize));
    if(currentPage>totalPages) currentPage=totalPages;
    if(currentPage<1) currentPage=1;
    const start=(currentPage-1)*pageSize, end=start+pageSize;

    matched.forEach((row,i)=>{ row.style.display=(i>=start&&i<end)?'':'none'; });

    document.getElementById('bookingCount').textContent=total+' result'+(total!==1?'s':'');
    document.getElementById('noBookings').style.display=total===0?'':'none';
    document.getElementById('bookingsTable').style.display=total===0?'none':'';
    renderPager(total,totalPages,start,end);
}

function renderPager(total,totalPages,start,end){
    const pager=document.getElementById('bookingsPager');
    if(!pager) return;
    pager.style.display = total===0 ? 'none' : 'flex';
    if (total===0) return;

    document.getElementById('pgSummary').textContent =
        `Showing ${start+1} to ${Math.min(end,total)} of ${total} booking${total!==1?'s':''}`;
    document.getElementById('pgPrev').disabled = currentPage<=1;
    document.getElementById('pgNext').disabled = currentPage>=totalPages;
    document.getElementById('pgSizeSelect').value = String(pageSize);

    const numsEl=document.getElementById('pgNums');
    let pages=[];
    const add=p=>{ if(!pages.includes(p)) pages.push(p); };
    add(1); add(totalPages);
    for(let p=currentPage-1;p<=currentPage+1;p++) if(p>=1&&p<=totalPages) add(p);
    pages.sort((a,b)=>a-b);

    let html='';
    let prev=0;
    pages.forEach(p=>{
        if(prev && p-prev>1) html+=`<span class="pg-ellipsis">…</span>`;
        html+=`<button class="pg-num ${p===currentPage?'active':''}" onclick="pgGo(${p})">${p}</button>`;
        prev=p;
    });
    numsEl.innerHTML=html;
}

function filterActivity(){
    const q = document.getElementById('activitySearch').value.trim().toLowerCase();
    const rows = document.querySelectorAll('.act-row');
    let visible = 0;
    rows.forEach(row => {
        const show = !q || row.dataset.username.includes(q) || row.dataset.ip.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('noActivity').style.display = visible === 0 ? '' : 'none';
    document.getElementById('activityTable').style.display = visible === 0 ? 'none' : '';
}

const bookingData=<?=json_encode(array_map(fn($b)=>[
    'id'=>(int)$b['booking_id'],
    'ref'=>$b['booking_ref'],
    'name'=>$b['full_name'],
    'email'=>$b['guest_email'],
    'contact'=>$b['contact_number'],
    'room'=>$b['room_type'],
    'roomCount'=>(int)($b['room_count'] ?? 1),
    'checkin'=>$b['check_in'],
    'checkout'=>$b['check_out'],
    'guests'=>(int)($b['guests'] ?? 0),
    'adults'=>(int)($b['adults'] ?? 0),
    'children'=>(int)($b['children'] ?? 0),
    'status'=>$b['status'],
    'confirmedAt'=>$b['confirmed_at'],
    'createdAt'=>$b['created_at'],
    'paymentMethod'=>$b['payment_method'],
    'paymentReference'=>$b['payment_reference'],
    'totalPrice'=>(float)($b['total_price'] ?? 0)
],$bookings))?>;
function highlight(t,q){ if(!q) return escapeHtml(t); const e=q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); return escapeHtml(t).replace(new RegExp(`(${e})`,'gi'),'<mark>$1</mark>'); }
function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function goToBooking(id){ showSection('bookings',document.querySelectorAll('.sb-item')[1]); setTimeout(()=>{ document.querySelectorAll('.b-row').forEach(r=>r.classList.remove('row-highlight')); const t=document.querySelector(`[data-bid="${id}"]`); if(t){ t.classList.add('row-highlight'); t.scrollIntoView({behavior:'smooth',block:'center'}); } },150); }

/* ── "Confirmed Today" breakdown modal ──
   Reuses bookingData (already loaded for global search) instead of an
   extra query. Filters by WHEN a booking was acted on today — confirmed_at
   for confirmed bookings, created_at for still-pending ones — not by
   whether the guest's stay dates happen to fall today. A booking for next
   month that you confirmed 5 minutes ago belongs here; a booking checking
   in today that you confirmed last week does not. ── */
function showTodayGuests(roomName) {
    const todayStr = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD, local time
    const dateOnly = ts => ts ? ts.split(' ')[0].split('T')[0] : null;

    const confirmedToday = bookingData.filter(b =>
        b.room === roomName && b.status === 'confirmed' && dateOnly(b.confirmedAt) === todayStr
    );
    const pendingToday = bookingData.filter(b =>
        b.room === roomName && b.status === 'pending' && dateOnly(b.createdAt) === todayStr
    );
    const matches = [...confirmedToday, ...pendingToday];

    document.getElementById('tg-modal-label').textContent = 'Confirmed / requested today';
    renderTodayGuestList(roomName, matches, b => b.status === 'confirmed' ? 'Confirmed today' : 'Requested today');
}

function renderTodayGuestList(roomName, matches, actionLabelFn) {
    const fmt = d => d.toLocaleDateString('en-US',{month:'short',day:'numeric'});
    document.getElementById('tg-room-name').textContent = roomName;

    const listEl = document.getElementById('tg-guest-list');
    const emptyEl = document.getElementById('tg-empty');

    if (!matches.length) {
        listEl.innerHTML = '';
        listEl.style.display = 'none';
        emptyEl.style.display = 'block';
    } else {
        emptyEl.style.display = 'none';
        listEl.style.display = 'flex';
        listEl.innerHTML = matches.map(b => {
            const isConfirmed = b.status === 'confirmed';
            const pillClass = isConfirmed ? 'status--confirmed' : 'status--pending';
            return `<div class="tg-guest-row" onclick="closeTodayGuestsModal();goToBooking(${b.id})">
                <div class="tg-guest-info">
                    <span class="tg-guest-name">${escapeHtml(b.name)}</span>
                    <span class="tg-guest-dates">${actionLabelFn(b)} · Stay: ${fmt(new Date(b.checkin))} - ${fmt(new Date(b.checkout))}</span>
                </div>
                <span class="status-badge ${pillClass}">${b.status.charAt(0).toUpperCase()+b.status.slice(1)}</span>
            </div>`;
        }).join('');
    }

    document.getElementById('todayGuestsModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeTodayGuestsModal(){
    document.getElementById('todayGuestsModal').classList.remove('open');
    document.body.style.overflow = '';
}

function toggleMenu(id, e, prefix) {
    e.stopPropagation();
    const wrap = document.getElementById((prefix || 'am-') + id);
    if (!wrap) return;

    const isOpen = wrap.classList.contains('open');
    document.querySelectorAll('.action-menu.open').forEach(m => m.classList.remove('open'));

    // Opening a booking/room action menu should never leave the Rooms filter open.
    document.getElementById('roomsFilterPanel')?.classList.remove('open');
    document.getElementById('roomsFilterBtn')?.classList.remove('open');

    if (!isOpen) {
        wrap.classList.add('open');
        positionDrop(wrap);
        requestAnimationFrame(() => positionDrop(wrap));
    }
}

function positionDrop(wrap) {
    if (!wrap) return;

    // This dashboard uses .action-icon-btn for the trigger. Older CSS/JS used
    // .action-btn, which caused btn to be null and left the menu unpositioned.
    const btn = wrap.querySelector('.action-icon-btn, .action-btn');
    const drop = wrap.querySelector('.action-dropdown');
    if (!btn || !drop) return;

    const rect = btn.getBoundingClientRect();

    // Temporarily make the fixed dropdown measurable without flashing it.
    const previousDisplay = drop.style.display;
    if (getComputedStyle(drop).display === 'none') drop.style.display = 'block';
    const dropRect = drop.getBoundingClientRect();
    const dropWidth = dropRect.width || 148;
    const dropHeight = dropRect.height || 88;
    drop.style.display = previousDisplay;

    const gap = 6;
    const pad = 10;

    // Prefer below the trigger, otherwise flip above it.
    let top = rect.bottom + gap;
    if (top + dropHeight > window.innerHeight - pad) {
        top = rect.top - dropHeight - gap;
    }
    top = Math.max(pad, Math.min(top, window.innerHeight - dropHeight - pad));

    // Keep the menu inside the viewport horizontally.
    let left = rect.right - dropWidth;
    left = Math.max(pad, Math.min(left, window.innerWidth - dropWidth - pad));

    drop.style.top = Math.round(top) + 'px';
    drop.style.left = Math.round(left) + 'px';
    drop.style.right = 'auto';
}

function repositionOpenActionMenu(){
    const openMenu = document.querySelector('.action-menu.open');
    if (openMenu) positionDrop(openMenu);
}

window.addEventListener('resize', repositionOpenActionMenu);
window.addEventListener('scroll', repositionOpenActionMenu, true);

function openBookingModal(name,room,checkin,checkout,nights,guests,bookedDate,bookedTime,email,idType,contact,status,idPhoto,adults,children,paymentMethod,paymentRef,paymentReceipt,bookingRef,totalPrice,bookingId){
    document.getElementById('bm-ref').textContent=bookingRef;
    document.getElementById('bm-name').textContent=name; document.getElementById('bm-room').textContent=room;
    const avatarWrap = document.getElementById('bm-avatar');
    const avatarImg = document.getElementById('bm-avatar-img');
    const avatarLetter = document.getElementById('bm-avatar-letter');
    if (idPhoto) {
        avatarImg.onerror = () => {
            avatarImg.style.display = 'none';
            avatarLetter.style.display = '';
            avatarLetter.textContent = (name||'?').charAt(0).toUpperCase();
            avatarWrap.style.cursor = 'default';
        };
        avatarImg.src = idPhoto;
        avatarImg.style.display = 'block';
        avatarLetter.style.display = 'none';
        avatarWrap.style.cursor = 'pointer';
    } else {
        avatarImg.removeAttribute('src');
        avatarImg.style.display = 'none';
        avatarLetter.style.display = '';
        avatarLetter.textContent = (name||'?').charAt(0).toUpperCase();
        avatarWrap.style.cursor = 'default';
    }
    document.getElementById('bm-checkin').textContent=checkin; document.getElementById('bm-checkout').textContent=checkout;
    document.getElementById('bm-nights').textContent=nights+' night'+(nights!=1?'s':'');

    // Bookings made before adults/children were tracked have both at 0 —
    // fall back to just the total guest count for those.
    if (adults + children > 0) {
        let parts = [adults + ' Adult' + (adults!=1?'s':'')];
        if (children > 0) parts.push(children + ' Child' + (children!=1?'ren':''));
        document.getElementById('bm-guests').textContent = parts.join(' · ');
    } else {
        document.getElementById('bm-guests').textContent=guests+' Guest'+(guests!=1?'s':'');
    }

    document.getElementById('bm-booked-date').textContent=bookedDate;
    document.getElementById('bm-booked-time').textContent=bookedTime;
    document.getElementById('bm-email').textContent=email||'Not provided';

    const msgBtn = document.getElementById('bm-message-btn');
    if (email) {
        msgBtn.href = 'mailto:' + encodeURIComponent(email) + '?subject=' + encodeURIComponent('CoraVergel Resort — Booking ' + bookingRef);
        msgBtn.classList.remove('disabled');
    } else {
        msgBtn.href = '#';
        msgBtn.classList.add('disabled');
    }
    document.getElementById('bm-idtype').textContent=idType||'Not provided';
    document.getElementById('bm-contact').textContent=contact||'Not provided';
    document.getElementById('bm-paymethod').textContent=paymentMethod||'Not provided';
    document.getElementById('bm-price').textContent='₱'+Number(totalPrice||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});

    const idVerified = document.getElementById('bm-id-verified');
    const idRow = document.getElementById('bm-id-row');
    if (idPhoto) {
        idVerified.style.display = 'inline';
        idRow.style.cursor = 'pointer';
    } else {
        idVerified.style.display = 'none';
        idRow.style.cursor = 'default';
    }

    if (status === 'cancelled') {
        document.getElementById('bm-paystatus').textContent = 'Not Paid';
    } else if (paymentRef || paymentReceipt) {
        document.getElementById('bm-paystatus').textContent = 'Paid';
    } else {
        document.getElementById('bm-paystatus').textContent = 'Pending';
    }

    const payrefField = document.getElementById('bm-payref-field');
    if (paymentRef) {
        document.getElementById('bm-payref').textContent = paymentRef;
        payrefField.style.display = 'flex';
    } else {
        payrefField.style.display = 'none';
    }

    const photoImg = document.getElementById('bm-idphoto');
    const photoWrap = document.getElementById('bm-idphoto-wrap');
    const photoEmpty = document.getElementById('bm-idphoto-empty');
    if (idPhoto) {
        photoImg.src = idPhoto;
        photoWrap.style.display = 'block';
        photoEmpty.style.display = 'none';
    } else {
        photoWrap.style.display = 'none';
        photoEmpty.style.display = 'flex';
    }

    const receiptImg = document.getElementById('bm-receipt');
    const receiptCard = document.getElementById('bm-receipt-card');
    const receiptEmpty2 = document.getElementById('bm-receipt-empty2');
    if (paymentReceipt) {
        receiptImg.src = paymentReceipt;
        document.getElementById('bm-receipt-name').textContent = paymentReceipt.split('/').pop();
        document.getElementById('bm-receipt-date').textContent = bookedDate;
        document.getElementById('bm-receipt-download').href = paymentReceipt;
        receiptCard.style.display = 'flex';
        receiptEmpty2.style.display = 'none';
    } else {
        receiptCard.style.display = 'none';
        receiptEmpty2.style.display = 'block';
    }

    const title=document.getElementById('bm-title'), badge=document.getElementById('bm-status-badge');
    const confirmForm=document.getElementById('bm-confirm-form'), cancelForm=document.getElementById('bm-cancel-form');
    document.getElementById('bm-confirm-id').value = bookingId;
    document.getElementById('bm-cancel-id').value = bookingId;

    if(status==='confirmed'){
        title.textContent='Booking confirmed';
        badge.textContent='Confirmed'; badge.className='bmodal-side-status';
        confirmForm.style.display='none';
        cancelForm.style.display='block';
    } else if(status==='cancelled'){
        title.textContent='Booking cancelled';
        badge.textContent='Cancelled'; badge.className='bmodal-side-status bmodal-side-status--cancelled';
        confirmForm.style.display='none';
        cancelForm.style.display='none';
    } else {
        title.textContent='Awaiting confirmation';
        badge.textContent='Pending'; badge.className='bmodal-side-status bmodal-side-status--pending';
        confirmForm.style.display='block';
        cancelForm.style.display='block';
    }

    document.getElementById('bookingModal').classList.add('open'); document.body.style.overflow='hidden';
}
function closeBookingModal(){ document.getElementById('bookingModal').classList.remove('open'); document.body.style.overflow=''; }
function bmOpenIdPhoto(){
    const wrap = document.getElementById('bm-idphoto-wrap');
    const img = document.getElementById('bm-idphoto');
    if (wrap.style.display !== 'none' && img.src) openIdPhotoLightbox(img.src);
}
function openIdPhotoLightbox(src){
    document.getElementById('idPhotoLightboxImg').src = src;
    document.getElementById('idPhotoLightbox').style.display = 'flex';
}
function closeIdPhotoLightbox(){
    document.getElementById('idPhotoLightbox').style.display = 'none';
    document.getElementById('idPhotoLightboxImg').src = '';
}

/* ══════════════════════════════════════
   PHOTO UPLOAD (single image, with preview)
══════════════════════════════════════ */
function handlePhotoSelect(input, prefix) {
    const file = input.files && input.files[0];
    if (!file) return;
    const previewImg   = document.getElementById(prefix === 'add' ? 'addPhotoPreviewImg' : 'er-photo-preview');
    const placeholder  = document.getElementById(prefix === 'add' ? 'addPhotoPlaceholder' : 'er-photo-placeholder');
    const reader = new FileReader();
    reader.onload = e => {
        previewImg.src = e.target.result;
        previewImg.style.display = 'block';
        placeholder.style.display = 'none';
        if (prefix === 'add') {
            document.getElementById('addPhotoRemoveBtn').style.display = 'flex';
            const addHint = document.getElementById('add-photo-hint');
            if (addHint) addHint.style.display = 'block';
        } else {
            const hint = document.getElementById('er-photo-hint');
            if (hint) hint.style.display = 'block';
        }
    };
    reader.readAsDataURL(file);
}

function clearPhotoSelection(e, prefix) {
    e.stopPropagation();
    const input        = document.getElementById(prefix === 'add' ? 'addRoomFileInput' : 'editRoomFileInput');
    const previewImg    = document.getElementById(prefix === 'add' ? 'addPhotoPreviewImg' : 'er-photo-preview');
    const placeholder  = document.getElementById(prefix === 'add' ? 'addPhotoPlaceholder' : 'er-photo-placeholder');
    input.value = '';
    previewImg.style.display = 'none';
    previewImg.src = '';
    placeholder.style.display = 'flex';
    if (prefix === 'add') {
        document.getElementById('addPhotoRemoveBtn').style.display = 'none';
        const addHint = document.getElementById('add-photo-hint');
        if (addHint) addHint.style.display = 'none';
    }
}

function setupPhotoDropzone(zoneId, inputId, prefix) {
    const zone = document.getElementById(zoneId);
    if (!zone) return;
    ['dragover', 'dragenter'].forEach(evt => zone.addEventListener(evt, e => {
        e.preventDefault(); e.stopPropagation(); zone.classList.add('dragover');
    }));
    ['dragleave', 'dragend'].forEach(evt => zone.addEventListener(evt, e => {
        e.preventDefault(); e.stopPropagation(); zone.classList.remove('dragover');
    }));
    zone.addEventListener('drop', e => {
        e.preventDefault(); e.stopPropagation();
        zone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            const dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]); // one photo only — ignore any extra dropped files
            const input = document.getElementById(inputId);
            input.files = dt.files;
            handlePhotoSelect(input, prefix);
        }
    });
}
setupPhotoDropzone('addPhotoDropZone', 'addRoomFileInput', 'add');
setupPhotoDropzone('editPhotoDropZone', 'editRoomFileInput', 'edit');

function roomCountChars(taId, countId){
    const ta = document.getElementById(taId);
    const el = document.getElementById(countId);
    if (!ta || !el) return;
    const max = ta.getAttribute('maxlength') || 255;
    el.textContent = ta.value.length + ' / ' + max;
}

function openAddRoomModal(){
    document.getElementById('addRoomForm').reset();
    document.getElementById('addPhotoPreviewImg').style.display = 'none';
    document.getElementById('addPhotoPreviewImg').src = '';
    document.getElementById('addPhotoPlaceholder').style.display = 'flex';
    document.getElementById('addPhotoRemoveBtn').style.display = 'none';
    const addHintReset = document.getElementById('add-photo-hint');
    if (addHintReset) addHintReset.style.display = 'none';
    roomCountChars('add-desc','add-desc-count');
    document.getElementById('addRoomModal').classList.add('open'); document.body.style.overflow='hidden';
}
function closeAddRoomModal(){ document.getElementById('addRoomModal').classList.remove('open'); document.body.style.overflow=''; }

function openEditRoomModal(id,name,price,units,description,imageUrl,capacity,badge){
    document.getElementById('er-room-id').value=id;
    document.getElementById('er-room-name').value=name;
    document.getElementById('er-price').value=price;
    document.getElementById('er-units').value=units;
    document.getElementById('er-capacity').value=capacity || 4;
    document.getElementById('er-badge').value=badge || 'Available';
    document.getElementById('er-description').value=description || '';
    roomCountChars('er-description','er-desc-count');

    const previewImg  = document.getElementById('er-photo-preview');
    const placeholder = document.getElementById('er-photo-placeholder');
    const hint         = document.getElementById('er-photo-hint');
    if (imageUrl) {
        previewImg.src = imageUrl; previewImg.style.display = 'block';
        placeholder.style.display = 'none';
        if (hint) hint.style.display = 'block';
    } else {
        previewImg.style.display = 'none'; previewImg.src = '';
        placeholder.style.display = 'flex';
        if (hint) hint.style.display = 'none';
    }

    document.getElementById('editRoomFileInput').value = '';

    document.getElementById('editRoomModal').classList.add('open'); document.body.style.overflow='hidden';
}
function closeEditRoomModal(){ document.getElementById('editRoomModal').classList.remove('open'); document.body.style.overflow=''; }

function openViewRoomModal(name, imageUrl, price, capacity, status, available, totalUnits, description){
    document.getElementById('vr-name').textContent = name;
    document.getElementById('vr-price').textContent = '₱' + Number(price).toLocaleString();
    document.getElementById('vr-capacity').textContent = capacity + ' Capacity' + (capacity != 1 ? '' : '');
    document.getElementById('vr-units').textContent = totalUnits;
    document.getElementById('vr-availability').textContent = available + ' / ' + totalUnits + ' units available';

    const statusEl = document.getElementById('vr-status');
    statusEl.textContent = status;
    statusEl.className = 'bmodal-side-status ' + (status === 'Occupied' ? 'room-status-occupied' : status === 'Maintenance' ? 'room-status-maintenance' : 'room-status-available');

    const img = document.getElementById('vr-image'), icon = document.getElementById('vr-icon');
    if (imageUrl) { img.src = imageUrl; img.style.display = 'block'; icon.style.display = 'none'; }
    else { img.style.display = 'none'; icon.style.display = 'flex'; }

    const desc = document.getElementById('vr-desc'), descSection = document.getElementById('vr-desc-section'), descSummary = document.getElementById('vr-desc-summary');
    descSummary.textContent = description || 'No description provided';
    if (description) { desc.textContent = description; desc.style.display = 'block'; descSection.style.display = 'flex'; }
    else { desc.style.display = 'none'; descSection.style.display = 'none'; }

    const guestList = document.getElementById('vr-confirmed-list');
    const emptyEl = document.getElementById('vr-confirmed-empty');
    const countEl = document.getElementById('vr-confirmed-count');
    const confirmed = bookingData.filter(b => b.room === name && b.status === 'confirmed');
    countEl.textContent = confirmed.length;

    if (!confirmed.length) {
        guestList.innerHTML = ''; guestList.style.display = 'none'; emptyEl.style.display = 'block';
    } else {
        emptyEl.style.display = 'none'; guestList.style.display = 'flex';
        const fmtDate = value => { if (!value) return '—'; const d = new Date(value.replace(' ', 'T')); return isNaN(d.getTime()) ? value : d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); };
        const esc = value => escapeHtml(value ?? 'Not provided');
        const guestText = b => { if ((b.adults||0)+(b.children||0)>0) { const parts=[]; if(b.adults>0)parts.push(b.adults+' Adult'+(b.adults!==1?'s':'')); if(b.children>0)parts.push(b.children+' Child'+(b.children!==1?'ren':'')); return parts.join(' · '); } return (b.guests||0)+' Guest'+((b.guests||0)!==1?'s':''); };
        guestList.innerHTML = confirmed.map(b => `
            <div class="room-confirmed-booking">
                <div class="room-confirmed-static">
                    <span class="bmodal-panel-avatar room-guest-avatar">${esc((b.name||'?').charAt(0).toUpperCase())}</span>
                    <span class="room-guest-main">
                        <span class="bmodal-panel-name room-guest-name">${esc(b.name)}</span>
                        <span class="bmodal-panel-contact"><i class="fa-regular fa-envelope"></i> ${esc(b.email)}</span>
                        <span class="bmodal-panel-contact"><i class="fa-solid fa-phone"></i> ${esc(b.contact)}</span>
                    </span>
                    <span class="bmodal-side-status room-status-confirmed">Confirmed</span>
                </div>
                <div class="room-confirmed-content">
                    <div class="bmodal-panel-statusrow room-guest-statusrow">
                        <span class="bmodal-panel-ref">Booking Ref: <b>#${esc(b.ref)}</b></span>
                    </div>
                    <button type="button" class="room-open-booking" onclick="goToBooking(${b.id})"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open Booking Details</button>
                </div>
            </div>`).join('');
    }
    document.getElementById('viewRoomModal').classList.add('open'); document.body.style.overflow='hidden';
}
function closeViewRoomModal(){ document.getElementById('viewRoomModal').classList.remove('open'); document.body.style.overflow=''; }

const ROOMS_PAGE_SIZE = 6;
let roomsCurrentPage = 1;

let roomsFilterState = { status: 'all', type: 'all', capacity: 'all', availability: 'all' };

function setRoomStatusQuick(value){
    roomsFilterState.status = value;
    filterRoomsTable();
}

function setRoomTypeQuick(value){
    roomsFilterState.type = value;
    filterRoomsTable();
}

function toggleRoomsFilter(e){
    if(e) e.stopPropagation();
    closeNotif();
    document.querySelectorAll('.action-menu.open').forEach(m => m.classList.remove('open'));

    const panel = document.getElementById('roomsFilterPanel');
    const btn = document.getElementById('roomsFilterBtn');
    if(!panel) return;

    const open = panel.classList.toggle('open');
    if(btn) btn.classList.toggle('open', open);

    // Keep the panel inside the viewport on narrower desktop windows.
    if(open && window.innerWidth > 600){
        const wrap = document.getElementById('roomsFilterWrap');
        const panelRect = panel.getBoundingClientRect();
        if(wrap){
            const wrapRect = wrap.getBoundingClientRect();
            if(wrapRect.right - panelRect.width < 12){
                panel.style.right = '0';
                panel.style.left = 'auto';
            }
        }
    }
}

function setRoomFilter(el){
    const group = el.dataset.rfGroup;
    const value = el.dataset.rfValue;
    roomsFilterState[group] = value;
    document.querySelectorAll('.rf-chip[data-rf-group="'+group+'"]').forEach(chip => chip.classList.remove('active'));
    el.classList.add('active');
    filterRoomsTable();
}

function setRoomCapacity(value){
    roomsFilterState.capacity = value;
    filterRoomsTable();
}

function applyRoomsFilterPanel(){
    filterRoomsTable();
    const panel = document.getElementById('roomsFilterPanel');
    const btn = document.getElementById('roomsFilterBtn');
    panel?.classList.remove('open');
    btn?.classList.remove('open');
}

function clearRoomsFilters(){
    roomsFilterState.capacity = 'all';
    roomsFilterState.availability = 'all';
    const min = document.getElementById('roomsMinPrice');
    const max = document.getElementById('roomsMaxPrice');
    if(min) min.value = '';
    if(max) max.value = '';
    const capacitySelect = document.getElementById('roomsCapacitySelect');
    const sortSelect = document.getElementById('roomsSortBy');
    if(capacitySelect) capacitySelect.value = 'all';
    if(sortSelect) sortSelect.value = 'default';
    filterRoomsTable();
}

function updateRoomsFilterCount(){
    const min = document.getElementById('roomsMinPrice')?.value || '';
    const max = document.getElementById('roomsMaxPrice')?.value || '';
    const count = (roomsFilterState.capacity !== 'all' ? 1 : 0)
        + (roomsFilterState.availability !== 'all' ? 1 : 0)
        + (min !== '' ? 1 : 0)
        + (max !== '' ? 1 : 0);
    const badge = document.getElementById('roomsFilterCount');
    if(badge){ badge.textContent = count; badge.style.display = count ? 'inline-flex' : 'none'; }
}

function filterRoomsTable(){
    roomsCurrentPage = 1;
    renderRoomsTable();
}

function renderRoomsTable(){
    const table = document.getElementById('roomsTable');
    if (!table) return;
    const search = (document.getElementById('roomsSearchInput')?.value || '').toLowerCase().trim();
    const minPrice = parseFloat(document.getElementById('roomsMinPrice')?.value || '') || null;
    const maxPrice = parseFloat(document.getElementById('roomsMaxPrice')?.value || '') || null;

    const allRows = Array.from(table.querySelectorAll('tbody tr')).filter(r => r.dataset.status !== undefined);
    const matches = allRows.filter(row => {
        const matchesSearch = !search || row.dataset.search.includes(search);
        const matchesStatus = roomsFilterState.status === 'all' || row.dataset.status === roomsFilterState.status;
        const matchesType = roomsFilterState.type === 'all' || row.dataset.type === roomsFilterState.type;
        const capacity = parseInt(row.dataset.capacity || '0', 10);
        const price = parseFloat(row.dataset.price || '0');
        const available = parseInt(row.dataset.available || '0', 10);
        const total = parseInt(row.dataset.totalUnits || '0', 10);
        const matchesCapacity = roomsFilterState.capacity === 'all'
            || (roomsFilterState.capacity === '2' && capacity <= 2)
            || (roomsFilterState.capacity === '4' && capacity >= 3 && capacity <= 4)
            || (roomsFilterState.capacity === '6' && capacity >= 5 && capacity <= 6)
            || (roomsFilterState.capacity === '7' && capacity >= 7);
        const matchesAvailability = roomsFilterState.availability === 'all'
            || (roomsFilterState.availability === 'available' && available > 0)
            || (roomsFilterState.availability === 'low' && available > 0 && available <= Math.max(1, Math.ceil(total * .25)))
            || (roomsFilterState.availability === 'full' && total > 0 && available <= 0);
        const matchesMin = minPrice === null || price >= minPrice;
        const matchesMax = maxPrice === null || price <= maxPrice;
        return matchesSearch && matchesStatus && matchesType && matchesCapacity && matchesAvailability && matchesMin && matchesMax;
    });

    const sortBy = document.getElementById('roomsSortBy')?.value || 'default';
    matches.sort((a, b) => {
        const aName = (a.dataset.search || '').localeCompare(b.dataset.search || '');
        const aPrice = parseFloat(a.dataset.price || '0'), bPrice = parseFloat(b.dataset.price || '0');
        const aCap = parseInt(a.dataset.capacity || '0', 10), bCap = parseInt(b.dataset.capacity || '0', 10);
        const aAvail = parseInt(a.dataset.available || '0', 10), bAvail = parseInt(b.dataset.available || '0', 10);
        if(sortBy === 'priceAsc') return aPrice - bPrice;
        if(sortBy === 'priceDesc') return bPrice - aPrice;
        if(sortBy === 'capacityAsc') return aCap - bCap;
        if(sortBy === 'capacityDesc') return bCap - aCap;
        if(sortBy === 'availabilityDesc') return bAvail - aAvail;
        if(sortBy === 'availabilityAsc') return aAvail - bAvail;
        return aName;
    });

    allRows.forEach(r => r.style.display = 'none');
    const totalPages = Math.max(1, Math.ceil(matches.length / ROOMS_PAGE_SIZE));
    if (roomsCurrentPage > totalPages) roomsCurrentPage = totalPages;
    const start = (roomsCurrentPage - 1) * ROOMS_PAGE_SIZE;
    const pageRows = matches.slice(start, start + ROOMS_PAGE_SIZE);
    pageRows.forEach(r => r.style.display = '');

    const countLabel = document.getElementById('roomsCountLabel');
    if (countLabel) {
        countLabel.textContent = matches.length === 0 ? 'No rooms found' : 'Showing ' + (start + 1) + ' to ' + (start + pageRows.length) + ' of ' + matches.length + ' rooms';
    }
    updateRoomsFilterCount();
    renderRoomsPagination(totalPages);
}

function roomsPageList(current, total){
    const maxVisible = 5;
    if (total <= maxVisible + 1) {
        return Array.from({length: total}, (_, i) => i + 1);
    }
    if (current <= maxVisible - 2) {
        return [1, 2, 3, 4, 5, '...', total];
    }
    if (current >= total - (maxVisible - 3)) {
        return [1, '...', total - 4, total - 3, total - 2, total - 1, total];
    }
    return [1, '...', current - 1, current, current + 1, '...', total];
}

function renderRoomsPagination(totalPages){
    const wrap = document.getElementById('roomsPagination');
    if (!wrap) return;
    if (totalPages <= 1) { wrap.innerHTML = ''; return; }

    let html = '<button class="rooms-page-btn" ' + (roomsCurrentPage === 1 ? 'disabled' : '') + ' onclick="goRoomsPage(' + (roomsCurrentPage - 1) + ')"><i class="fa-solid fa-chevron-left"></i></button>';
    roomsPageList(roomsCurrentPage, totalPages).forEach(p => {
        if (p === '...') {
            html += '<span class="rooms-page-dots">&hellip;</span>';
        } else {
            html += '<button class="rooms-page-btn' + (p === roomsCurrentPage ? ' active' : '') + '" onclick="goRoomsPage(' + p + ')">' + p + '</button>';
        }
    });
    html += '<button class="rooms-page-btn" ' + (roomsCurrentPage === totalPages ? 'disabled' : '') + ' onclick="goRoomsPage(' + (roomsCurrentPage + 1) + ')"><i class="fa-solid fa-chevron-right"></i></button>';
    wrap.innerHTML = html;
}

function goRoomsPage(p){ roomsCurrentPage = p; renderRoomsTable(); }

document.addEventListener('DOMContentLoaded', renderRoomsTable);

// toggleNotif — close rooms filter first, and physically hide its button
// (not just its dropdown) so it can never render above the notif panel
// no matter what z-index/stacking-context quirks the browser has.
function toggleNotif(e){
    e.stopPropagation();
    document.getElementById('roomsFilterPanel')?.classList.remove('open');
    document.getElementById('roomsFilterBtn')?.classList.remove('open');
    const opening = !document.getElementById('notifPanel').classList.contains('open');
    document.getElementById('notifPanel').classList.toggle('open');
    document.getElementById('notifWrap').classList.toggle('open');
    document.body.classList.toggle('notif-open', opening);
}
function closeNotif(){
    document.getElementById('notifPanel').classList.remove('open');
    document.getElementById('notifWrap').classList.remove('open');
    document.body.classList.remove('notif-open');
}
function clearNotificationUnreadState(el){
    if(!el) return;
    el.classList.remove('notif-item--unread','notif-page-item--unread');
    el.dataset.unread='0';
    el.querySelector('.ni-dot')?.remove();
}

function updateUnreadCountUI(delta=0){
    const badgeEls = document.querySelectorAll('.notif-count');
    const pillEls = document.querySelectorAll('.notif-unread-pill');
    const sidebarBadges = document.querySelectorAll('.sb-item');
    const tabCount = document.getElementById('ntab-unread')?.querySelector('.notif-tab-count');

    let current = 0;
    const firstBadge = badgeEls[0];
    if(firstBadge) current = parseInt(firstBadge.textContent,10) || 0;
    else if(tabCount) current = parseInt(tabCount.textContent,10) || 0;

    const next = Math.max(0, current + delta);
    if(tabCount) tabCount.textContent = String(next);

    badgeEls.forEach(el => el.textContent = String(next));
    pillEls.forEach(el => el.textContent = next + ' new');

    if(next === 0){
        badgeEls.forEach(el => el.remove());
        pillEls.forEach(el => el.remove());
        document.querySelector('.notif-mark-all')?.remove();
        document.querySelector('#section-notifications .qa-action-btn')?.remove();
        sidebarBadges.forEach(b=>{
            if(b.textContent.includes('Notifications')) b.querySelector('.sb-badge')?.remove();
        });
    }
}

function markNotificationRead(bookingId){
    const items = Array.from(document.querySelectorAll('.notif-item, .notif-page-item'));
    const selector = '[onclick*="goToBooking(' + bookingId + ')"]';

    // Update the visible UI immediately.
    let affected = 0;
    items.forEach(el=>{
        if(el.matches(selector) && el.classList.contains('notif-item--unread')){
            clearNotificationUnreadState(el);
            affected++;
        } else if(el.matches(selector) && el.classList.contains('notif-page-item--unread')){
            clearNotificationUnreadState(el);
            affected++;
        }
    });
    if(affected) updateUnreadCountUI(-affected);

    const csrfInput = document.querySelector('#csrfTokenHolder input');
    const body = new URLSearchParams();
    body.append('action','mark_notification_read');
    body.append('booking_id', String(bookingId));
    if(csrfInput) body.append(csrfInput.name, csrfInput.value);

    fetch('admin_dashboard.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body
    }).catch(()=>{});
}

function markAllRead(){
    document.querySelectorAll('.notif-item--unread, .notif-page-item--unread').forEach(clearNotificationUnreadState);
    document.querySelector('.notif-count')?.remove();
    document.querySelector('.notif-unread-pill')?.remove();
    document.querySelector('.notif-mark-all')?.remove();
    document.querySelector('#section-notifications .qa-action-btn')?.remove();
    document.querySelectorAll('.sb-item').forEach(b=>{
        if(b.textContent.includes('Notifications')) b.querySelector('.sb-badge')?.remove();
    });
    const unreadCountEl = document.getElementById('ntab-unread')?.querySelector('.notif-tab-count');
    if (unreadCountEl) unreadCountEl.textContent = '0';

    // Persist to the server so the count stays cleared after reload.
    const csrfInput = document.querySelector('#csrfTokenHolder input');
    const body = new URLSearchParams();
    body.append('action', 'mark_notifications_read');
    if (csrfInput) body.append(csrfInput.name, csrfInput.value);
    fetch('admin_dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
    }).catch(() => {});
}

document.addEventListener('click',e=>{
    const rfw=document.getElementById('roomsFilterWrap');
    if(rfw && !rfw.contains(e.target)){ document.getElementById('roomsFilterPanel')?.classList.remove('open'); document.getElementById('roomsFilterBtn')?.classList.remove('open'); }
    const nw=document.getElementById('notifWrap'); if(nw&&!nw.contains(e.target)) closeNotif();
    if(adminPicker&&adminPicker.isOpen){
        const dfw=document.querySelector('.date-filter-wrap');
        const insideCalendar = e.target.closest('.flatpickr-calendar');
        if(dfw && !dfw.contains(e.target) && !insideCalendar) adminPicker.close();
    }
    document.querySelectorAll('.action-menu.open').forEach(m=>m.classList.remove('open'));
});
document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ closeBookingModal(); closeIdPhotoLightbox(); closeTodayGuestsModal(); if(adminPicker) adminPicker.close(); } });

/* ── Room modal description character counters ── */
(function(){
    const addDesc = document.querySelector('#addRoomForm textarea[name="description"]');
    const addCount = document.getElementById('addDescriptionCount');
    const editDesc = document.getElementById('er-description');
    const editCount = document.getElementById('editDescriptionCount');

    function bindCounter(input, counter){
        if(!input || !counter) return;
        const update = () => counter.textContent = input.value.length + ' / 255';
        input.addEventListener('input', update);
        update();
    }
    bindCounter(addDesc, addCount);
    bindCounter(editDesc, editCount);
})();

/* ── Restore last-viewed section on reload ── */
(function(){
    const saved = localStorage.getItem('adminActiveSection');
    if (saved && document.getElementById('section-' + saved)) {
        const idx = { overview: 0, bookings: 1, rooms: 2, notifications: 3, settings: 4 }[saved];
        const el = document.querySelectorAll('.sb-item')[idx];
        showSection(saved, el);
    }
})();

const alertEl=document.getElementById('dashAlert');
if(alertEl){ setTimeout(()=>{ alertEl.style.transition='opacity 0.5s'; alertEl.style.opacity='0'; setTimeout(()=>alertEl.remove(),500); },4000); }
</script>

<script>
(function(){
  function updateNotificationTimes(){
    document.querySelectorAll('[data-notif-time]').forEach(function(el){
      const raw = el.getAttribute('data-notif-time');
      if(!raw) return;
      const ts = new Date(raw.replace(' ', 'T')).getTime();
      if(Number.isNaN(ts)) return;
      const seconds = Math.max(0, Math.floor((Date.now() - ts) / 1000));
      let text;
      if(seconds < 60) text = 'Just now';
      else if(seconds < 3600) text = Math.floor(seconds / 60) + 'm ago';
      else if(seconds < 86400) text = Math.floor(seconds / 3600) + 'h ago';
      else if(seconds < 604800) text = Math.floor(seconds / 86400) + 'd ago';
      else {
        const d = new Date(ts);
        text = d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
      }
      el.textContent = text;
    });
  }
  updateNotificationTimes();
  setInterval(updateNotificationTimes, 30000);
})();
</script>
</body>
</html>
