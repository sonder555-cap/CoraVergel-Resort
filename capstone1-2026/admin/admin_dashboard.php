<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';
require_once '../config/availability.php';
require_once '../config/mailer.php';
require_once '../config/csrf.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: ../admin/admin_login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'];
$success = '';
$error   = '';

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    csrfVerify();
    $bid = intval($_POST['cancel_booking']);
    $stmt = $conn->prepare("UPDATE bookings SET status='cancelled' WHERE booking_id=?");
    $stmt->bind_param("i", $bid);
    $stmt->execute();
    $stmt->close();
    $_SESSION['flash_success'] = "Booking cancelled.";
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
        $stmt = $conn->prepare("SELECT room_type, check_in, check_out, guests, total_price, guest_name, guest_email, status FROM bookings WHERE booking_id = ? FOR UPDATE");
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

            if (!isRoomAvailable($conn, $booking_info['room_type'], $booking_info['check_in'], $booking_info['check_out'], $bid)) {
                $conn->rollback();
                $_SESSION['flash_error'] = "Can't confirm — " . htmlspecialchars($booking_info['room_type']) . " has no units left for " . fmtAdminDate($booking_info['check_in']) . " to " . fmtAdminDate($booking_info['check_out']) . ". Cancel a conflicting booking first, or contact the guest about different dates.";
            } else {
                $upd = $conn->prepare("UPDATE bookings SET status='confirmed', confirmed_at=NOW() WHERE booking_id=?");
                $upd->bind_param("i", $bid);
                $upd->execute();
                $upd->close();
                $conn->commit();

                try {
                    $email_html = buildBookingConfirmationEmail(
                        $booking_info['guest_name'],
                        $booking_info['room_type'],
                        $booking_info['check_in'],
                        $booking_info['check_out'],
                        $booking_info['guests'],
                        $booking_info['total_price'],
                        $bid
                    );
                    if (sendMail($booking_info['guest_email'], $booking_info['guest_name'], "Your CoraVergel Resort Booking is Confirmed", $email_html)) {
                        $_SESSION['flash_success'] = "Booking confirmed and email sent to " . htmlspecialchars($booking_info['guest_email']) . ".";
                    } else {
                        $_SESSION['flash_success'] = "Booking confirmed, but the email failed to send.";
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

/* ── Helper: handle up to 5 uploaded room photos.
   Returns ['primary' => filename|null, 'gallery' => [filenames], 'error' => string|null] ── */
function handleRoomPhotoUploads($files) {
    $result = ['primary' => null, 'gallery' => [], 'error' => null];
    if (empty($files['name'][0])) return $result; // nothing uploaded

    $count = count(array_filter($files['name']));
    if ($count > 5) {
        $result['error'] = "You can upload up to 5 photos only.";
        return $result;
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
    $upload_dir = '../assets/images/rooms/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $saved = [];
    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $file_type = mime_content_type($files['tmp_name'][$i]);
        if (!in_array($file_type, $allowed_types)) {
            $result['error'] = "Room photos must be JPG, PNG, or WEBP files.";
            return $result;
        }
        if ($files['size'][$i] > 5 * 1024 * 1024) {
            $result['error'] = "Each room photo must be under 5MB.";
            return $result;
        }
        $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
        $filename = 'room_' . uniqid() . '.' . strtolower($ext);
        if (move_uploaded_file($files['tmp_name'][$i], $upload_dir . $filename)) {
            $saved[] = $filename;
        }
    }

    if (!empty($saved)) {
        $result['primary'] = $saved[0];
        $result['gallery'] = array_slice($saved, 1);
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
    $tags        = htmlspecialchars(trim($_POST['tags'] ?? ''), ENT_QUOTES, 'UTF-8');
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
            $gallery_str    = implode(',', $upload['gallery']);
            try {
                $stmt = $conn->prepare("INSERT INTO rooms (room_name, price, total_units, description, image, gallery, capacity, badge, tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sdissssis", $room_name, $price, $total_units, $description, $image_filename, $gallery_str, $capacity, $badge, $tags);
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
    $price       = (float) $_POST['price'];
    $total_units = intval($_POST['total_units']);
    $capacity    = intval($_POST['capacity'] ?? 4);
    $badge       = htmlspecialchars(trim($_POST['badge'] ?? 'Available'), ENT_QUOTES, 'UTF-8');
    $tags        = htmlspecialchars(trim($_POST['tags'] ?? ''), ENT_QUOTES, 'UTF-8');
    $description = htmlspecialchars(strip_tags(trim($_POST['description'] ?? '')), ENT_QUOTES, 'UTF-8');

    if ($price <= 0 || $total_units < 1) {
        $error = "Please enter a valid price and unit count.";
    } elseif ($capacity < 1) {
        $error = "Please enter a valid guest capacity.";
    } else {
        $upload = handleRoomPhotoUploads($_FILES['images'] ?? ['name' => []]);
        if ($upload['error']) {
            $error = $upload['error'];
        } else {
            if ($upload['primary'] !== null) {
                $gallery_str = implode(',', $upload['gallery']);
                $stmt = $conn->prepare("UPDATE rooms SET price=?, total_units=?, description=?, image=?, gallery=?, capacity=?, badge=?, tags=? WHERE room_id=?");
                $stmt->bind_param("dissssisi", $price, $total_units, $description, $upload['primary'], $gallery_str, $capacity, $badge, $tags, $room_id);
            } else {
                $stmt = $conn->prepare("UPDATE rooms SET price=?, total_units=?, description=?, capacity=?, badge=?, tags=? WHERE room_id=?");
                $stmt->bind_param("disissi", $price, $total_units, $description, $capacity, $badge, $tags, $room_id);
            }
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_success'] = "Room updated successfully.";
            header("Location: admin_dashboard.php");
            exit();
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
$uc = $conn->query("SELECT booking_id, guest_name full_name, room_type, check_in, status
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

/* ── Rooms ──
   Two separate metrics, deliberately not conflated:
   - "Confirmed Today"  → admin activity: bookings confirmed/paid TODAY
                          (confirmed_at), regardless of stay dates.
   - "occupied_today"   → physical capacity: confirmed stays that actually
                          span today (check_in <= today < check_out), used
                          against total_units — this is the one that can
                          legitimately be "full". ── */
$rooms_list = [];
$rq = $conn->query("SELECT room_id, room_name, price, total_units, description, image, gallery, capacity, badge, tags FROM rooms ORDER BY room_name");
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

/* ── Bookings ──
   No user_id on bookings — guest_name is the guest's identity as entered
   on the booking form. ── */
$bookings = [];
$bq = $conn->query("SELECT booking_id, guest_name full_name, guest_email, id_type, id_photo, contact_number, room_type, check_in, check_out, guests, adults, children, status, created_at, confirmed_at, payment_method, payment_reference, COALESCE(total_price,0) total_price
    FROM bookings
    ORDER BY created_at DESC");
    while ($row = $bq->fetch_assoc()) {
    $row['status'] = strtolower(trim($row['status']));
    $bookings[] = $row;
}

/* ── Notifications ── */
$notifications = [];
$nq = $conn->query("SELECT 'booking' notif_type, booking_id, guest_name full_name, room_type, check_in, check_out, status, created_at
    FROM bookings
    ORDER BY created_at DESC LIMIT 15");
while ($row = $nq->fetch_assoc()) {
    $row['status'] = strtolower(trim($row['status']));
    $notifications[] = $row;
}

usort($notifications, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$notifications = array_slice($notifications, 0, 20);

$unread_count = array_reduce($notifications, function($c,$n) {
    if ($n['status']==='pending') return $c+1;
    return $c;
}, 0);

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

/* Simple user-agent → readable device/browser label */
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
function buildBookingConfirmationEmail($guest_name, $room_type, $check_in, $check_out, $guests, $total_price, $booking_id) {
    $ci = date('M j, Y', strtotime($check_in));
    $co = date('M j, Y', strtotime($check_out));
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
          <span style="display:inline-block;padding:4px 12px;border-radius:20px;background:#e8f5e9;color:#2e7d32;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:18px;">&#10003; Confirmed</span>

          <p style="font-size:14px;color:#1a1a2e;line-height:1.6;margin:14px 0;">
            Hi ' . htmlspecialchars($guest_name) . ',<br>
            Great news &mdash; your stay at <strong>CoraVergel Resort</strong> is officially confirmed. We can&rsquo;t wait to welcome you.
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
                  <div style="font-weight:600;color:#1a1a2e;">#' . intval($booking_id) . '</div>
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
          <div style="font-size:11px;color:#aaa;margin-top:4px;">21 Barosong, Tigbauan, Iloilo City, Philippines</div>
          <div style="font-size:11px;color:#aaa;">+320 2512 &middot; coravergelresort@gmail.com</div>
        </div>

      </div>
    </div>';
}

function fmtAdminDate($d) {
    return date('M j, Y', strtotime($d));
}

function human_time_diff($ts) {
    $d = time()-$ts;
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
<style>
.clickable-badge{cursor:pointer;transition:opacity .15s,transform .15s;}
.clickable-badge:hover{opacity:.8;transform:translateY(-1px);}
.tg-guest-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;background:#fafaf8;border:1px solid #f0ede8;border-radius:9px;cursor:pointer;transition:background .15s;}
.tg-guest-row:hover{background:#f0ede8;}
.tg-guest-info{display:flex;flex-direction:column;gap:2px;min-width:0;}
.tg-guest-name{font-size:13px;font-weight:600;color:#1a1a2e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.tg-guest-dates{font-size:11px;color:#aaa;}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
    <div class="sb-brand">
        <div class="sb-brand-text">
            <span class="sb-name">CoraVergel Resort</span>
            <span class="sb-sub">Admin Panel</span>
        </div>
    </div>
    <div class="sb-nav">
        <div class="sb-group-label">MAIN</div>
        <button class="sb-item active" onclick="showSection('overview',this)">
            <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
        </button>
        <div class="sb-group-label">MANAGEMENT</div>
        <button class="sb-item" onclick="showSection('bookings',this)">
            <i class="fa-solid fa-calendar-check"></i><span>Bookings</span>
            <?php if($pending_count>0): ?><span class="sb-badge"><?=$pending_count?></span><?php endif; ?>
        </button>
        <button class="sb-item" onclick="showSection('rooms',this)">
            <i class="fa-solid fa-bed"></i><span>Rooms</span>
        </button>
        <div class="sb-group-label">SECURITY</div>
        <button class="sb-item" onclick="showSection('activity',this)">
            <i class="fa-solid fa-shield-halved"></i><span>Login Activity</span>
        </button>
        <div class="sb-group-label">SITE</div>
        <a href="../user/dashboard.php" class="sb-item" target="_blank">
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

<!-- ══ MAIN ══ -->
<div class="main-wrap">

    <!-- Topbar -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle nav-hamburger" id="adminHamburger" onclick="toggleSidebar()" aria-label="Toggle menu">
                <span></span><span></span><span></span>
            </button>
            <div class="topbar-search" id="searchWrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" placeholder="Search"
                    id="globalSearch" oninput="globalSearchFn(this.value)"
                    onfocus="globalSearchFn(this.value)" autocomplete="off">
                <div class="search-dropdown" id="searchDropdown"></div>
            </div>
        </div>
        <div class="topbar-right">
            <div class="notif-wrap" id="notifWrap">
                <button class="notif-bell" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell"></i>
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
                            $ago       = human_time_diff(strtotime($n['created_at']));
                            $is_unread = $n['status']==='pending';
                            $icon      = $n['status']==='confirmed'?'fa-circle-check':($n['status']==='cancelled'?'fa-ban':'fa-clock');
                            $icon_cls  = $n['status']==='confirmed'?'ni--green':($n['status']==='cancelled'?'ni--red':'ni--gold');
                        ?>
                        <div class="notif-item <?=$is_unread?'notif-item--unread':''?>"
                             onclick="goToBooking(<?=$n['booking_id']?>)">
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
                                    <span><?=date('M d',strtotime($n['check_in']))?> → <?=date('M d',strtotime($n['check_out']))?></span>
                                </div>
                                <div class="ni-time"><?=$ago?></div>
                            </div>
                            <?php if($is_unread): ?><div class="ni-dot"></div><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="notif-panel-foot">
                        <button onclick="showSection('bookings',document.querySelectorAll('.sb-item')[1]);closeNotif();">
                            View all bookings <i class="fa-solid fa-arrow-right"></i>
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
        <div class="section-header">
            <div>
                <h1>Dashboard</h1>
                <p>Welcome back, <?=htmlspecialchars($admin_name)?>. Here's what's happening today.</p>
            </div>
            <div class="section-date"><i class="fa-regular fa-calendar"></i> <?=date('F j, Y')?></div>
        </div>

        <div class="quick-actions-bar">
            <span class="qa-label"><i class="fa-solid fa-bolt"></i> Quick actions</span>
            <button class="qa-action-btn qa-blue" onclick="showSection('bookings',document.querySelectorAll('.sb-item')[1])">
                <i class="fa-solid fa-calendar-check"></i> View All Bookings
            </button>
            <a href="../frontend/guest.php" target="_blank" class="qa-action-btn">
                <i class="fa-solid fa-globe"></i> View Website
            </a>
            <button class="qa-action-btn" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Print Report
            </button>
            <?php if($cancelled > 0): ?>
            <button class="qa-action-btn qa-red" onclick="filterByStatus('cancelled');showSection('bookings',document.querySelectorAll('.sb-item')[1])">
                <i class="fa-solid fa-ban"></i> View Cancelled (<?=$cancelled?>)
            </button>
            <?php endif; ?>
        </div>

        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#f0f4ff;color:#1a1a2e"><i class="fa-solid fa-calendar-days"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total Bookings</div>
                    <div class="kpi-value"><?=$total_bookings?></div>
                    <div class="kpi-change neutral"><i class="fa-solid fa-calendar"></i> All time</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#e8f5e9;color:#2e7d32"><i class="fa-solid fa-circle-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Confirmed</div>
                    <div class="kpi-value"><?=$confirmed?></div>
                    <div class="kpi-change <?=$confirmed>0?'up':'neutral'?>">
                        <i class="fa-solid fa-check"></i> <?=$total_bookings>0?round(($confirmed/$total_bookings)*100):0?>% of total
                    </div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#fff8e1;color:#e65100"><i class="fa-solid fa-clock"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pending</div>
                    <div class="kpi-value" style="color:<?=$pending_count>0?'#e65100':'#1a1a2e'?>"><?=$pending_count?></div>
                    <div class="kpi-change <?=$pending_count>0?'neutral':''?>">
                        <?php if($pending_count>0): ?><i class="fa-solid fa-triangle-exclamation"></i> Needs your action<?php else: ?>All clear<?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="charts-row-new">
            <div class="chart-card-new">
                <div class="chart-card-header-new">
                    <div>
                        <h3>Monthly Bookings<?=$total_revenue>0?' &amp; Revenue':''?></h3>
                        <p><?=date('Y')?> overview</p>
                    </div>
                    <div style="display:flex;gap:12px;align-items:center">
                        <span style="display:flex;align-items:center;gap:5px;font-size:11px;color:#aaa">
                            <span style="width:10px;height:10px;border-radius:2px;background:#16a34a;display:inline-block"></span>Bookings
                        </span>
                        <?php if($total_revenue > 0): ?>
                        <span style="display:flex;align-items:center;gap:5px;font-size:11px;color:#aaa">
                            <span style="width:10px;height:3px;background:#c8a96e;display:inline-block;border-top:2px dashed #c8a96e"></span>Revenue
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <canvas id="monthlyChart" height="120"></canvas>
            </div>
            <div class="chart-card-new">
                <div class="chart-card-header-new">
                    <div><h3>By Room Type</h3><p>Booking distribution</p></div>
                </div>
                <canvas id="roomChart" height="180"></canvas>
            </div>
        </div>

        <div class="bottom-grid">
            <div class="mini-cal-card">
                <div class="mc-header">
                    <div class="mc-title"><i class="fa-regular fa-calendar" style="color:#c8a96e;margin-right:6px"></i>Booking Calendar</div>
                    <div class="mc-nav">
                        <button class="mc-nav-btn" onclick="calPrev()"><i class="fa-solid fa-chevron-left"></i></button>
                        <span class="mc-month-label" id="calMonthLabel"><?=date('M Y')?></span>
                        <button class="mc-nav-btn" onclick="calNext()"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="mc-body">
                    <div class="mc-grid" id="calGrid">
                        <?php foreach(['Su','Mo','Tu','We','Th','Fr','Sa'] as $d): ?>
                        <div class="mc-day-head"><?=$d?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mc-legend">
                    <div class="mc-legend-item"><span class="mc-legend-dot" style="background:#1a1a2e"></span>Today</div>
                    <div class="mc-legend-item"><span class="mc-legend-dot" style="background:#c8a96e"></span>Has booking</div>
                    <div class="mc-legend-item"><span class="mc-legend-dot" style="background:#ff9800;border-radius:2px;width:9px;height:9px"></span>Busy (3+)</div>
                </div>
                <?php if(!empty($upcoming_checkins)): ?>
                <div class="mc-upcoming">
                    <div class="mc-upcoming-title">Upcoming check-ins</div>
                    <?php foreach($upcoming_checkins as $ci): ?>
                    <div class="mc-checkin-item">
                        <span class="mc-checkin-date"><?=date('M d',strtotime($ci['check_in']))?></span>
                        <span class="mc-checkin-guest"><?=htmlspecialchars($ci['full_name'])?> · <?=htmlspecialchars($ci['room_type'])?></span>
                        <span class="status-pill sp-<?=$ci['status']?>"><?=ucfirst($ci['status'])?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div style="padding:12px 16px;font-size:12px;color:#bbb;text-align:center">No check-ins in the next 7 days</div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- BOOKINGS -->
    <section class="dash-section" id="section-bookings">
        <div class="section-header">
            <div><h1>Bookings</h1><p>Manage all resort reservations</p></div>
        </div>

<div class="filter-toggle-wrap" id="filterToggleWrap">
    <button class="filter-toggle-btn" id="filterToggleBtn" onclick="toggleFilterDropdown()">
        <div class="ftb-icon"><i class="fa-solid fa-sliders"></i></div>
        <span class="ftb-label">Filter bookings</span>
        <span class="ftb-active-pill" id="ftbActivePill">
            <i class="fa-solid fa-circle" style="font-size:7px"></i> All
        </span>
        <i class="fa-solid fa-chevron-down ftb-chevron"></i>
    </button>
    <div class="filter-dropdown" id="filterDropdown">
        <div class="fd-header"><i class="fa-solid fa-filter" style="margin-right:5px"></i>Filter by status</div>
        <div class="fd-item fd-active" id="fd-all" onclick="selectFilter('all','All',this)">
            <div class="fd-item-left">
                <span class="fd-dot fd-dot--all"></span>
                <span class="fd-item-label">All bookings</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
            </div>
        </div>
        <div class="fd-item" id="fd-pending" onclick="selectFilter('pending','Pending',this)">
            <div class="fd-item-left">
                <span class="fd-dot fd-dot--pending"></span>
                <span class="fd-item-label">Pending</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
            </div>
        </div>
        <div class="fd-item" id="fd-confirmed" onclick="selectFilter('confirmed','Confirmed',this)">
            <div class="fd-item-left">
                <span class="fd-dot fd-dot--confirmed"></span>
                <span class="fd-item-label">Confirmed</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
            </div>
        </div>
        <div class="fd-item" id="fd-cancelled" onclick="selectFilter('cancelled','Cancelled',this)">
            <div class="fd-item-left">
                <span class="fd-dot fd-dot--cancelled"></span>
                <span class="fd-item-label">Cancelled</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
            </div>
        </div>
    </div>
</div>
        <div class="table-card">
            <div class="table-card-head">
                <div><h3>All Bookings</h3><p id="bookingCount"><?=count($bookings)?> total</p></div>
<div class="table-controls">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" placeholder="Search guest or room..." id="bookingSearch" oninput="filterBookings()">
    </div>
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
                        <tr><th>#</th><th>Guest</th><th>Room Type</th><th>Check-In</th><th>Check-Out</th><th>Nights</th><th>Guests</th><th>Status</th><th>Booked</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($bookings)): ?>
                        <tr><td colspan="10" class="empty-cell">No bookings found.</td></tr>
                        <?php else: foreach($bookings as $i => $b):
                            $nights = (new DateTime($b['check_in']))->diff(new DateTime($b['check_out']))->days;
                        ?>
                        <tr class="b-row"
                            data-bid="<?=$b['booking_id']?>"
                            data-name="<?=strtolower(htmlspecialchars($b['full_name']))?>"
                            data-room="<?=strtolower(htmlspecialchars($b['room_type']))?>"
                            data-status="<?=$b['status']?>"
                            data-checkin="<?=$b['check_in']?>"
                            data-checkout="<?=$b['check_out']?>">
                            <td class="row-num"><?=$i+1?></td>
                            <td>
                                <div class="guest-cell">
                                    <div class="guest-avatar"><?=strtoupper(substr($b['full_name'],0,1))?></div>
                                    <div>
                                        <div class="guest-name"><?=htmlspecialchars($b['full_name'])?></div>
                                        <div class="guest-booked">Booked <?=date('M d',strtotime($b['created_at']))?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="tag tag--room"><?=htmlspecialchars($b['room_type'])?></span></td>
                            <td><?=date('M d, Y',strtotime($b['check_in']))?></td>
                            <td><?=date('M d, Y',strtotime($b['check_out']))?></td>
                            <td><?=$nights?> night<?=$nights!=1?'s':''?></td>
                            <td><?=$b['guests']?></td>
                            <td>
                                <?php if($b['status']==='confirmed'): ?>
                                    <span class="status-badge status--confirmed"><i class="fa-solid fa-circle-check"></i> Confirmed</span>
                                <?php elseif($b['status']==='cancelled'): ?>
                                    <span class="status-badge status--cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>
                                <?php else: ?>
                                    <span class="status-badge status--pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                <?php endif; ?>
                            </td>
                            <td><?=date('M d, Y',strtotime($b['created_at']))?></td>
<?php
    $bm_name     = htmlspecialchars($b['full_name'],ENT_QUOTES);
    $bm_room     = htmlspecialchars($b['room_type'],ENT_QUOTES);
    $bm_checkin  = date('M d, Y',strtotime($b['check_in']));
    $bm_checkout = date('M d, Y',strtotime($b['check_out']));
    $bm_booked   = date('M d, Y',strtotime($b['created_at']));
    $bm_email    = htmlspecialchars($b['guest_email'] ?? '', ENT_QUOTES);
    $bm_idtype   = htmlspecialchars($b['id_type'] ?? '', ENT_QUOTES);
    $bm_contact  = htmlspecialchars($b['contact_number'] ?? '', ENT_QUOTES);
    $bm_idphoto  = !empty($b['id_photo']) ? '../assets/uploads/ids/' . htmlspecialchars($b['id_photo'], ENT_QUOTES) : '';
    $bm_adults   = (int) ($b['adults'] ?? 0);
    $bm_children = (int) ($b['children'] ?? 0);
    $bm_paymethod = htmlspecialchars($b['payment_method'] ?? '', ENT_QUOTES);
    $bm_payref    = htmlspecialchars($b['payment_reference'] ?? '', ENT_QUOTES);
    $bm_view_call = "openBookingModal('$bm_name','$bm_room','$bm_checkin','$bm_checkout',$nights,{$b['guests']},'$bm_booked','$bm_email','$bm_idtype','$bm_contact','{$b['status']}','$bm_idphoto',$bm_adults,$bm_children,'$bm_paymethod','$bm_payref')";
?>
                            <td>
                                <?php if($b['status']==='pending'): ?>
                                <div class="action-menu" id="am-<?=$b['booking_id']?>">
                                    <button class="action-btn" onclick="toggleMenu(<?=$b['booking_id']?>,event)">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>
                                    <div class="action-dropdown">
                                        <button type="button" class="ad-item ad-view" onclick="<?=$bm_view_call?>;document.getElementById('am-<?=$b['booking_id']?>').classList.remove('open')">
                                            <i class="fa-solid fa-id-card"></i> View Details
                                        </button>
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
                                <?php elseif($b['status']==='confirmed'): ?>
                                <button class="btn-view-info" onclick="<?=$bm_view_call?>">
                                    <i class="fa-solid fa-circle-info"></i> View
                                </button>
                                <?php else: ?>
                                <button class="btn-view-info" style="border-color:#dc2626;background:#fff5f5;color:#dc2626;" onclick="<?=$bm_view_call?>">
                                    <i class="fa-solid fa-ban"></i> Cancelled — View
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                <div class="empty-state" id="noBookings" style="display:none;">
                    <i class="fa-regular fa-calendar-xmark"></i>
                    <p>No bookings match your filters.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ROOMS -->
    <section class="dash-section" id="section-rooms">
        <div class="section-header">
            <div><h1>Rooms</h1><p>Manage room types, pricing, and unit inventory</p></div>
            <button class="qa-action-btn qa-blue" onclick="openAddRoomModal()">
                <i class="fa-solid fa-plus"></i> Add Room Type
            </button>
        </div>

        <div class="table-card">
            <div class="table-card-head">
                <div><h3>Room Inventory</h3><p><?=count($rooms_list)?> room types</p></div>
            </div>
            <div class="table-wrap">
                <table id="roomsTable">
                    <thead>
                        <tr><th>#</th><th>Photo</th><th>Room Name</th><th>Price / night</th><th>Units</th><th>Confirmed Today</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($rooms_list)): ?>
                        <tr><td colspan="7" class="empty-cell">No room types yet. Add one to get started.</td></tr>
                        <?php else: foreach($rooms_list as $i => $rm): ?>
                        <tr>
                            <td class="row-num"><?=$i+1?></td>
                            <td>
                                <?php if(!empty($rm['image'])): ?>
                                <img src="../assets/images/rooms/<?=htmlspecialchars($rm['image'])?>" alt=""
                                     style="width:44px;height:44px;object-fit:cover;border-radius:8px;">
                                <?php else: ?>
                                <div style="width:44px;height:44px;border-radius:8px;background:#f0ede8;display:flex;align-items:center;justify-content:center;color:#bbb;">
                                    <i class="fa-solid fa-image"></i>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="tag tag--room"><?=htmlspecialchars($rm['room_name'])?></span></td>
                            <td>₱<?=number_format($rm['price'],2)?></td>
                            <td>
                                <?php if($rm['occupied_today'] >= $rm['total_units'] && $rm['occupied_today'] > 0): ?>
                                    <span class="status-badge status--cancelled"><?=$rm['occupied_today']?>/<?=$rm['total_units']?></span>
                                <?php else: ?>
                                    <span class="status-badge status--confirmed"><?=$rm['occupied_today']?>/<?=$rm['total_units']?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($rm['confirmed_today'] > 0): ?>
                                    <span class="status-badge status--confirmed clickable-badge" onclick="showTodayGuests('<?=htmlspecialchars($rm['room_name'],ENT_QUOTES)?>')" title="Click to see who was confirmed today">
                                        <i class="fa-solid fa-circle-check"></i> <?=$rm['confirmed_today']?> confirmed today
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge clickable-badge" style="background:#f5f5f5;color:#aaa;" onclick="showTodayGuests('<?=htmlspecialchars($rm['room_name'],ENT_QUOTES)?>')" title="Click to see today's activity">
                                        No confirmations yet
                                    </span>
                                <?php endif; ?>
                                <?php if($rm['pending_today'] > 0): ?>
                                <div style="font-size:10px;color:#e65100;margin-top:3px;">
                                    <i class="fa-solid fa-clock" style="font-size:9px;"></i> +<?=$rm['pending_today']?> new pending today
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn-view-info" onclick="openEditRoomModal(
                                    <?=$rm['room_id']?>,
                                    '<?=htmlspecialchars($rm['room_name'],ENT_QUOTES)?>',
                                    <?=$rm['price']?>,
                                    <?=$rm['total_units']?>,
                                    '<?=htmlspecialchars($rm['description'] ?? '', ENT_QUOTES)?>',
                                    <?=!empty($rm['image']) ? "'../assets/images/rooms/".htmlspecialchars($rm['image'],ENT_QUOTES)."'" : 'null'?>,
                                    <?=json_encode(!empty($rm['gallery']) ? array_map(fn($g)=>'../assets/images/rooms/'.$g, array_filter(explode(',', $rm['gallery']))) : [])?>,
                                    <?=(int)($rm['capacity'] ?? 4)?>,
                                    '<?=htmlspecialchars($rm['badge'] ?? 'Available', ENT_QUOTES)?>',
                                    '<?=htmlspecialchars($rm['tags'] ?? '', ENT_QUOTES)?>'
                                )">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </button>
                                <form method="POST" action="admin_dashboard.php" onsubmit="return confirm('Delete this room type? Existing bookings for it will remain in history but no new bookings can be made for it.')" style="display:inline-block;margin-left:6px;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="delete_room" value="<?=$rm['room_id']?>">
                                    <button type="submit" class="btn-view-info" style="border-color:#dc2626;background:#fff5f5;color:#dc2626;">
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- LOGIN ACTIVITY -->
    <section class="dash-section" id="section-activity">
        <div class="section-header">
            <div><h1>Login Activity</h1><p>Every time an admin has signed in to this dashboard</p></div>
        </div>

        <div class="kpi-row" style="grid-template-columns:repeat(3,1fr);">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#f0f4ff;color:#1a1a2e"><i class="fa-solid fa-right-to-bracket"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total Logins</div>
                    <div class="kpi-value"><?=$total_logins?></div>
                    <div class="kpi-change neutral"><i class="fa-solid fa-clock-rotate-left"></i> Last 100 shown</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#e8f5e9;color:#2e7d32"><i class="fa-solid fa-user-clock"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Last Login</div>
                    <div class="kpi-value" style="font-size:15px;"><?= $last_login ? date('M d, g:i A', strtotime($last_login)) : '—' ?></div>
                    <div class="kpi-change neutral"><?= $last_login ? human_time_diff(strtotime($last_login)) : 'No logins yet' ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#fff8e1;color:#e65100"><i class="fa-solid fa-shield-halved"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Via Remembered Device</div>
                    <div class="kpi-value"><?=$remembered_logins?></div>
                    <div class="kpi-change neutral">Skipped OTP</div>
                </div>
            </div>
        </div>

        <div class="table-card">
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
                                    <span class="status-badge status--confirmed"><i class="fa-solid fa-shield-halved"></i> Remembered device</span>
                                <?php else: ?>
                                    <span class="status-badge status--pending"><i class="fa-solid fa-key"></i> OTP verified</span>
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
    </section>

</div><!-- /main-wrap -->

<!-- BOOKING INFO MODAL -->
<div class="bmodal-overlay" id="bookingModal" onclick="closeBookingModal()">
    <div class="bmodal-box" onclick="event.stopPropagation()">
        <div class="bmodal-top">
            <div class="bmodal-top-row">
                <span class="bmodal-label">Reservation details</span>
                <button class="bmodal-close" onclick="closeBookingModal()">✕</button>
            </div>
            <div class="bmodal-identity">
                <div class="bmodal-icon" id="bm-icon">✓</div>
                <div>
                    <div class="bmodal-title" id="bm-title">Booking confirmed</div>
                    <div class="bmodal-guest">Guest — <span id="bm-name"></span></div>
                    <div class="bmodal-conf-badge" id="bm-status-badge">✓ Confirmed reservation</div>
                </div>
            </div>
        </div>
        <div class="bmodal-body">
            <div class="bmodal-fields">
                <div class="bmodal-field">
                    <div class="bmodal-field-lbl">Room type</div>
                    <div class="bmodal-field-val" id="bm-room"></div>
                </div>
                <div class="bmodal-field">
                    <div class="bmodal-field-lbl">Check-in</div>
                    <div class="bmodal-field-val" id="bm-checkin"></div>
                </div>
                <div class="bmodal-field">
                    <div class="bmodal-field-lbl">Check-out</div>
                    <div class="bmodal-field-val" id="bm-checkout"></div>
                </div>
            </div>

<div class="bmodal-section-lbl" style="font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#aaa;margin:18px 0 10px;">Guest &amp; verification details</div>

<div style="display:grid;grid-template-columns:1fr 140px;gap:16px;align-items:start;">

    <!-- Left: text fields stacked -->
    <div style="display:flex;flex-direction:column;gap:8px;">
        <div class="bmodal-field">
            <div class="bmodal-field-lbl">Email</div>
            <div class="bmodal-field-val" id="bm-email" style="font-size:13px;"></div>
        </div>
        <div class="bmodal-field">
            <div class="bmodal-field-lbl">Contact number</div>
            <div class="bmodal-field-val" id="bm-contact"></div>
        </div>
        <div class="bmodal-field">
            <div class="bmodal-field-lbl">ID Type</div>
            <div class="bmodal-field-val" id="bm-idtype"></div>
        </div>
        <div class="bmodal-field">
            <div class="bmodal-field-lbl">Booked on</div>
            <div class="bmodal-field-val" id="bm-booked" style="font-size:13px;"></div>
        </div>
        <div class="bmodal-field">
            <div class="bmodal-field-lbl">Payment method</div>
            <div class="bmodal-field-val" id="bm-paymethod"></div>
        </div>
        <div class="bmodal-field" id="bm-payref-field" style="display:none;">
            <div class="bmodal-field-lbl">Payment reference</div>
            <div class="bmodal-field-val" id="bm-payref" style="font-size:13px;"></div>
        </div>
    </div>

    <!-- Right: small ID photo thumbnail -->
    <div>
        <div class="bmodal-field-lbl" style="margin-bottom:6px;">ID Photo</div>
        <div id="bm-idphoto-wrap" style="display:none;width:140px;height:140px;border-radius:10px;overflow:hidden;border:1px solid #f0ede8;cursor:pointer;" onclick="openIdPhotoLightbox(document.getElementById('bm-idphoto').src)">
            <img id="bm-idphoto" src="" style="width:100%;height:100%;object-fit:cover;">
        </div>
        <div id="bm-idphoto-empty" style="display:none;width:140px;height:140px;border-radius:10px;background:#fafaf8;border:1px dashed #e0dbd0;align-items:center;justify-content:center;font-size:11px;color:#bbb;text-align:center;padding:8px;">No ID photo uploaded</div>
    </div>

</div>

<div class="bmodal-divider" style="margin-top:16px;"></div>

            <div class="bmodal-summary">
                <div class="bmodal-summary-item">
                    <div class="bmodal-summary-lbl">Nights</div>
                    <div class="bmodal-summary-val" id="bm-nights"></div>
                </div>
                <div class="bmodal-summary-sep"></div>
                <div class="bmodal-summary-item">
                    <div class="bmodal-summary-lbl">Guests</div>
                    <div class="bmodal-summary-val" id="bm-guests"></div>
                </div>
                <div class="bmodal-summary-sep"></div>
                <div class="bmodal-summary-item">
                    <div class="bmodal-summary-lbl">Status</div>
                    <div class="bmodal-summary-val" id="bm-status-text" style="font-size:13px;color:#16a34a;">Confirmed</div>
                </div>
            </div>
        </div>
        <div class="bmodal-footer">
            <button class="bmodal-close-btn" onclick="closeBookingModal()">Close</button>
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

<!-- ADD ROOM MODAL -->
<div class="room-modal-overlay" id="addRoomModal" onclick="if(event.target===this)closeAddRoomModal()">
    <div class="room-modal-box" onclick="event.stopPropagation()">
        <div class="room-modal-top">
            <div class="room-modal-top-row">
                <span class="room-modal-eyebrow">New Room Type</span>
                <button class="room-modal-close" onclick="closeAddRoomModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="room-modal-identity">
                <div class="room-modal-icon"><i class="fa-solid fa-bed"></i></div>
                <div>
                    <div class="room-modal-title">Add Room Type</div>
                    <div class="room-modal-sub">Create a new room category for guests to book</div>
                </div>
            </div>
        </div>
<form method="POST" action="admin_dashboard.php" enctype="multipart/form-data" id="addRoomForm">
    <input type="hidden" name="action" value="add_room">
    <?= csrfField() ?>
    <div class="room-modal-body">
        <div class="room-field">
            <div class="room-field-lbl">Room Name</div>
            <input type="text" name="room_name" required placeholder="e.g. Deluxe Room">
        </div>

        <div class="room-fields-grid cols-3">
            <div class="room-field">
                <div class="room-field-lbl">Price / night (₱)</div>
                <input type="number" name="price" step="0.01" min="1" required>
            </div>
            <div class="room-field">
                <div class="room-field-lbl">Total Units</div>
                <input type="number" name="total_units" min="1" required>
            </div>
            <div class="room-field">
                <div class="room-field-lbl">Capacity (pax)</div>
                <input type="number" name="capacity" min="1" value="4" required>
            </div>
        </div>

        <div class="room-fields-grid cols-2">
            <div class="room-field">
                <div class="room-field-lbl">Badge</div>
                <select name="badge">
                    <option>Available</option>
                    <option>Popular</option>
                    <option>New</option>
                    <option>Limited</option>
                </select>
            </div>
            <div class="room-field">
                <div class="room-field-lbl">Tags (comma-separated)</div>
                <input type="text" name="tags" placeholder="Free Entrance, Balcony, Air Conditioned">
            </div>
        </div>

        <div class="room-field">
            <div class="room-field-lbl">Description</div>
            <textarea name="description" rows="3" placeholder="Short description guests will see on the booking page..."></textarea>
        </div>

        <div class="room-section-lbl">Room Photos</div>
        <div class="room-photo-zone" id="addPhotoDropZone" onclick="document.getElementById('addRoomFileInput').click()">
            <div class="rpz-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
            <div class="rpz-title">Click to upload photos</div>
            <div class="rpz-sub">JPG, PNG, or WEBP · max 5MB each · up to 5 photos</div>
        </div>
        <input type="file" id="addRoomFileInput" name="images[]" accept="image/jpeg,image/png,image/webp"
            multiple style="display:none;" onchange="handlePhotoSelect(this,'add')">
        <div class="room-photo-grid" id="addPhotoThumbs"></div>
        <div class="room-photo-count" id="addPhotoCount"></div>
    </div>
    <div class="room-modal-footer">
        <button type="button" class="room-btn room-btn-ghost" onclick="closeAddRoomModal()">Cancel</button>
        <button type="submit" class="room-btn room-btn-gold"><i class="fa-solid fa-plus"></i> Add Room</button>
    </div>
</form>
    </div>
</div>

<!-- EDIT ROOM MODAL -->
<div class="room-modal-overlay" id="editRoomModal" onclick="if(event.target===this)closeEditRoomModal()">
    <div class="room-modal-box" onclick="event.stopPropagation()">
        <div class="room-modal-top">
            <div class="room-modal-top-row">
                <span class="room-modal-eyebrow">Edit Room</span>
                <button class="room-modal-close" onclick="closeEditRoomModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="room-modal-identity">
                <div class="room-modal-icon"><i class="fa-solid fa-bed"></i></div>
                <div>
                    <div class="room-modal-title" id="er-name">—</div>
                    <div class="room-modal-sub">Update pricing, inventory, and photos</div>
                </div>
            </div>
        </div>
<form method="POST" action="admin_dashboard.php" enctype="multipart/form-data" id="editRoomForm">
    <input type="hidden" name="action" value="update_room">
    <input type="hidden" name="room_id" id="er-room-id">
    <?= csrfField() ?>
    <div class="room-modal-body">
        <div class="room-fields-grid cols-3">
            <div class="room-field">
                <div class="room-field-lbl">Price / night (₱)</div>
                <input type="number" name="price" id="er-price" step="0.01" min="1" required>
            </div>
            <div class="room-field">
                <div class="room-field-lbl">Total Units</div>
                <input type="number" name="total_units" id="er-units" min="1" required>
            </div>
            <div class="room-field">
                <div class="room-field-lbl">Capacity (pax)</div>
                <input type="number" name="capacity" id="er-capacity" min="1" required>
            </div>
        </div>

        <div class="room-fields-grid cols-2">
            <div class="room-field">
                <div class="room-field-lbl">Badge</div>
                <select name="badge" id="er-badge">
                    <option>Available</option>
                    <option>Popular</option>
                    <option>New</option>
                    <option>Limited</option>
                </select>
            </div>
            <div class="room-field">
                <div class="room-field-lbl">Tags (comma-separated)</div>
                <input type="text" name="tags" id="er-tags" placeholder="Free Entrance, Balcony, Air Conditioned">
            </div>
        </div>

        <div class="room-field">
            <div class="room-field-lbl">Description</div>
            <textarea name="description" id="er-description" rows="3"></textarea>
        </div>

        <div class="room-section-lbl">Current Photos</div>
        <div class="room-photo-grid" id="er-existing-photos"></div>
        <div class="room-photo-empty" id="er-no-photos">No photos uploaded yet.</div>

        <div class="room-photo-zone" id="editPhotoDropZone" style="margin-top:12px;" onclick="document.getElementById('editRoomFileInput').click()">
            <div class="rpz-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
            <div class="rpz-title">Upload new photos</div>
            <div class="rpz-sub">Uploading replaces all current photos · up to 5</div>
        </div>
        <input type="file" id="editRoomFileInput" name="images[]" accept="image/jpeg,image/png,image/webp"
            multiple style="display:none;" onchange="handlePhotoSelect(this,'edit')">
        <div class="room-photo-grid" id="editPhotoThumbs"></div>
        <div class="room-photo-count" id="editPhotoCount"></div>

        <div class="room-modal-hint">Room name can't be changed here — delete and re-add if you need to rename it.</div>
    </div>
    <div class="room-modal-footer">
        <button type="button" class="room-btn room-btn-ghost" onclick="closeEditRoomModal()">Cancel</button>
        <button type="submit" class="room-btn room-btn-gold"><i class="fa-solid fa-check"></i> Save Changes</button>
    </div>
</form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
function showSection(name,el){
    document.querySelectorAll('.dash-section').forEach(s=>s.classList.remove('active'));
    document.querySelectorAll('.sb-item').forEach(n=>n.classList.remove('active'));
    document.getElementById('section-'+name).classList.add('active');
    if(el) el.classList.add('active');
    localStorage.setItem('adminActiveSection', name);
}
function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('adminHamburger').classList.toggle('open');
}

const monthlyLabels = <?=json_encode(array_column($monthly_stats,'month'))?:'\[\]'?>;
const monthlyBookings = <?=json_encode(array_column($monthly_stats,'total'))?:'\[\]'?>;
const monthlyRevenue = <?=json_encode(array_column($monthly_stats,'revenue'))?:'\[\]'?>;
const hasRevenue = monthlyRevenue.some(v=>v>0);

const monthlyDatasets = [{
    type:'bar', label:'Bookings', data:monthlyBookings,
    backgroundColor:'rgba(54, 162, 235, 0.75)', borderRadius:5, yAxisID:'y'
}];
if(hasRevenue){
    monthlyDatasets.push({
        type:'line', label:'Revenue (₱)', data:monthlyRevenue,
        borderColor:'rgb(75, 192, 192)', backgroundColor:'rgba(75, 192, 192, 0.15)',
        borderWidth:2.5, pointBackgroundColor:'rgb(75, 192, 192)',
        pointBorderColor:'#fff', pointBorderWidth:2, pointRadius:5,
        fill:true, tension:0.4, yAxisID:'y1'
    });
}

new Chart(document.getElementById('monthlyChart'),{
    data:{labels:monthlyLabels, datasets:monthlyDatasets},
    options:{
        responsive:true,
        plugins:{legend:{display:false}},
        scales:{
            y:{beginAtZero:true,ticks:{stepSize:1,color:'#aaa',font:{size:11}},grid:{color:'#f5f5f5'},title:{display:true,text:'Bookings',color:'#aaa',font:{size:10}}},
            y1:hasRevenue?{position:'right',beginAtZero:true,ticks:{color:'rgb(75, 192, 192)',font:{size:10},callback:v=>'₱'+v.toLocaleString()},grid:{display:false}}:{display:false},
            x:{grid:{display:false},ticks:{color:'#aaa',font:{size:11}}}
        }
    }
});

new Chart(document.getElementById('roomChart'),{
    type:'doughnut',
    data:{
        labels:<?=json_encode(array_column($room_stats,'room_type'))?:'\[\]'?>,
        datasets:[{
            data:<?=json_encode(array_column($room_stats,'total'))?:'\[\]'?>,
            backgroundColor:['rgb(54, 162, 235)','rgb(255, 99, 132)','rgb(255, 205, 86)','rgb(75, 192, 192)','rgb(153, 102, 255)','rgb(255, 159, 64)'],
            borderWidth:0, hoverOffset:8
        }]
    },
    options:{responsive:true,plugins:{legend:{display:true,position:'bottom',labels:{font:{size:11},color:'#555',padding:14,boxWidth:12}}},cutout:'68%'}
});

const calBookings = <?=json_encode($cal_bookings)?>;
let calYear=<?=date('Y')?>, calMonth=<?=date('n')-1?>;
const monthNames=['January','February','March','April','May','June','July','August','September','October','November','December'];

function renderCal(){
    document.getElementById('calMonthLabel').textContent=monthNames[calMonth].substring(0,3)+' '+calYear;
    const grid=document.getElementById('calGrid');
    const existing=grid.querySelectorAll('.mc-day,.mc-day-empty');
    existing.forEach(e=>e.remove());
    const first=new Date(calYear,calMonth,1).getDay();
    const days=new Date(calYear,calMonth+1,0).getDate();
    const today=new Date();
    for(let i=0;i<first;i++){
        const e=document.createElement('div');
        e.className='mc-day other-month';e.textContent='';grid.appendChild(e);
    }
    for(let d=1;d<=days;d++){
        const el=document.createElement('div');
        el.className='mc-day';el.textContent=d;
        const isToday=today.getFullYear()===calYear&&today.getMonth()===calMonth&&today.getDate()===d;
        if(isToday) el.classList.add('today');
        const cnt=calBookings[d]||0;
        if(cnt>0) el.classList.add('has-booking');
        if(cnt>=3) el.classList.add('busy');
        grid.appendChild(el);
    }
}
function calPrev(){calMonth--;if(calMonth<0){calMonth=11;calYear--;}renderCal();}
function calNext(){calMonth++;if(calMonth>11){calMonth=0;calYear++;}renderCal();}
renderCal();

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
                    document.getElementById('dateFilterLabel').textContent=fmt(d[0])+' → '+fmt(d[1]);
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

let currentStatus='all', currentSearch='', dfpFrom='', dfpTo='';

function toggleFilterDropdown(){
    const btn=document.getElementById('filterToggleBtn');
    const dd=document.getElementById('filterDropdown');
    btn.classList.toggle('open');
    dd.classList.toggle('open');
}
function selectFilter(status,label,el){
    currentStatus=status;
    document.querySelectorAll('.fd-item').forEach(i=>i.classList.remove('fd-active'));
    el.classList.add('fd-active');
    document.getElementById('ftbActivePill').innerHTML=`<i class="fa-solid fa-circle" style="font-size:7px"></i> ${label}`;
    document.getElementById('filterToggleBtn').classList.remove('open');
    document.getElementById('filterDropdown').classList.remove('open');
    applyFilters();
}
function filterByStatus(status){ selectFilter(status,status.charAt(0).toUpperCase()+status.slice(1),document.getElementById('fd-'+status)); }
function filterBookings(){ currentSearch=document.getElementById('bookingSearch').value.trim().toLowerCase(); applyFilters(); }
function applyFilters(){
    const rows=document.querySelectorAll('.b-row'); let visible=0;
    rows.forEach(row=>{
        const ms=currentStatus==='all'||row.dataset.status===currentStatus;
        const mq=!currentSearch||row.dataset.name.includes(currentSearch)||row.dataset.room.includes(currentSearch);
        const md=(!dfpFrom&&!dfpTo)||(!dfpTo&&row.dataset.checkin>=dfpFrom)||(!dfpFrom&&row.dataset.checkout<=dfpTo)||(dfpFrom&&dfpTo&&row.dataset.checkin<=dfpTo&&row.dataset.checkout>=dfpFrom);
        const show=ms&&mq&&md; row.style.display=show?'':'none'; if(show) visible++;
    });
    document.getElementById('bookingCount').textContent=visible+' result'+(visible!==1?'s':'');
    document.getElementById('noBookings').style.display=visible===0?'':'none';
    document.getElementById('bookingsTable').style.display=visible===0?'none':'';
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

const bookingData=<?=json_encode(array_map(fn($b)=>['id'=>$b['booking_id'],'name'=>$b['full_name'],'room'=>$b['room_type'],'checkin'=>$b['check_in'],'checkout'=>$b['check_out'],'status'=>$b['status'],'confirmedAt'=>$b['confirmed_at'],'createdAt'=>$b['created_at']],$bookings))?>;
function highlight(t,q){ if(!q) return escapeHtml(t); const e=q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); return escapeHtml(t).replace(new RegExp(`(${e})`,'gi'),'<mark>$1</mark>'); }
function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function globalSearchFn(q){
    const query=q.trim().toLowerCase(); const dd=document.getElementById('searchDropdown');
    if(!query){ dd.classList.remove('open'); dd.innerHTML=''; return; }
    const mb=bookingData.filter(b=>b.name.toLowerCase().includes(query)||b.room.toLowerCase().includes(query)).slice(0,5);
    if(!mb.length){ dd.innerHTML=`<div class="sd-empty"><i class="fa-regular fa-face-frown"></i>No results for "<strong>${escapeHtml(q)}</strong>"</div>`; dd.classList.add('open'); return; }
    let html='';
    html+=`<div class="sd-section-label"><i class="fa-solid fa-calendar-check"></i> Bookings</div>`;
    mb.forEach(b=>{ const ci=new Date(b.checkin).toLocaleDateString('en-US',{month:'short',day:'numeric'}); const co=new Date(b.checkout).toLocaleDateString('en-US',{month:'short',day:'numeric'}); html+=`<div class="sd-item" onclick="goToBooking(${b.id})"><div class="sd-avatar sd-avatar--booking"><i class="fa-solid fa-calendar"></i></div><div class="sd-body"><div class="sd-title">${highlight(b.name,q)}</div><div class="sd-meta">${highlight(b.room,q)} · ${ci} → ${co}</div></div><span class="sd-badge sd-badge--${b.status}">${b.status}</span></div>`; });
    html+=`<div class="sd-footer"><i class="fa-solid fa-magnifying-glass"></i> Showing top results</div>`;
    dd.innerHTML=html; dd.classList.add('open');
}
function goToBooking(id){ closeSearchDropdown(); showSection('bookings',document.querySelectorAll('.sb-item')[1]); setTimeout(()=>{ document.querySelectorAll('.b-row').forEach(r=>r.classList.remove('row-highlight')); const t=document.querySelector(`[data-bid="${id}"]`); if(t){ t.classList.add('row-highlight'); t.scrollIntoView({behavior:'smooth',block:'center'}); } },150); }
function closeSearchDropdown(){ document.getElementById('searchDropdown').classList.remove('open'); document.getElementById('globalSearch').value=''; }

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
            const pillIcon  = isConfirmed ? 'fa-circle-check' : 'fa-clock';
            return `<div class="tg-guest-row" onclick="closeTodayGuestsModal();goToBooking(${b.id})">
                <div class="tg-guest-info">
                    <span class="tg-guest-name">${escapeHtml(b.name)}</span>
                    <span class="tg-guest-dates">${actionLabelFn(b)} · Stay: ${fmt(new Date(b.checkin))} → ${fmt(new Date(b.checkout))}</span>
                </div>
                <span class="status-badge ${pillClass}"><i class="fa-solid ${pillIcon}"></i> ${b.status.charAt(0).toUpperCase()+b.status.slice(1)}</span>
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

function toggleMenu(id, e) {
    e.stopPropagation();
    const wrap = document.getElementById('am-' + id);
    const isOpen = wrap.classList.contains('open');
    document.querySelectorAll('.action-menu.open').forEach(m => m.classList.remove('open'));
    if (!isOpen) { wrap.classList.add('open'); positionDrop(wrap); }
}

function positionDrop(wrap) {
    const btn = wrap.querySelector('.action-btn');
    const drop = wrap.querySelector('.action-dropdown');
    const rect = btn.getBoundingClientRect();
    const dropHeight = drop.offsetHeight || 140; // fallback before first paint

    let top = rect.bottom + 6;
    // If it would overflow past the bottom of the viewport, flip it above the button instead
    if (top + dropHeight > window.innerHeight - 10) {
        top = rect.top - dropHeight - 6;
    }

    drop.style.top = top + 'px';
    drop.style.left = 'auto';
    drop.style.right = (window.innerWidth - rect.right) + 'px';
}

function openBookingModal(name,room,checkin,checkout,nights,guests,booked,email,idType,contact,status,idPhoto,adults,children,paymentMethod,paymentRef){
    document.getElementById('bm-name').textContent=name; document.getElementById('bm-room').textContent=room;
    document.getElementById('bm-checkin').textContent=checkin; document.getElementById('bm-checkout').textContent=checkout;
    document.getElementById('bm-nights').textContent=nights+' night'+(nights!=1?'s':'');

    // Bookings made before adults/children were tracked have both at 0 —
    // fall back to just the total guest count for those.
    if (adults + children > 0) {
        let parts = [adults + ' adult' + (adults!=1?'s':'')];
        if (children > 0) parts.push(children + ' child' + (children!=1?'ren':''));
        document.getElementById('bm-guests').textContent = parts.join(', ');
    } else {
        document.getElementById('bm-guests').textContent=guests+' guest'+(guests!=1?'s':'');
    }

    document.getElementById('bm-booked').textContent=booked;
    document.getElementById('bm-email').textContent=email||'Not provided';
    document.getElementById('bm-idtype').textContent=idType||'Not provided';
    document.getElementById('bm-contact').textContent=contact||'Not provided';
    document.getElementById('bm-paymethod').textContent=paymentMethod||'Not provided';

    const payrefField = document.getElementById('bm-payref-field');
    if (paymentRef) {
        document.getElementById('bm-payref').textContent = paymentRef;
        payrefField.style.display = 'block';
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

    const icon=document.getElementById('bm-icon'), title=document.getElementById('bm-title'),
          badge=document.getElementById('bm-status-badge'), statusTxt=document.getElementById('bm-status-text');
    if(status==='confirmed'){
        icon.textContent='✓'; icon.style.cssText='';
        title.textContent='Booking confirmed';
        badge.innerHTML='✓ Confirmed reservation'; badge.style.cssText='';
        statusTxt.textContent='Confirmed'; statusTxt.style.color='#16a34a';
    } else if(status==='cancelled'){
        icon.textContent='✕'; icon.style.cssText='background:rgba(220,38,38,.15);border-color:rgba(220,38,38,.3);color:#dc2626;';
        title.textContent='Booking cancelled';
        badge.innerHTML='✕ Cancelled'; badge.style.cssText='background:rgba(220,38,38,.15);border-color:rgba(220,38,38,.3);color:#fca5a5;';
        statusTxt.textContent='Cancelled'; statusTxt.style.color='#dc2626';
    } else {
        icon.textContent='⏱'; icon.style.cssText='background:rgba(230,145,0,.15);border-color:rgba(230,145,0,.3);color:#e69100;';
        title.textContent='Awaiting confirmation';
        badge.innerHTML='⏱ Pending review'; badge.style.cssText='background:rgba(230,145,0,.15);border-color:rgba(230,145,0,.3);color:#e69100;';
        statusTxt.textContent='Pending'; statusTxt.style.color='#e69100';
    }

    document.getElementById('bookingModal').classList.add('open'); document.body.style.overflow='hidden';
}
function closeBookingModal(){ document.getElementById('bookingModal').classList.remove('open'); document.body.style.overflow=''; }

function openIdPhotoLightbox(src){
    document.getElementById('idPhotoLightboxImg').src = src;
    document.getElementById('idPhotoLightbox').style.display = 'flex';
}
function closeIdPhotoLightbox(){
    document.getElementById('idPhotoLightbox').style.display = 'none';
    document.getElementById('idPhotoLightboxImg').src = '';
}

/* ══════════════════════════════════════
   PHOTO UPLOAD (drag/drop + preview, max 5)
══════════════════════════════════════ */
function handlePhotoSelect(input, prefix) {
    const thumbGrid = document.getElementById(prefix + 'PhotoThumbs');
    const countEl   = document.getElementById(prefix + 'PhotoCount');
    let files = Array.from(input.files);

    if (files.length > 5) {
        alert('You can upload up to 5 photos only. The first 5 were kept.');
        files = files.slice(0, 5);
        const dt = new DataTransfer();
        files.forEach(f => dt.items.add(f));
        input.files = dt.files;
    }

    thumbGrid.innerHTML = '';
    files.forEach((file, idx) => {
        const reader = new FileReader();
        reader.onload = e => {
            const item = document.createElement('div');
            item.className = 'room-photo-thumb';
            item.innerHTML = '<img src="' + e.target.result + '" alt="">' +
            '<button type="button" class="rpt-remove" onclick="removePhoto(event,\'' + prefix + '\',' + idx + ')"><i class="fa-solid fa-xmark"></i></button>';
            thumbGrid.appendChild(item);
        };
        reader.readAsDataURL(file);
    });

    countEl.textContent = files.length ? files.length + ' of 5 photos selected' : '';
}

function removePhoto(e, prefix, idx) {
    e.stopPropagation();
    const input = document.getElementById(prefix === 'add' ? 'addRoomFileInput' : 'editRoomFileInput');
    const dt = new DataTransfer();
    Array.from(input.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
    input.files = dt.files;
    handlePhotoSelect(input, prefix);
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
        const input = document.getElementById(inputId);
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            handlePhotoSelect(input, prefix);
        }
    });
}
setupPhotoDropzone('addPhotoDropZone', 'addRoomFileInput', 'add');
setupPhotoDropzone('editPhotoDropZone', 'editRoomFileInput', 'edit');

function openAddRoomModal(){
    document.getElementById('addRoomForm').reset();
    document.getElementById('addPhotoThumbs').innerHTML = '';
    document.getElementById('addPhotoCount').textContent = '';
    document.getElementById('addRoomModal').classList.add('open'); document.body.style.overflow='hidden';
}
function closeAddRoomModal(){ document.getElementById('addRoomModal').classList.remove('open'); document.body.style.overflow=''; }

function openEditRoomModal(id,name,price,units,description,imageUrl,galleryUrls,capacity,badge,tags){
    document.getElementById('er-room-id').value=id;
    document.getElementById('er-name').textContent=name;
    document.getElementById('er-price').value=price;
    document.getElementById('er-units').value=units;
    document.getElementById('er-capacity').value=capacity || 4;
    document.getElementById('er-badge').value=badge || 'Available';
    document.getElementById('er-tags').value=tags || '';
    document.getElementById('er-description').value=description || '';

    const allPhotos = [imageUrl, ...(galleryUrls || [])].filter(Boolean);
    const grid = document.getElementById('er-existing-photos');
    const noPhotos = document.getElementById('er-no-photos');
    if (allPhotos.length) {
        grid.innerHTML = allPhotos.map(url => '<div class="room-photo-thumb"><img src="' + url + '" alt=""></div>').join('');
        grid.style.display = 'grid';
        noPhotos.style.display = 'none';
    } else {
        grid.style.display = 'none';
        noPhotos.style.display = 'block';
    }

    document.getElementById('editRoomFileInput').value = '';
    document.getElementById('editPhotoThumbs').innerHTML = '';
    document.getElementById('editPhotoCount').textContent = '';

    document.getElementById('editRoomModal').classList.add('open'); document.body.style.overflow='hidden';
}
function closeEditRoomModal(){ document.getElementById('editRoomModal').classList.remove('open'); document.body.style.overflow=''; }

function toggleNotif(e){ e.stopPropagation(); document.getElementById('notifPanel').classList.toggle('open'); document.getElementById('notifWrap').classList.toggle('open'); }
function closeNotif(){ document.getElementById('notifPanel').classList.remove('open'); document.getElementById('notifWrap').classList.remove('open'); }
function markAllRead(){ document.querySelectorAll('.notif-item--unread').forEach(el=>{ el.classList.remove('notif-item--unread'); el.querySelector('.ni-dot')?.remove(); }); document.querySelector('.notif-count')?.remove(); document.querySelector('.notif-unread-pill')?.remove(); document.querySelector('.notif-mark-all')?.remove(); }

document.addEventListener('click',e=>{
    if(!document.getElementById('searchWrap').contains(e.target)) document.getElementById('searchDropdown').classList.remove('open');
    const nw=document.getElementById('notifWrap'); if(nw&&!nw.contains(e.target)) closeNotif();
    if(adminPicker&&adminPicker.isOpen){
        const dfw=document.querySelector('.date-filter-wrap');
        const insideCalendar = e.target.closest('.flatpickr-calendar');
        if(dfw && !dfw.contains(e.target) && !insideCalendar) adminPicker.close();
    }
    const fw=document.getElementById('filterToggleWrap'); if(fw&&!fw.contains(e.target)){document.getElementById('filterToggleBtn').classList.remove('open');document.getElementById('filterDropdown').classList.remove('open');}
    document.querySelectorAll('.action-menu.open').forEach(m=>m.classList.remove('open'));
});
document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ document.getElementById('searchDropdown').classList.remove('open'); document.getElementById('globalSearch').value=''; closeBookingModal(); closeIdPhotoLightbox(); closeTodayGuestsModal(); if(adminPicker) adminPicker.close(); } });

/* ── Restore last-viewed section on reload ── */
(function(){
    const saved = localStorage.getItem('adminActiveSection');
    if (saved && document.getElementById('section-' + saved)) {
        const idx = { overview: 0, bookings: 1, rooms: 2, activity: 3 }[saved];
        const el = document.querySelectorAll('.sb-item')[idx];
        showSection(saved, el);
    }
})();

const alertEl=document.getElementById('dashAlert');
if(alertEl){ setTimeout(()=>{ alertEl.style.transition='opacity 0.5s'; alertEl.style.opacity='0'; setTimeout(()=>alertEl.remove(),500); },4000); }
</script>
</body>
</html>