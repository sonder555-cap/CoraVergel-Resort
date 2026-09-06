<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';
require_once '../config/csrf.php';

require_once '../config/availability.php';

/* ── URL params from dashboard ── */
$url_check_in  = trim($_GET['check_in']  ?? '');
$url_check_out = trim($_GET['check_out'] ?? '');
$has_dates     = ($url_check_in !== '' && $url_check_out !== '');

// Prefer the split adults/children params (new links). Fall back to the
// old combined "guests" param (older links / bookmarks) treating it as
// all-adults so nothing breaks for links generated before this change.
if (isset($_GET['adults']) || isset($_GET['children'])) {
    $url_adults   = max(1, intval($_GET['adults']   ?? 1));
    $url_child = max(0, intval($_GET['children'] ?? 0));
} else {
    $url_adults   = max(1, intval($_GET['guests'] ?? 1));
    $url_child = 0;
}
$url_guests = $url_adults + $url_child; // total, used for capacity/availability checks
$url_rooms  = max(1, intval($_GET['rooms'] ?? 1));

/* ── Helpers ── */
function fmtDisplay($d) { return (new DateTime($d))->format('m-d-Y'); }

// Generates a unique 4-digit booking reference (e.g. "0472") that guests
// can quote when messaging the Facebook page, so staff can pull up the
// booking quickly instead of searching by name/email.
function generateBookingRef($conn) {
    do {
        $ref = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $chk = $conn->prepare("SELECT booking_id FROM bookings WHERE booking_ref = ? LIMIT 1");
        $chk->bind_param("s", $ref);
        $chk->execute();
        $taken = $chk->get_result()->fetch_assoc();
        $chk->close();
    } while ($taken);
    return $ref;
}
function diffNights($ci, $co) { return max(1, (new DateTime($ci))->diff(new DateTime($co))->days); }

// Real "Booked in last X hours" indicator, driven by actual booking rows
// (not simulated). Auto-detects the timestamp column on bookings so a
// schema difference degrades quietly (badge just doesn't show) instead
// of breaking the page.
function getRecentBookingHours($conn, $room_type) {
    static $ts_col = null, $checked = false;
    if (!$checked) {
        $checked = true;
        foreach (['created_at', 'booking_date', 'date_created', 'created'] as $candidate) {
            $col = $conn->query("SHOW COLUMNS FROM bookings LIKE '{$candidate}'");
            if ($col && $col->num_rows > 0) { $ts_col = $candidate; break; }
        }
    }
    if (!$ts_col) return null;

    $stmt = $conn->prepare("SELECT {$ts_col} FROM bookings WHERE room_type = ? ORDER BY {$ts_col} DESC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $room_type);
    $stmt->execute();
    $stmt->bind_result($ts);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found || empty($ts)) return null;

    $hours = floor((time() - strtotime($ts)) / 3600);
    return $hours >= 0 ? (int) $hours : null;
}

$default_img  = '../assets/images/rooms/room_6a85b9e715fa1.jpg';
$default_desc = 'A comfortable accommodation at CoraVergel Resort. Contact us for more details about this room.';
$default_meta = [
    'cap'   => '',
    'badge' => 'Available',
    'tags'  => ['Free Entrance'],
];

$rooms = [];
$rq = $conn->query("SELECT room_id, room_name, price, total_units, description, image, capacity, badge, tags FROM rooms ORDER BY room_name");
if ($rq) {
    while ($row = $rq->fetch_assoc()) {

        $tags = !empty($row['tags'])
            ? array_map('trim', explode(',', $row['tags']))
            : $default_meta['tags'];

        $rooms[] = [
            'id'          => $row['room_name'],
            'room_id'     => $row['room_id'],
            'price'       => (float) $row['price'],
            'total_units' => (int) $row['total_units'],
            'desc'        => !empty($row['description']) ? $row['description'] : $default_desc,
            'img'         => !empty($row['image']) ? '../assets/images/rooms/' . $row['image'] : $default_img,
            'cap'         => !empty($row['capacity']) ? (int) $row['capacity'] : $default_meta['cap'],
            'badge'       => !empty($row['badge']) ? $row['badge'] : $default_meta['badge'],
            'tags'        => $tags,
        ];
    }
}
$booking_success = '';
$booking_error   = '';
if (($_GET['booked'] ?? '') === '1' && !empty($_GET['ref'])) {
    $booking_success = "Your booking has been submitted! Reference <strong>#" . htmlspecialchars($_GET['ref']) . "</strong>. We'll confirm it shortly.";
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book_room') {
    csrfVerify();
    $room_type      = htmlspecialchars(strip_tags(trim($_POST['room_type'] ?? '')), ENT_QUOTES, 'UTF-8');
    $check_in       = trim($_POST['check_in'] ?? '');
    $check_out      = trim($_POST['check_out'] ?? '');
    $guests         = max(1, intval($_POST['guests'] ?? 0));
    $adults         = max(1, intval($_POST['adults'] ?? 1));
    $children       = max(0, intval($_POST['children'] ?? 0));
    $room_count     = max(1, intval($_POST['rooms'] ?? 1));
    $guest_name     = htmlspecialchars(strip_tags(trim($_POST['guest_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $guest_email    = htmlspecialchars(strip_tags(trim($_POST['guest_email'] ?? '')), ENT_QUOTES, 'UTF-8');
    $id_type        = htmlspecialchars(strip_tags(trim($_POST['id_type'] ?? '')), ENT_QUOTES, 'UTF-8');
    $contact_number = htmlspecialchars(strip_tags(trim($_POST['contact_number'] ?? '')), ENT_QUOTES, 'UTF-8');
    $payment_method = htmlspecialchars(strip_tags(trim($_POST['payment_method'] ?? '')), ENT_QUOTES, 'UTF-8');
    $payment_ref    = htmlspecialchars(strip_tags(trim($_POST['payment_reference'] ?? '')), ENT_QUOTES, 'UTF-8');
    $allowed_payment_methods = ['E-wallet', 'Bank Transfer'];
    $id_photo       = '';
    $payment_receipt = '';

    $room_info = getRoomInfo($conn, $room_type);

    if (empty($room_type) || empty($check_in) || empty($check_out) || $guests < 1) {
        $booking_error = "Missing booking details. Please try again.";
    } elseif (!$room_info) {
        $booking_error = "The selected room is no longer available. Please refresh and choose another room.";
    } elseif ($room_count > (int)$room_info['total_units']) {
        $booking_error = "The requested number of rooms exceeds the available inventory.";
    } elseif ($guests > ((int)$room_info['capacity'] * $room_count)) {
        $booking_error = "The selected room can accommodate up to " . ((int)$room_info['capacity'] * $room_count) . " guest(s).";
    } elseif (empty($guest_name) || empty($guest_email) || empty($id_type) || empty($contact_number)) {
        $booking_error = "Please fill in your personal details, email, valid ID, and contact number to confirm your booking.";
    } elseif (!filter_var($guest_email, FILTER_VALIDATE_EMAIL)) {
        $booking_error = "Please enter a valid email address.";
    } elseif (!in_array($payment_method, $allowed_payment_methods, true)) {
        $booking_error = "Please select a payment method.";
    } elseif (empty($_FILES['id_photo']['name']) || $_FILES['id_photo']['error'] !== UPLOAD_ERR_OK) {
        $booking_error = "Please upload a photo of your valid ID.";
    } elseif (empty($_FILES['payment_receipt']['name']) || $_FILES['payment_receipt']['error'] !== UPLOAD_ERR_OK) {
        $booking_error = "Please upload a screenshot of your payment receipt.";
    } elseif ($check_in < date('Y-m-d')) {
        $booking_error = "Check-in date cannot be in the past.";
    } elseif ($check_in >= $check_out) {
        $booking_error = "Check-out must be after check-in.";
    } elseif (!isRoomAvailable($conn, $room_type, $check_in, $check_out, null, $room_count)) {
        $booking_error = "Sorry, " . htmlspecialchars($room_type) . " does not have enough units for the selected dates. Please choose different dates or another room.";
    } else {
        $allowed_ext  = ['jpg', 'jpeg', 'png', 'webp'];
        $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];
        $max_bytes    = 5 * 1024 * 1024;

        $tmp_name  = $_FILES['id_photo']['tmp_name'];
        $orig_name = $_FILES['id_photo']['name'];
        $file_size = $_FILES['id_photo']['size'];
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $mime      = function_exists('mime_content_type') ? mime_content_type($tmp_name) : '';

        if (!in_array($ext, $allowed_ext, true) || ($mime && !in_array($mime, $allowed_mime, true))) {
            $booking_error = "ID photo must be a JPG, PNG, or WEBP image.";
        } elseif ($file_size > $max_bytes) {
            $booking_error = "ID photo must be smaller than 5MB.";
        } else {
            $upload_dir = '../assets/uploads/ids/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $id_photo = uniqid('id_', true) . '.' . $ext;
            if (!move_uploaded_file($tmp_name, $upload_dir . $id_photo)) {
                $booking_error = "Something went wrong uploading your ID photo. Please try again.";
                $id_photo = '';
            }
        }

        if (empty($booking_error)) {
            $rc_tmp_name  = $_FILES['payment_receipt']['tmp_name'];
            $rc_orig_name = $_FILES['payment_receipt']['name'];
            $rc_file_size = $_FILES['payment_receipt']['size'];
            $rc_ext       = strtolower(pathinfo($rc_orig_name, PATHINFO_EXTENSION));
            $rc_mime      = function_exists('mime_content_type') ? mime_content_type($rc_tmp_name) : '';

            if (!in_array($rc_ext, $allowed_ext, true) || ($rc_mime && !in_array($rc_mime, $allowed_mime, true))) {
                $booking_error = "Payment receipt must be a JPG, PNG, or WEBP image.";
            } elseif ($rc_file_size > $max_bytes) {
                $booking_error = "Payment receipt must be smaller than 5MB.";
            } else {
                $receipt_dir = '../assets/uploads/receipts/';
                if (!is_dir($receipt_dir)) mkdir($receipt_dir, 0755, true);
                $payment_receipt = uniqid('receipt_', true) . '.' . $rc_ext;
                if (!move_uploaded_file($rc_tmp_name, $receipt_dir . $payment_receipt)) {
                    $booking_error = "Something went wrong uploading your payment receipt. Please try again.";
                    $payment_receipt = '';
                }
            }
        }

        if (empty($booking_error)) {
            $nights = diffNights($check_in, $check_out);
            $total_price = (float)$room_info['price'] * $nights * $room_count;
            $booking_ref = generateBookingRef($conn);

            // Lock the room inventory row before the final availability check.
            // This closes the race where two guests submit the last units at once.
            $conn->begin_transaction();
            try {
                $lock = $conn->prepare("SELECT room_id, total_units, capacity, price FROM rooms WHERE room_name = ? FOR UPDATE");
                $lock->bind_param("s", $room_type);
                $lock->execute();
                $locked_room = $lock->get_result()->fetch_assoc();
                $lock->close();

                if (!$locked_room || !isRoomAvailable($conn, $room_type, $check_in, $check_out, null, $room_count)) {
                    throw new RuntimeException("The selected room is no longer available for the requested dates.");
                }

                $stmt = $conn->prepare("INSERT INTO bookings (booking_ref, room_type, room_count, check_in, check_out, guests, adults, children, total_price, guest_name, guest_email, id_type, id_photo, contact_number, payment_method, payment_reference, payment_receipt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param(
                    "ssissiiidssssssss",
                    $booking_ref, $room_type, $room_count, $check_in, $check_out,
                    $guests, $adults, $children, $total_price, $guest_name,
                    $guest_email, $id_type, $id_photo, $contact_number,
                    $payment_method, $payment_ref, $payment_receipt
                );
                $stmt->execute();
                $stmt->close();
                $conn->commit();

                // Post/Redirect/Get — refresh no longer resubmits the booking.
                header("Location: rooms.php?" . http_build_query([
                    'check_in'  => $check_in,
                    'check_out' => $check_out,
                    'guests'    => $guests,
                    'adults'    => $adults,
                    'children'  => $children,
                    'rooms'     => $room_count,
                    'booked'    => 1,
                    'ref'       => $booking_ref,
                ]));
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                $booking_error = $e->getMessage();
                if ($booking_error === "The selected room is no longer available for the requested dates.") {
                    // keep the user-facing message concise
                } else {
                    error_log("Booking insert error: " . $e->getMessage());
                    $booking_error = "Something went wrong while saving your booking. Please try again.";
                }
            }
        }
    }

    // If the DB insert failed after files were uploaded, clean up the orphaned files.
    if ($booking_error !== '' && $id_photo !== '') {
        $id_path = '../assets/uploads/ids/' . basename($id_photo);
        if (is_file($id_path)) @unlink($id_path);
    }
    if ($booking_error !== '' && $payment_receipt !== '') {
        $receipt_path = '../assets/uploads/receipts/' . basename($payment_receipt);
        if (is_file($receipt_path)) @unlink($receipt_path);
    }
}
?>
<!DOCTYPE html>
<html lang="en" xmlns:og="http://opengraphprotocol.org/schema/">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rooms &amp; Rates</title>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="CoraVergel Resort">
    <meta property="og:title" content="Rooms & Rates — CoraVergel Resort">
    <meta property="og:description" content="Explore room types, cottages, and rates at CoraVergel Resort in Tigbauan, Iloilo.">
    <meta property="og:image" content="https://coravergelresort.com/assets/images/11.jpg">
    <meta property="og:url" content="https://coravergelresort.com/pages/rooms.php">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="../assets/images/logo/cv_logo.png">
    <link rel="stylesheet" href="../assets/css/user.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .payment-info-box {
            font-size: 0.82rem;
            line-height: 1.5;
            background: #f5f5f5;
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 10px 12px;
            color: #333;
        }

        /* Compact guests flyout (rooms/adults/children counters) */
        .guests-flyout-container .g-row {
            padding: 6px 0;
        }
        .guests-flyout-container .g-lbl {
            font-size: 0.78rem;
        }
        .guests-flyout-container .g-sub {
            font-size: 0.66rem;
        }
        .guests-flyout-container .g-counter {
            gap: 6px;
        }
        .guests-flyout-container .g-counter button {
            width: 28px;
            height: 28px;
            font-size: 0.75rem;
            line-height: 1;
        }
        .guests-flyout-container .g-counter span {
            font-size: 0.78rem;
            min-width: 18px;
            text-align: center;
        }


        /* ═══════════════════════════════════════════════
           CORAVERGEL BOOKING MODAL — POLISHED 2-COLUMN UI
           ═══════════════════════════════════════════════ */
        #bookModal { background:rgba(10,16,26,.72); backdrop-filter:blur(7px); -webkit-backdrop-filter:blur(7px); padding:28px; }
        #bookModal .modal-box { width:min(1100px,96vw); max-width:1100px; height:min(900px,94vh); max-height:94vh; overflow:hidden; display:grid; grid-template-columns:minmax(340px,.72fr) minmax(560px,1.28fr); grid-template-rows:auto 1fr; background:#fff; border:1px solid rgba(20,31,49,.12); border-radius:20px; box-shadow:0 28px 80px rgba(0,0,0,.28); color:#152033; }
        .cv-booking-head { grid-column:1/-1; display:flex; align-items:center; justify-content:space-between; gap:20px; padding:16px 26px 14px; border-bottom:1px solid #e8e9ec; background:#fff; }
        .cv-booking-eyebrow { font:600 11px/1 "DM Sans",sans-serif; letter-spacing:.17em; color:#b6852a; margin-bottom:4px; }
        .cv-booking-head h2 { margin:0; font:600 25px/1.05 "Cormorant Garamond",serif; color:#142038; }
        .cv-booking-head p { margin:4px 0 0; font:400 12px/1.4 "DM Sans",sans-serif; color:#7c8491; }
        .cv-header-close { flex:0 0 auto; width:34px; height:34px; display:grid; place-items:center; border-radius:50%; border:1px solid #e5e6e9; background:#fff; color:#586172; font-size:14px; cursor:pointer; transition:background .15s ease,color .15s ease; }
        .cv-header-close:hover { background:#f3f4f6; color:#19243a; }
        .cv-booking-left { min-width:0; overflow-y:auto; padding:22px 22px 22px 26px; background:#fbfbfa; border-right:1px solid #e8e9ec; scrollbar-width:thin; }
        .cv-room-photo { height:220px; overflow:hidden; border-radius:10px; background:#eee; }
        .cv-room-photo img { width:100%; height:100%; display:block; object-fit:cover; }
        .cv-room-copy { padding:14px 5px 0; }
        .cv-room-kicker { font:600 10px "DM Sans",sans-serif; letter-spacing:.14em; color:#a57928; }
        .cv-room-copy h3 { margin:3px 0 3px; font:700 "Cormorant Garamond",serif; color:#152033; }
        .cv-room-price { display:flex; align-items:baseline; gap:5px; margin-bottom:10px; color:#b17d20; }
        .cv-room-price span { font:600 20px "DM Sans",sans-serif; }
        .cv-room-price small { font:400 12px "DM Sans",sans-serif; color:#818896; }
        .cv-room-features { display:flex; flex-wrap:wrap; gap:6px 14px; padding:9px 0 11px; border-top:1px solid #e6e6e4; border-bottom:1px solid #e6e6e4; }
        .cv-room-features span { display:inline-flex; align-items:center; gap:6px; color:#586172; font:400 11px "DM Sans",sans-serif; }
        .cv-room-features i { color:#1b2942; font-size:11px; }
        .cv-reservation-card { margin-top:11px; padding:12px 13px 7px; background:#fff; border:1px solid #e4e5e7; border-radius:11px; }
        .cv-reservation-title { display:flex; align-items:center; gap:7px; margin-bottom:7px; font:600 14px "Cormorant Garamond",serif; color:#18243a; }
        .cv-reservation-title i { color:#b6852a; }
        .cv-reservation-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:5px 0; font:400 11px "DM Sans",sans-serif; color:#697181; }
        .cv-reservation-row span { display:flex; align-items:center; gap:7px; }
        .cv-reservation-row span i { width:14px; color:#7c8491; }
        .cv-reservation-row strong { color:#283348; font-weight:500; text-align:right; }
        .cv-reservation-total { margin-top:7px; padding:12px 0 2px; border-top:1px solid #e5e5e5; color:#17243b; }
        .cv-reservation-total span { font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
        .cv-reservation-total strong { font-size:20px; color:#b17d20; font-weight:600; }
        .cv-data-note { display:flex; align-items:center; gap:8px; margin-top:17px; color:#858c98; font:400 10px/1.4 "DM Sans",sans-serif; }
        .cv-data-note i { color:#17243b; font-size:14px; }
        #bookModal .cv-booking-form { min-width:0; min-height:0; display:flex; flex-direction:column; background:#fff; }
        #bookModal .cv-form-scroll { min-height:0; overflow-y:auto; padding:16px 20px 10px; scrollbar-width:thin; }
        .cv-form-section { margin-bottom:9px; padding:11px 14px 12px; border:1px solid #e2e4e8; border-radius:10px; background:#fff; }
        .cv-section-heading { display:flex; align-items:center; gap:9px; margin-bottom:10px; }
        .cv-step { flex:0 0 auto; width:25px; height:25px; display:grid; place-items:center; border-radius:50%; background:#142038; color:#fff; font:600 11px "DM Sans",sans-serif; }
        .cv-section-heading>div { min-width:0; flex:1; }
        .cv-section-heading h3 { margin:0; font:600 16px/1.05 "Cormorant Garamond",serif; color:#19243a; }
        .cv-section-heading p { margin:2px 0 0; color:#9298a2; font:400 10px/1.3 "DM Sans",sans-serif; }
        .cv-section-icon { width:26px; height:26px; display:grid; place-items:center; border-radius:7px; background:#fff8e9; color:#b6852a; border:1px solid #f0e4c9; }
        #bookModal .cf-field { margin:0; }
        #bookModal .cf-field label { display:block; margin:0 0 4px; color:#283348; font:500 10px "DM Sans",sans-serif; }
        #bookModal .cf-field label em { color:#c34d43; font-style:normal; }
        #bookModal .cf-field input,#bookModal .cf-field select { width:100%; min-height:34px; box-sizing:border-box; border:1px solid #d9dce1; border-radius:7px; background:#fff; padding:6px 10px; color:#283348; font:400 11px "DM Sans",sans-serif; outline:none; transition:border-color .18s,box-shadow .18s; }
        #bookModal .cf-field input::placeholder { color:#a7adb7; }
        #bookModal .cf-field input:focus,#bookModal .cf-field select:focus { border-color:#b6852a; box-shadow:0 0 0 3px rgba(182,133,42,.10); }
        .cv-fields-3 { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; }
        .cv-ident-grid,.cv-payment-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; align-items:start; }
        .cv-upload-box { min-height:60px; box-sizing:border-box; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:2px; padding:7px 10px; border:1px dashed #bfc4cc; border-radius:7px; background:#fcfcfc; color:#596273; text-align:center; cursor:pointer; transition:border-color .18s,background .18s; }
        .cv-upload-box:hover { border-color:#b6852a; background:#fffaf1; }
        .cv-upload-box>i { margin-bottom:1px; color:#b6852a; font-size:18px; }
        .cv-upload-box .id-upload-label { font:500 10px "DM Sans",sans-serif; }
        .cv-upload-box small { color:#9aa0aa; font:400 9px "DM Sans",sans-serif; }
        #bookModal .id-upload-wrap.has-file { border-color:#719b78; background:#f4faf5; }
        #bookModal .id-upload-wrap.has-file>i { color:#4e8659; }
        #bookModal .id-upload-filename { color:#3e6f48; font-weight:600; word-break:break-word; }
        #bookModal #idPhotoInput,#bookModal #paymentReceiptInput { display:none; }
        #bookModal .payment-info-box { font-size:10px; line-height:1.45; background:#fffaf0; border:1px solid #eadfc7; border-radius:8px; padding:9px 11px; color:#66532e; }
        #bookModal #paymentInfoField,#bookModal #paymentReceiptField { margin-top:10px; }
        .cv-important { display:flex; align-items:flex-start; gap:9px; margin:0; padding:9px 12px; border:1px solid #efdfb8; border-radius:8px; background:#fff9ea; color:#7c6128; }
        .cv-important>i { margin-top:1px; font-size:15px; color:#b6852a; }
        .cv-important strong { display:block; margin-bottom:3px; font:600 11px "DM Sans",sans-serif; }
        .cv-important p { margin:0; font:400 10px/1.4 "DM Sans",sans-serif; }
        #bookModal .cv-booking-footer { flex:0 0 auto; min-height:58px; display:flex; align-items:center; justify-content:space-between; gap:18px; padding:9px 20px; border-top:1px solid #e5e6e9; background:#fff; }
        .cv-booking-footer .mf-total-lbl { font:600 9px "DM Sans",sans-serif; letter-spacing:.09em; text-transform:uppercase; color:#7d8490; }
        .cv-booking-footer .mf-total-val { margin-top:1px; font:600 19px "DM Sans",sans-serif; color:#b17d20; }
        .cv-footer-actions { display:flex; align-items:center; gap:9px; }
        .cv-cancel-btn { min-width:96px; min-height:37px; border:1px solid #d5d8dd; border-radius:8px; background:#fff; color:#273248; font:500 11px "DM Sans",sans-serif; cursor:pointer; }
        .cv-cancel-btn:hover { background:#f7f7f7; }
        #bookModal .cv-confirm-btn { min-width:210px; min-height:37px; display:inline-flex; align-items:center; justify-content:center; gap:8px; border:0; border-radius:8px; background:#142038; color:#d7a945; font:600 11px "DM Sans",sans-serif; letter-spacing:.04em; text-transform:uppercase; text-decoration:none; cursor:pointer; }
        #bookModal .cv-confirm-btn:hover { background:#0e1729; }
        .cv-no-dates { height:100%; min-height:420px; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:30px; text-align:center; }
        .cv-no-dates>i { margin-bottom:12px; color:#b6852a; font-size:38px; }
        .cv-no-dates .mnd-title { margin:0 0 5px; font:600 23px "Cormorant Garamond",serif; color:#18243a; }
        .cv-no-dates .mnd-sub { max-width:390px; margin:0; color:#858c98; font:400 12px/1.5 "DM Sans",sans-serif; }
        @media (max-width:1050px) {
            #bookModal { padding:15px; }
            #bookModal .modal-box { width:min(900px,96vw); height:min(900px,95vh); grid-template-columns:1fr; grid-template-rows:auto auto minmax(0,1fr); overflow-y:auto; }
            .cv-booking-head { grid-column:1; }
            .cv-booking-left { border-right:0; border-bottom:1px solid #e8e9ec; padding:18px 22px; }
            .cv-room-photo { height:230px; }
            #bookModal .cv-booking-form { min-height:500px; }
        }
        @media (max-width:680px) {
            #bookModal { padding:8px; }
            #bookModal .modal-box { width:100%; height:96vh; max-height:96vh; border-radius:14px; }
            .cv-booking-head { padding:17px 18px 14px; }
            .cv-booking-head h2 { font-size:25px; }
            .cv-booking-left { padding:14px 16px; }
            .cv-room-photo { height:185px; }
            .cv-room-copy h3 { font-size:28px; }
            .cv-fields-3,.cv-ident-grid,.cv-payment-grid { grid-template-columns:1fr; }
            #bookModal .cv-form-scroll { padding:15px 14px 10px; }
            .cv-form-section { padding:13px; }
            #bookModal .cv-booking-footer { padding:10px 14px; align-items:stretch; flex-direction:column; gap:8px; }
            .cv-footer-actions { width:100%; }
            .cv-cancel-btn,#bookModal .cv-confirm-btn { flex:1; min-width:0; }
        }
    </style>
    </head>
<body>

<!-- ══════════ NAVBAR ══════════ -->
<nav class="navbar">

    <!-- LEFT: hamburger (mobile) / nav links (desktop) -->
<div style="display:flex;align-items:center;padding:0;margin:0;background:transparent;overflow:hidden;">
        <button class="nav-hamburger" id="navHamburger" onclick="openDrawer()" aria-label="Open menu">
            <span></span><span></span><span></span>
        </button>
        <!-- Desktop links -->
        <div class="nav-links">
            <a href="about.php">about</a>
            <a href="rooms.php" class="active-link">rooms &amp; rates</a>
            <a href="gallery.php">gallery</a>
            <a href="deals.php">deals</a>
            <a href="index.php#contact">contact</a>
        </div>
    </div>

    <a href="index.php" class="navbar-brand">
        <div class="custom-logo">
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort">
        </div>
    </a>

    <!-- No accounts on this site — nav-login intentionally removed.
         Re-add a profile/login icon here later if accounts come back. -->
    <div class="nav-login"></div>

</nav>
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>


<!-- Slide Drawer -->
<div class="nav-drawer" id="navDrawer">

    <!-- Nav links -->
    <nav class="drawer-nav-links">
        <a href="about.php">About <i class="fa-solid fa-chevron-right"></i></a>
        <a href="rooms.php" class="active-link">Rooms &amp; Rates <i class="fa-solid fa-chevron-right"></i></a>
        <a href="gallery.php">Gallery <i class="fa-solid fa-chevron-right"></i></a>
        <a href="deals.php">Deals <i class="fa-solid fa-chevron-right"></i></a>
        <a href="index.php#contact">Contact <i class="fa-solid fa-chevron-right"></i></a>
    </nav>

    <!-- Footer branding -->
    <div class="drawer-footer">
        <div class="drawer-footer-eyebrow">Resort Tigbauan, Iloilo</div>
        <div class="drawer-footer-logo">
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel">
            <span class="drawer-footer-name">CoraVergel Resort</span>
        </div>
    </div>

</div>
</div>
<button class="drawer-close-x" id="drawerCloseBtn" onclick="closeDrawer()" aria-label="Close menu">
    <i class="fa-solid fa-xmark"></i>
</button>

<!-- ALERTS -->
<?php if ($booking_success): ?>
<div class="page-alert page-alert--success" id="pageAlert">
    <i class="fa-solid fa-circle-check"></i>
    <div>
        <?= $booking_success ?>
    </div>
    <button onclick="this.parentElement.remove()" class="alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>
<?php if ($booking_error): ?>
<div class="page-alert page-alert--error" id="pageAlert">
    <i class="fa-solid fa-circle-exclamation"></i>
    <div><?= htmlspecialchars($booking_error) ?></div>
    <button onclick="this.parentElement.remove()" class="alert-close"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>

<!-- BOOKING MODAL -->
<div class="modal-overlay" id="bookModal">
    <div class="modal-box">
        <div class="cv-booking-head">
            <div>
                <div class="cv-booking-eyebrow">CORAVERGEL RESORT</div>
                <h2>Book Your Stay</h2>
                <p>Fill in your details to reserve your room.</p>
            </div>
            <button type="button" class="cv-header-close" onclick="closeModal()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="cv-booking-left">
            <div class="cv-room-photo">
                <img class="modal-room-img-preview" id="modalImg" src="../assets/images/rooms/room_6a85b9e715fa1.jpg" alt="Room">
            </div>
            <div class="cv-room-copy">
                <div class="cv-room-kicker">SELECTED ROOM</div>
                <h3 id="modalRoomName">Room Name</h3>
                <div class="cv-room-price"><span id="modalRoomPrice">₱0</span><small>/ night</small></div>
                <div class="cv-room-features">
                    <span><i class="fa-solid fa-user-group"></i> <b id="modalCapacity">Guest capacity</b></span>
                    <span><i class="fa-solid fa-bed"></i> <?= $url_rooms ?> room<?= $url_rooms !== 1 ? 's' : '' ?></span>
                    <span><i class="fa-solid fa-door-open"></i> Free entrance</span>
                </div>
                <div class="cv-reservation-card">
                    <div class="cv-reservation-title"><i class="fa-regular fa-calendar-check"></i> Your Reservation</div>
                    <div class="cv-reservation-row"><span><i class="fa-regular fa-calendar"></i> Check-in</span><strong><?= htmlspecialchars(fmtDisplay($url_check_in)) ?></strong></div>
                    <div class="cv-reservation-row"><span><i class="fa-regular fa-calendar"></i> Check-out</span><strong><?= htmlspecialchars(fmtDisplay($url_check_out)) ?></strong></div>
                    <div class="cv-reservation-row"><span><i class="fa-regular fa-clock"></i> Nights</span><strong><?= diffNights($url_check_in,$url_check_out) ?></strong></div>
                    <div class="cv-reservation-row"><span><i class="fa-solid fa-users"></i> Guests</span><strong><?= $url_adults ?> Adults<?= $url_child ? ', '.$url_child.' Child'.($url_child!==1?'ren':'') : '' ?></strong></div>
                </div>
            </div>
        </div>

        <?php if ($has_dates): ?>
        <form class="modal-form modal-right cv-booking-form" method="POST" enctype="multipart/form-data"
              action="rooms.php?<?= http_build_query(['check_in'=>$url_check_in,'check_out'=>$url_check_out,'guests'=>$url_guests]) ?>" id="bookForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="book_room">
            <input type="hidden" name="room_type" id="formRoomType" value="">
            <input type="hidden" name="check_in" value="<?= htmlspecialchars($url_check_in) ?>">
            <input type="hidden" name="check_out" value="<?= htmlspecialchars($url_check_out) ?>">
            <input type="hidden" name="guests" id="formGuests" value="<?= $url_guests ?>">
            <input type="hidden" name="adults" id="formAdults" value="<?= $url_adults ?>">
            <input type="hidden" name="children" id="formChildren" value="<?= $url_child ?>">
            <input type="hidden" name="rooms" id="formRooms" value="<?= $url_rooms ?>">

            <div class="modal-scroll cv-form-scroll">
                <section class="cv-form-section">
                    <div class="cv-section-heading"><span class="cv-step">1</span><div><h3>Guest Information</h3><p>Tell us who will be staying.</p></div><span class="cv-section-icon"><i class="fa-regular fa-user"></i></span></div>
                    <div class="cv-fields-3">
                        <div class="cf-field"><label for="guestName">Full name <em>*</em></label><input type="text" id="guestName" name="guest_name" required placeholder="Enter your full name"></div>
                        <div class="cf-field"><label for="guestEmail">Email address <em>*</em></label><input type="email" id="guestEmail" name="guest_email" required placeholder="Enter your email"></div>
                        <div class="cf-field"><label for="contactNumber">Contact number <em>*</em></label><input type="tel" id="contactNumber" name="contact_number" required placeholder="09XX XXX XXXX" pattern="^[0-9+\-\s]{7,15}$"></div>
                    </div>
                </section>

                <section class="cv-form-section">
                    <div class="cv-section-heading"><span class="cv-step">2</span><div><h3>Identification</h3><p>A valid ID helps us verify your reservation.</p></div><span class="cv-section-icon"><i class="fa-regular fa-id-card"></i></span></div>
                    <div class="cv-ident-grid">
                        <div class="cf-field"><label for="idType">Valid ID type <em>*</em></label><select id="idType" name="id_type" required onchange="handleIdTypeChange()"><option value="">Select ID type</option><option value="Government ID">Government ID</option><option value="Driver's License">Driver's License</option><option value="Passport">Passport</option><option value="School ID">School ID</option><option value="Other">Other</option></select></div>
                        <div class="cf-field" id="idPhotoField" style="display:none;"><label for="idPhotoInput">Upload photo of your ID <em>*</em></label><label class="id-upload-wrap cv-upload-box" id="idUploadWrap" for="idPhotoInput"><i class="fa-solid fa-arrow-up-from-bracket"></i><div class="id-upload-label" id="idUploadLabel">Click to upload ID</div><small>JPG, PNG, WEBP · Max 5MB</small></label><input type="file" id="idPhotoInput" name="id_photo" accept=".jpg,.jpeg,.png,.webp,image/*" onchange="handleIdPhotoChange()"></div>
                    </div>
                </section>

                <section class="cv-form-section">
                    <div class="cv-section-heading"><span class="cv-step">3</span><div><h3>Payment</h3><p>Submit your payment details for verification.</p></div><span class="cv-section-icon"><i class="fa-regular fa-credit-card"></i></span></div>
                    <div class="cv-payment-grid">
                        <div class="cf-field"><label for="paymentMethod">Payment method <em>*</em></label><select id="paymentMethod" name="payment_method" required onchange="handlePaymentMethodChange()"><option value="">Select payment method</option><option value="E-wallet">GCash</option><option value="Bank Transfer">Bank Transfer</option></select></div>
                        <div class="cf-field" id="paymentReceiptField" style="display:none;"><label for="paymentReceiptInput">Upload payment receipt <em>*</em></label><label class="id-upload-wrap cv-upload-box cv-receipt-box" id="receiptUploadWrap" for="paymentReceiptInput"><i class="fa-solid fa-cloud-arrow-up"></i><div class="id-upload-label" id="receiptUploadLabel">Click to upload receipt</div><small>JPG, PNG, WEBP · Max 5MB</small></label><input type="file" id="paymentReceiptInput" name="payment_receipt" accept=".jpg,.jpeg,.png,.webp,image/*" onchange="handleReceiptChange()"></div>
                    </div>
                    <div class="cf-field" id="paymentInfoField" style="display:none;"><div class="payment-info-box" id="paymentInfoBox"></div></div>
                </section>

                <div class="cv-important"><i class="fa-solid fa-circle-info"></i><div><strong>Important Reminder</strong><p>Please make sure all information provided is correct. Your booking will be verified before confirmation.</p></div></div>
            </div>

            <div class="modal-footer-bar cv-booking-footer">
                <div><div class="mf-total-lbl">Total Amount</div><div class="mf-total-val" id="modalTotal">₱0</div></div>
                <div class="cv-footer-actions"><button type="button" class="cv-cancel-btn" onclick="closeModal()">Cancel</button><button type="submit" class="modal-submit cv-confirm-btn"><i class="fa-solid fa-lock"></i> Confirm Booking</button></div>
            </div>
        </form>
        <?php else: ?>
        <div class="modal-right cv-booking-form"><div class="modal-scroll"><div class="modal-no-dates cv-no-dates"><i class="fa-regular fa-calendar-days"></i><p class="mnd-title">No dates selected yet</p><p class="mnd-sub">Pick your check-in and check-out dates first, then come back to book.</p><a href="index.php#booking-section" class="modal-submit cv-confirm-btn" style="margin-top:8px;"><i class="fa-solid fa-calendar-days"></i> Pick dates</a></div></div></div>
        <?php endif; ?>
    </div>
</div>

<!-- ROOM PREVIEW LIGHTBOX -->
<div class="room-lightbox" id="roomLightbox" onclick="closeRoomLightbox(event)">
    <button class="room-lightbox-close" onclick="closeRoomLightbox(event)" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
    </button>
    <div class="room-lightbox-img-wrap" onclick="event.stopPropagation()">
        <img id="roomLightboxImg" src="" alt="">
        <div class="room-lightbox-caption" id="roomLightboxCaption"></div>
    </div>
</div>

<!-- RESORT HERO -->
<div class="resort-hero">
    <img class="resort-hero-img" src="../assets/images/background2.png" alt="CoraVergel Resort">
    <div class="resort-hero-overlay"></div>
    <div class="resort-hero-info">
        <div class="resort-hero-name">CoraVergel Resort</div>
        <div class="resort-hero-details">
            <a class="resort-hero-detail" href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-building"></i>Barosong, Tigbauan, Iloilo City, Philippines</a>
            <a class="resort-hero-detail" href="https://www.facebook.com/coravergelresort" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-link"></i> facebook.com/coravergelresort</a>
        </div>
    </div>
</div>

<!-- PAGE BODY -->
<div class="page-body">

    <!-- ══ OVERNIGHT ROOMS ══ -->
    <section class="content-section">

        <!-- STICKY DATE BAR — only shown once the user already has dates selected -->
        <?php if ($has_dates): ?>
        <div class="search-bar-container_inner" id="dateBar">

            <div aria-live="assertive" aria-relevant="all" class="sr-only" id="dbLiveRegion"></div>

            <div class="search-bar-container_top">

                <!-- Guests -->
                <div class="search-bar-container_guestsWrapper">
                    <button type="button"
                            class="search-bar-container_guests"
                            aria-label="Guests, <?= $url_rooms ?> room<?= $url_rooms !== 1 ? 's' : '' ?>, <?= $url_adults ?> adult<?= $url_adults !== 1 ? 's' : '' ?>, <?= $url_child ?> child"
                            aria-expanded="false"
                            aria-controls="guestsFlyoutContainer"
                            onclick="toggleDbGuests(event)"
                            id="dbGuestBox">
                        <i class="fa-solid fa-user search-bar-icon-left" aria-hidden="true"></i>
                        <div class="search-bar-container_text">
                            <span class="search-bar-container_label">Guests</span>
                            <span id="dbGuestVal"><?= $url_rooms ?> room<?= $url_rooms !== 1 ? 's' : '' ?>, <?= $url_adults ?> adult<?= $url_adults !== 1 ? 's' : '' ?>, <?= $url_child ?> child</span>
                        </div>
                    </button>

                    <div class="guests-flyout-container" id="guestsFlyoutContainer" onclick="event.stopPropagation()">
                        <div class="g-row">
                            <div>
                                <div class="g-lbl">Rooms</div>
                            </div>
                            <div class="g-counter">
                                <button type="button" onclick="gAdjRoom(-1)" aria-label="Decrease rooms">−</button>
                                <span id="gRoomCount"><?= $url_rooms ?></span>
                                <button type="button" onclick="gAdjRoom(1)" aria-label="Increase rooms">+</button>
                            </div>
                        </div>
                        <div class="g-row">
                            <div>
                                <div class="g-lbl">Adults</div>
                                <div class="g-sub">Ages 13+</div>
                            </div>
                            <div class="g-counter">
                                <button type="button" onclick="gAdjAdult(-1)" aria-label="Decrease adults">−</button>
                                <span id="gAdultCount"><?= $url_adults ?></span>
                                <button type="button" onclick="gAdjAdult(1)" aria-label="Increase adults">+</button>
                            </div>
                        </div>
                        <div class="g-row">
                            <div>
                                <div class="g-lbl">Children</div>
                                <div class="g-sub">Ages 0–12</div>
                            </div>
                            <div class="g-counter">
                                <button type="button" onclick="gAdjChild(-1)" aria-label="Decrease children">−</button>
                                <span id="gChildCount"><?= $url_child ?></span>
                                <button type="button" onclick="gAdjChild(1)" aria-label="Increase children">+</button>
                            </div>
                        </div>

                        <div class="guest-child-note" role="note" aria-label="Children entrance fee information">
                            <div class="guest-child-note-icon" aria-hidden="true">i</div>
                            <div><strong>Note:</strong> Children 4 years old and below are <strong>FREE of entrance fee.</strong></div>
                        </div>

                    </div>
                </div>

                <!-- Check-in -->
                <button type="button"
                        class="search-bar-container_checkIn"
                        aria-label="Check-in <?= $has_dates ? fmtDisplay($url_check_in) : 'not selected' ?>"
                        aria-expanded="false"
                        aria-controls="calendarFlyoutContainer"
                        onclick="toggleDbCal(event)"
                        id="dbCheckInBox">
                    <i class="fa-regular fa-calendar-days search-bar-icon-left" aria-hidden="true"></i>
                    <div class="search-bar-container_text">
                        <span class="search-bar-container_label">Check-in</span>
                        <span id="dbCheckInVal" class="<?= !$has_dates ? 'is-placeholder' : '' ?>">
                            <?= $has_dates ? fmtDisplay($url_check_in) : 'Select date' ?>
                        </span>
                    </div>
                </button>

                <!-- Check-out -->
                <button type="button"
                        class="search-bar-container_checkOut"
                        aria-label="Check-out <?= $has_dates ? fmtDisplay($url_check_out) : 'not selected' ?>"
                        aria-expanded="false"
                        aria-controls="calendarFlyoutContainer"
                        onclick="toggleDbCal(event)"
                        id="dbCheckOutBox">
                    <i class="fa-regular fa-calendar-days search-bar-icon-left" aria-hidden="true"></i>
                    <div class="search-bar-container_text">
                        <span class="search-bar-container_label">Check-out</span>
                        <span id="dbCheckOutVal" class="<?= !$has_dates ? 'is-placeholder' : '' ?>">
                            <?= $has_dates ? fmtDisplay($url_check_out) : 'Select date' ?>
                        </span>
                    </div>
                </button>

                <!-- Shared calendar flyout -->
                <div class="calendar-flyout-container" id="calendarFlyoutContainer" onclick="event.stopPropagation()">

                    <div class="cal-summary-row">
                        <div class="cal-summary-item">
                            <div class="cal-summary-lbl">Check-in</div>
                            <div class="cal-summary-val <?= !$has_dates ? 'empty' : '' ?>" id="calFromVal">
                                <?= $has_dates ? fmtDisplay($url_check_in) : 'Select' ?>
                            </div>
                        </div>
                        <div class="cal-summary-item">
                            <div class="cal-summary-lbl">Check-out</div>
                            <div class="cal-summary-val <?= !$has_dates ? 'empty' : '' ?>" id="calToVal">
                                <?= $has_dates ? fmtDisplay($url_check_out) : 'Select' ?>
                            </div>
                        </div>
                    </div>

                    <div class="cal-months-row">
                        <div class="cal-month-block">
                            <div class="cal-month-header"><span id="calMonthLabel1"></span></div>
                            <div class="cal-dow-row">
                                <div class="cal-dow">Su</div><div class="cal-dow">Mo</div><div class="cal-dow">Tu</div>
                                <div class="cal-dow">We</div><div class="cal-dow">Th</div><div class="cal-dow">Fr</div><div class="cal-dow">Sa</div>
                            </div>
                            <div class="cal-days-grid" id="calDaysGrid1"></div>
                        </div>

                        <div class="cal-month-block">
                            <div class="cal-month-header">
                                <span id="calMonthLabel2"></span>
                                <button type="button" class="cal-nav-btn" onclick="calNext()" aria-label="Next month"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                            </div>
                            <div class="cal-dow-row">
                                <div class="cal-dow">Su</div><div class="cal-dow">Mo</div><div class="cal-dow">Tu</div>
                                <div class="cal-dow">We</div><div class="cal-dow">Th</div><div class="cal-dow">Fr</div><div class="cal-dow">Sa</div>
                            </div>
                            <div class="cal-days-grid" id="calDaysGrid2"></div>
                        </div>
                    </div>

                    <div class="cal-footer">
                        <span class="cal-summary" id="calSummary"></span>
                        <button type="button" class="cal-clear" onclick="calClear()">Cancel</button>
                        <button type="button" class="cal-done" onclick="calDone()">Search</button>
                    </div>
                </div>

            </div>
        </div>
        <?php endif; ?>

        <?php
        /* ── Pre-check: are ANY rooms bookable for the selected dates/guests? ──
           Reuses the same availability logic as the room cards below, just
           run early so we know whether to show the "not available" alert. */
        $no_rooms_available = false;
        if ($has_dates && !empty($rooms)) {
            $bookable_count = 0;
            foreach ($rooms as $r) {
                $chk_over_cap = $url_guests > ($r['cap'] * $url_rooms);
                $chk_units    = getAvailableUnits($conn, $r['id'], $url_check_in, $url_check_out);
                if (!$chk_over_cap && $chk_units >= $url_rooms) $bookable_count++;
            }
            $no_rooms_available = ($bookable_count === 0);
        }
        ?>

        <div class="section-label">
            <h2 class="section-heading">Accommodations</h2>
        </div>

        <?php if ($no_rooms_available): ?>
        <div class="unavailable-alert">
            <div class="unavailable-alert-icon"><i class="fa-solid fa-ban"></i></div>
            <div class="unavailable-alert-text">
                <p class="unavailable-alert-title">
                    No rooms available for <?= fmtDisplay($url_check_in) ?> &ndash; <?= fmtDisplay($url_check_out) ?>.
                </p>
                <p class="unavailable-alert-sub">
                    Please try choosing different dates, a shorter stay, or another guest count.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <div class="rooms-grid">
            <?php if (empty($rooms)): ?>
            <p style="grid-column:1/-1;text-align:center;color:#999;">Rooms are being updated. Please check back shortly.</p>
            <?php endif; ?>
            <?php
            foreach ($rooms as $r):
                $over_cap = $has_dates && $url_guests > ($r['cap'] * $url_rooms);
                $nights   = $has_dates ? diffNights($url_check_in, $url_check_out) : null;
                $total    = $nights ? $r['price'] * $nights : null;
                $available_units = $has_dates ? getAvailableUnits($conn, $r['id'], $url_check_in, $url_check_out) : null;
                $fully_booked = $has_dates && $available_units !== null && $available_units < $url_rooms;
                $recent_hours = getRecentBookingHours($conn, $r['id']);
            ?>
            <div class="room-card <?= ($over_cap || $fully_booked) ? 'room-card--dimmed' : '' ?>">
                <div class="room-img" onclick="openRoomLightbox('<?= addslashes($r['id']) ?>', '<?= addslashes($r['img']) ?>')">
                    <img src="<?= htmlspecialchars($r['img']) ?>" alt="<?= htmlspecialchars($r['id']) ?>"
                         onerror="this.src='https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800&q=80'">
                    <span class="room-zoom-hint"><i class="fa-solid fa-images"></i></span>
                    <?php if ($over_cap): ?>
                    <div class="room-over-cap"><i class="fa-solid fa-users-slash"></i> Exceeds capacity</div>
                    <?php elseif ($fully_booked): ?>
                    <div class="room-over-cap"><i class="fa-solid fa-ban"></i> Fully booked these dates</div>
                    <?php endif; ?>
                </div>

                <div class="room-main">
                    <div class="room-info">
                        <h3 class="room-name"><?= htmlspecialchars($r['id']) ?></h3>



                        <p class="room-desc"><?= $r['desc'] ?></p>

                        <hr class="room-divider">

                        <?php if (!empty($r['tags'])): ?>
                        <div class="room-tags-line"><?= htmlspecialchars(implode(' | ', $r['tags'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="room-cta">
                    <div class="room-price-block">
                        <div class="room-price">
                            <span class="rp-sym">₱</span>
                            <span class="rp-amt"><?= number_format($r['price']) ?></span>
                        </div>
                        <div class="rp-per">Per Night</div>
                        <?php if ($total): ?>
                        <div class="rp-total">₱<?= number_format($total) ?> total</div>
                        <?php endif; ?>
                    </div>

                    <?php if ($over_cap): ?>
                    <div class="btn-book--disabled">
                        <i class="fa-solid fa-users-slash"></i> Over Capacity
                    </div>
                    <?php elseif ($fully_booked): ?>
                    <div class="btn-book--disabled">
                        <i class="fa-solid fa-ban"></i> Fully Booked
                    </div>
                    <?php else: ?>
                    <button class="btn-book"
                        onclick="openModal('<?= addslashes($r['id']) ?>', <?= $r['price'] ?>, '<?= addslashes($r['img']) ?>')">
                        Book Now
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ══ COTTAGES ══ -->
    <section class="content-section content-section--alt">
        <div class="section-inner">
            <div class="section-label">
                <span class="label-pill label-pill--gold"><i class="fa-solid fa-sun"></i> Day Use</span>
                <h2 class="section-heading">Cottages &amp; Gazebos</h2>
            </div>
            <div class="small-cards-grid">
                <?php
                $cottages = [
                    ['Large Gazebo','15 pax','1,500','fa-umbrella-beach','Near swimming pool'],
                    ['Small Gazebo','8 pax', '1,200','fa-umbrella-beach','Near swimming pool'],
                    ['Umbrella',    '4 pax', '400',  'fa-umbrella',      'Open area'],
                    ['Small Kubo',  '10 pax','1,000','fa-house',         'Shaded cottage'],
                    ['Large Kubo',  '20 pax','2,000','fa-house',         'Great for big groups'],
                ];
                foreach ($cottages as $c): ?>
                <div class="small-card">
                    <div class="sc-icon"><i class="fa-solid <?= $c[3] ?>"></i></div>
                    <div>
                        <div class="sc-name"><?= $c[0] ?></div>
                        <div class="sc-sub"><?= $c[4] ?></div>
                        <div class="sc-cap"><i class="fa-solid fa-user-group"></i> Up to <?= $c[1] ?></div>
                    </div>
                    <div class="sc-price">
                        <span class="scp-sym">₱</span><span class="scp-amt"><?= $c[2] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ══ TENTS ══ -->
    <section class="content-section">
        <div class="section-label">
            <span class="label-pill label-pill--dark"><i class="fa-solid fa-campground"></i> Tent Rental</span>
            <h2 class="section-heading">Premium Tents</h2>
            <p class="section-sub">Check-in 5PM · Check-out 7AM · Includes mattress, pillows &amp; blankets · Free Entrance</p>
        </div>
        <div class="tent-cards-grid">
            <?php
            $tents = [
                ['Premium Tent A','2 pax','900',  '6 units available'],
                ['Premium Tent B','3 pax','1,100','1 unit available'],
                ['Premium Tent C','6 pax','2,300','1 unit available'],
            ];
            foreach ($tents as $t): ?>
            <div class="tent-card">
                <div class="tent-icon"><i class="fa-solid fa-campground"></i></div>
                <div class="tent-body">
                    <div class="tent-name"><?= $t[0] ?></div>
                    <div class="tent-meta">
                        <i class="fa-solid fa-user-group"></i> <?= $t[1] ?>
                        <span class="tent-sep">·</span> <?= $t[3] ?>
                    </div>
                    <div class="tent-includes">
                        <i class="fa-solid fa-check"></i> Free Entrance
                        <span class="tent-sep">·</span>
                        <i class="fa-solid fa-check"></i> Bedding Included
                    </div>
                </div>
                <div class="tent-price">
                    <span class="tp-sym">₱</span><span class="tp-amt"><?= $t[2] ?></span>
                    <span class="tp-night">/night</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ══ REMINDERS ══ -->
    <section class="content-section">
        <div class="reminders-card">
            <div class="rem-head">
                <i class="fa-solid fa-circle-info"></i>
                <h3>Resort Reminders</h3>
            </div>
            <div class="rem-grid">
                <div class="rem-item"><i class="fa-solid fa-clock"></i> Check-in: 5:00 PM · Check-out: 12:00 PM</div>
                <div class="rem-item"><i class="fa-solid fa-ban"></i> No outside food &amp; beverages allowed</div>
                <div class="rem-item"><i class="fa-solid fa-glass-water"></i> Resort food &amp; drinks available on-site</div>
                <div class="rem-item"><i class="fa-solid fa-person-swimming"></i> Free swimming included for overnight guests</div>
                <div class="rem-item"><i class="fa-solid fa-paw"></i> No pets allowed inside the resort</div>
                <div class="rem-item"><i class="fa-solid fa-music"></i> Quiet hours: 10:00 PM – 6:00 AM</div>
            </div>
        </div>
    </section>

</div><!-- /page-body -->

<!-- FOOTER -->
<footer class="site-footer">
    <div class="footer-top">
        <div class="footer-brand">
            <div class="footer-logo-wrap">
                <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort Logo" class="footer-logo-img">
            </div>
        </div>
        <div class="footer-right">
            <div class="footer-socials">
                <a href="https://www.facebook.com/coravergelresort" aria-label="Facebook" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-facebook-f"></i></a>
                <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                <a href="https://www.tiktok.com/@coravergel.resort" aria-label="TikTok" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-tiktok"></i></a>
            </div>
        </div>
    </div>
    <div class="footer-links">
        <div class="footer-col">
            <h4>About CoraVergel Resort</h4>
            <a href="about.php">About</a>
            <a href="#contact" onclick="smoothScroll(event,'contact')">Contact Us</a>
        </div>
        <div class="footer-col">
            <h4>Stay</h4>
            <a href="rooms.php">Duplex Rooms</a>
            <a href="rooms.php">Family Rooms</a>
            <a href="rooms.php">Small Bahay Kubo</a>
            <a href="rooms.php">Large Bahay Kubo</a>
        </div>

        <div class="footer-col footer-contact-col">
            <h4>Contact Information</h4>
            <a href="mailto:coravergelresort@gmail.com" class="topbar-link">coravergelresort@gmail.com</a>
            <br>
            <h4>Address</h4>
            <a href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer" class="topbar-link">21 Barosong, Tigbauan,<br>Iloilo City, Philippines</a>
            <div class="footer-map-icons">
                <a class="fa-solid fa-location-dot" href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer" title="View on Google Maps"></a>
            </div>
        </div>
    </div>
    <div class="footer-bottom">
        <span>&copy; CoraVergel Resort. All rights reserved.</span>
        <div class="footer-bottom-links">
            <a href="resort_policies.php">Privacy Policy</a>
            <a href="#">Terms of Use</a>
        </div>
    </div>
</footer>

<script>
/* ══════════════════════════════════════
   PHP → JS
══════════════════════════════════════ */
const HAS_DATES  = <?= $has_dates ? 'true' : 'false' ?>;
const URL_CI     = "<?= addslashes($url_check_in) ?>";
const URL_CO     = "<?= addslashes($url_check_out) ?>";
const URL_GUESTS = <?= $url_guests ?>;
const URL_ADULTS   = <?= $url_adults ?>;
const URL_CHILDREN = <?= $url_child ?>;
const URL_ROOMS     = <?= $url_rooms ?>;

/* Room price/image lookup — generated straight from the DB-driven
   $rooms array, so it always matches whatever's in the admin panel. */
const ROOM_MAP = <?= json_encode(array_combine(
    array_column($rooms, 'id'),
    array_map(fn($r) => ['price' => $r['price'], 'img' => $r['img'], 'cap' => $r['cap'], 'tags' => $r['tags']], $rooms)
)) ?>;

/* ══════════════════════════════════════
   DATE BAR CALENDAR
══════════════════════════════════════ */
const MF = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const MS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

let calY, calM, calStart = null, calEnd = null, calHoverDate = null;

(function(){
    const now = new Date();
    if (HAS_DATES) {
        calStart = new Date(URL_CI + 'T00:00:00');
        calEnd   = new Date(URL_CO + 'T00:00:00');
        calY = calStart.getFullYear(); calM = calStart.getMonth();
    } else {
        calY = now.getFullYear(); calM = now.getMonth();
    }
})();

function dStr(d){ return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
function dDisp(d){ return MS[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear(); }

function announce(msg){
    const el = document.getElementById('dbLiveRegion');
    el.textContent = '';
    setTimeout(() => { el.textContent = msg; }, 50);
}

function toggleDbCal(e){
    e.stopPropagation();
    closeGuests();
    const flyout = document.getElementById('calendarFlyoutContainer');
    const inBox  = document.getElementById('dbCheckInBox');
    const outBox = document.getElementById('dbCheckOutBox');
    const open   = flyout.classList.toggle('open');
    inBox.setAttribute('aria-expanded', open);
    outBox.setAttribute('aria-expanded', open);
    if (open) renderCal();
}

function closeCal(){
    document.getElementById('calendarFlyoutContainer').classList.remove('open');
    document.getElementById('dbCheckInBox').setAttribute('aria-expanded', 'false');
    document.getElementById('dbCheckOutBox').setAttribute('aria-expanded', 'false');
}

function buildMonthGrid(y, m, gridId){
    const first = new Date(y,m,1).getDay();
    const days  = new Date(y,m+1,0).getDate();
    const today = new Date(); today.setHours(0,0,0,0);
    let h='';
    for(let i=0;i<first;i++) h+=`<button type="button" class="cal-day cal-empty" disabled></button>`;
    for(let d=1;d<=days;d++){
        const dt = new Date(y,m,d);
        let cls = 'cal-day';
        const isPast = dt < today;
        if(isPast) cls += ' cal-disabled';
        if(dt.toDateString()===today.toDateString()) cls += ' cal-today';
        if(calStart && calEnd){
            const t=dt.getTime(), s=calStart.getTime(), e=calEnd.getTime();
            if(t===s && t===e) cls += ' cal-start cal-end';
            else if(t===s)      cls += ' cal-start';
            else if(t===e)      cls += ' cal-end';
            else if(t>s && t<e) cls += ' cal-in-range';
        } else if(calStart && calHoverDate && calHoverDate.getTime() > calStart.getTime()){
            /* live preview while picking check-out, before it's actually clicked */
            const t=dt.getTime(), s=calStart.getTime(), h=calHoverDate.getTime();
            if(t===s)            cls += ' cal-start';
            else if(t===h)       cls += ' cal-hover-end';
            else if(t>s && t<h)  cls += ' cal-in-range-preview';
        } else if(calStart && dt.toDateString()===calStart.toDateString()){
            cls += ' cal-start cal-end';
        }
        h += `<button type="button" class="${cls}" ${isPast?'disabled':''} onclick="calPick(${y},${m},${d})" onmouseenter="calHoverDay(${y},${m},${d})" onmouseleave="calHoverClear()">${d}</button>`;
    }
    document.getElementById(gridId).innerHTML = h;
}

function renderCal(){
    document.getElementById('calMonthLabel1').textContent = MF[calM] + ' ' + calY;
    let m2 = calM + 1, y2 = calY;
    if (m2 > 11) { m2 = 0; y2++; }
    document.getElementById('calMonthLabel2').textContent = MF[m2] + ' ' + y2;

    buildMonthGrid(calY, calM, 'calDaysGrid1');
    buildMonthGrid(y2, m2, 'calDaysGrid2');

    const fEl = document.getElementById('calFromVal'), tEl = document.getElementById('calToVal');
    fEl.textContent = calStart ? dDisp(calStart) : 'Select';
    fEl.className   = 'cal-summary-val' + (calStart ? '' : ' empty');
    tEl.textContent = calEnd ? dDisp(calEnd) : 'Select';
    tEl.className   = 'cal-summary-val' + (calEnd ? '' : ' empty');

    const s = document.getElementById('calSummary');
    if (calStart && calEnd) {
        const n = Math.round((calEnd - calStart) / 86400000);
        s.textContent = n + ' night' + (n !== 1 ? 's' : '') + ' selected';
    } else if (calStart) {
        s.textContent = 'Now pick check-out';
    } else {
        s.textContent = '';
    }
}

function calNext(){ calM++; if(calM>11){calM=0;calY++;} renderCal(); }

/* Redraw just the day grids (no header/summary work) — cheap enough to run on every hover */
function redrawCalGrids(){
    let m2 = calM + 1, y2 = calY;
    if (m2 > 11) { m2 = 0; y2++; }
    buildMonthGrid(calY, calM, 'calDaysGrid1');
    buildMonthGrid(y2, m2, 'calDaysGrid2');
}

function calHoverDay(y,m,d){
    if (!calStart || calEnd) return; /* only preview while picking check-out */
    const dt = new Date(y,m,d);
    if (dt.getTime() === calHoverDate?.getTime()) return;
    calHoverDate = dt;
    redrawCalGrids();
}

function calHoverClear(){
    if (!calHoverDate) return;
    calHoverDate = null;
    redrawCalGrids();
}

function calPick(y,m,d){
    const dt = new Date(y,m,d);
    const today = new Date(); today.setHours(0,0,0,0);
    if (dt < today) return;
    calHoverDate = null;
    if (!calStart || (calStart && calEnd)) { calStart = dt; calEnd = null; announce('Check-in ' + dDisp(dt) + ' selected'); }
    else {
        if (dt <= calStart) { calStart = dt; calEnd = null; announce('Check-in ' + dDisp(dt) + ' selected'); }
        else { calEnd = dt; announce('Check-out ' + dDisp(dt) + ' selected'); }
    }
    renderCal();
}

function calDone(){
    if(!calStart || !calEnd) return;
    const p = new URLSearchParams({
        check_in: dStr(calStart),
        check_out: dStr(calEnd),
        guests: adultCount + childCount,
        adults: adultCount,
        children: childCount,
        rooms: roomCount
    });
    window.location.href = 'rooms.php?' + p.toString();
}

function calClear(){
    calStart = null; calEnd = null; calHoverDate = null;
    document.getElementById('dbCheckInVal').textContent = 'Select date';
    document.getElementById('dbCheckInVal').classList.add('is-placeholder');
    document.getElementById('dbCheckOutVal').textContent = 'Select date';
    document.getElementById('dbCheckOutVal').classList.add('is-placeholder');
    closeCal();
}

/* ── Guests ── */
function closeGuests(){
    document.getElementById('guestsFlyoutContainer').classList.remove('open');
    document.getElementById('dbGuestBox').setAttribute('aria-expanded', 'false');
}
function toggleDbGuests(e){
    e.stopPropagation();
    closeCal();
    const flyout = document.getElementById('guestsFlyoutContainer');
    const box    = document.getElementById('dbGuestBox');
    const open   = flyout.classList.toggle('open');
    box.setAttribute('aria-expanded', open);
}
let roomCount = URL_ROOMS, adultCount = URL_ADULTS, childCount = URL_CHILDREN;

function gUpdateLabel(){
    const txt = roomCount + ' room' + (roomCount !== 1 ? 's' : '') + ', ' +
                adultCount + ' adult' + (adultCount !== 1 ? 's' : '') + ', ' +
                childCount + ' child' + (childCount !== 1 ? 'ren' : '');
    document.getElementById('dbGuestVal').textContent = txt;
    document.getElementById('dbGuestBox').setAttribute('aria-label', 'Guests, ' + txt);

    // Keep the booking form's hidden fields in sync so "Book Now" always
    // submits the counts actually shown here, not stale page-load values.
    const fGuests   = document.getElementById('formGuests');
    const fAdults   = document.getElementById('formAdults');
    const fChildren = document.getElementById('formChildren');
    const fRooms    = document.getElementById('formRooms');
    if (fGuests)   fGuests.value   = adultCount + childCount;
    if (fAdults)   fAdults.value   = adultCount;
    if (fChildren) fChildren.value = childCount;
    if (fRooms)    fRooms.value    = roomCount;

    // Apply every +/- change instantly and keep the URL in sync without
    // refreshing or closing the guest popup.
    gSyncGuestsToUrl();
}
function gAdjRoom(d){
    roomCount = Math.max(1, roomCount + d);
    document.getElementById('gRoomCount').textContent = roomCount;
    gUpdateLabel();
    announce(roomCount + ' room' + (roomCount !== 1 ? 's' : '') + ' selected');
}
function gAdjAdult(d){
    adultCount = Math.max(1, adultCount + d);
    document.getElementById('gAdultCount').textContent = adultCount;
    gUpdateLabel();
}
function gAdjChild(d){
    childCount = Math.max(0, childCount + d);
    document.getElementById('gChildCount').textContent = childCount;
    gUpdateLabel();
}

// Keep the selected guest counts in the URL without reloading the page.
// This makes every +/- click apply immediately, just like index.php.
function gSyncGuestsToUrl(){
    const p = new URLSearchParams(window.location.search);
    p.set('check_in', URL_CI);
    p.set('check_out', URL_CO);
    p.set('guests', adultCount + childCount);
    p.set('adults', adultCount);
    p.set('children', childCount);
    p.set('rooms', roomCount);
    window.history.replaceState({}, '', 'rooms.php?' + p.toString());
}

document.addEventListener('click', function(e){
    const inBox  = document.getElementById('dbCheckInBox');
    const outBox = document.getElementById('dbCheckOutBox');
    const gBox   = document.getElementById('dbGuestBox');
    if (!inBox.contains(e.target) && !outBox.contains(e.target)) closeCal();
    if (gBox && !gBox.contains(e.target)) closeGuests();
});

/* ══════════════════════════════════════
   ID TYPE → SHOW ID PHOTO UPLOAD
══════════════════════════════════════ */
function handleIdTypeChange() {
    const idType = document.getElementById('idType').value;
    const field  = document.getElementById('idPhotoField');
    field.style.display = idType ? 'block' : 'none';
}

function handleIdPhotoChange() {
    const input = document.getElementById('idPhotoInput');
    const wrap  = document.getElementById('idUploadWrap');
    const label = document.getElementById('idUploadLabel');
    const file  = input.files[0];
    if (file) {
        wrap.classList.add('has-file');
        wrap.querySelector('i').className = 'fa-solid fa-circle-check';
        label.innerHTML = '<span class="id-upload-filename">' + file.name + '</span><br>Tap to change photo';
    } else {
        wrap.classList.remove('has-file');
        wrap.querySelector('i').className = 'fa-solid fa-camera';
        label.textContent = 'Tap to upload a photo of your ID';
    }
}

function handleReceiptChange() {
    const input = document.getElementById('paymentReceiptInput');
    const wrap  = document.getElementById('receiptUploadWrap');
    const label = document.getElementById('receiptUploadLabel');
    const file  = input.files[0];
    if (file) {
        wrap.classList.add('has-file');
        wrap.querySelector('i').className = 'fa-solid fa-circle-check';
        label.innerHTML = '<span class="id-upload-filename">' + file.name + '</span><br>Tap to change receipt';
    } else {
        wrap.classList.remove('has-file');
        wrap.querySelector('i').className = 'fa-solid fa-receipt';
        label.textContent = 'Tap to upload your payment receipt';
    }
}

/* ══════════════════════════════════════
   PAYMENT METHOD
══════════════════════════════════════ */
const PAYMENT_INFO = {
    'E-wallet': 'Send your deposit via GCash to <strong>+639629544504</strong>, then enter your reference number below. We\'ll verify it before confirming your booking.',
    'Bank Transfer': 'Transfer your deposit to CoraVergel Resort\'s bank account (details will be emailed to you), then enter your transaction reference number below.'
};

function handlePaymentMethodChange() {
    const selectEl  = document.getElementById('paymentMethod');
    const infoField = document.getElementById('paymentInfoField');
    const infoBox   = document.getElementById('paymentInfoBox');
    const receiptField = document.getElementById('paymentReceiptField');
    const receiptInput = document.getElementById('paymentReceiptInput');

    const val = selectEl.value;

    if (!val) {
        infoField.style.display = 'none';
        receiptField.style.display = 'none';
        receiptInput.removeAttribute('required');
        return;
    }

    infoBox.innerHTML = PAYMENT_INFO[val] || '';
    infoField.style.display = 'block';

    // Both remaining payment methods (GCash, Bank Transfer) require a receipt upload.
    receiptField.style.display = 'block';
    receiptInput.setAttribute('required', 'required');
}

/* ══════════════════════════════════════
   BOOKING MODAL
══════════════════════════════════════ */
let modalPrice = 0;

function openModal(roomId, price, img) {
    /* ── No dates yet: send user to dashboard to pick dates, carry room ── */
    if (!HAS_DATES) {
        window.location.href = 'index.php?room=' + encodeURIComponent(roomId) + '#booking-section';
        return;
    }
    modalPrice = price;
    document.getElementById('formRoomType').value         = roomId;
    document.getElementById('modalRoomName').textContent  = roomId;
    document.getElementById('modalRoomPrice').textContent = '₱' + price.toLocaleString();
    document.getElementById('modalImg').src               = img;
    const roomInfo = ROOM_MAP[roomId] || {};
    const capEl = document.getElementById('modalCapacity');
    if (capEl) capEl.textContent = roomInfo.cap ? 'Up to ' + roomInfo.cap + ' guests' : 'Guest capacity';
    updateTotal();

    // Reset payment method selection for a fresh booking
    const paymentSelect = document.getElementById('paymentMethod');
    if (paymentSelect) paymentSelect.value = '';
    document.getElementById('paymentInfoField').style.display = 'none';
    document.getElementById('paymentReceiptField').style.display = 'none';
    const receiptInput = document.getElementById('paymentReceiptInput');
    if (receiptInput) receiptInput.removeAttribute('required');

    document.getElementById('bookModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal(){
    document.getElementById('bookModal').classList.remove('open');
    document.body.style.overflow = '';
}

function updateTotal(){
    const el = document.getElementById('modalTotal');
    if (!el || !HAS_DATES) return;
    const nights = Math.round((new Date(URL_CO+'T00:00:00') - new Date(URL_CI+'T00:00:00')) / 86400000);
    if (nights > 0) {
        const total = '₱' + (modalPrice * nights).toLocaleString();
        el.textContent = total;
        const summaryEl = document.getElementById('modalTotalSummary');
        if (summaryEl) summaryEl.textContent = total;
    }
}

document.getElementById('bookModal').addEventListener('click', function(e){ if(e.target===this) closeModal(); });
document.addEventListener('keydown', e => { if(e.key==='Escape') closeModal(); });

/* ══════════════════════════════════════
   ROOM PREVIEW LIGHTBOX
══════════════════════════════════════ */
function openRoomLightbox(roomName, img) {
    document.getElementById('roomLightboxImg').src = img;
    document.getElementById('roomLightboxCaption').textContent = roomName;
    document.getElementById('roomLightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeRoomLightbox(e) {
    if (e) e.stopPropagation();
    document.getElementById('roomLightbox').classList.remove('open');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('roomLightbox').classList.contains('open')) {
        closeRoomLightbox();
    }
});

/* ── Auto-scroll alert ── */
<?php if ($booking_success || $booking_error): ?>
window.addEventListener('load', () => {
    const alertEl = document.getElementById('pageAlert');
    alertEl?.scrollIntoView({behavior:'smooth', block:'center'});

    // Auto-dismiss after 2 seconds
    setTimeout(() => {
        if (!alertEl) return;
        alertEl.style.transition = 'opacity .35s ease';
        alertEl.style.opacity = '0';
        setTimeout(() => alertEl.remove(), 350);
    }, 2000);
});
<?php endif; ?>

/* ── Auto-open modal if returning from dashboard with room + dates ── */
window.addEventListener('load', function() {
    <?php if ($has_dates): ?>
    const urlRoom = new URLSearchParams(window.location.search).get('room');
    if (urlRoom && ROOM_MAP[urlRoom]) {
        const r = ROOM_MAP[urlRoom];
        openModal(urlRoom, r.price, r.img);
    }
    <?php endif; ?>
});
function openDrawer() {
    document.getElementById('navDrawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('navHamburger').classList.add('open');
    const btn = document.getElementById('drawerCloseBtn');
    btn.style.display = 'flex';
    btn.style.position = 'fixed';
    btn.style.left = (document.getElementById('navDrawer').offsetWidth + 15) + 'px';
    document.body.style.overflow = 'hidden';
}
function closeDrawer() {
    document.getElementById('navDrawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('navHamburger').classList.remove('open');
    document.getElementById('drawerCloseBtn').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function () {
    const currentPage = window.location.pathname.split('/').pop() || 'index.php';
    document.querySelectorAll('.nav-links a').forEach(link => {
        const linkPage = link.getAttribute('href').split('#')[0].split('/').pop();
        if (linkPage === currentPage) {
            link.classList.add('active-link');
        }
    });
});
</script>

</body>
</html>