<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';
require_once '../config/availability.php';
require_once '../config/mailer.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: ../admin/admin_login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'];
$success = '';
$error   = '';

/* ── Handlers ── */
if (isset($_GET['delete_booking'])) {
    $stmt = $conn->prepare("DELETE FROM bookings WHERE booking_id = ?");
    $stmt->bind_param("i", intval($_GET['delete_booking'])); $stmt->execute(); $stmt->close();
    $success = "Booking deleted.";
}
if (isset($_GET['confirm_booking'])) {
    $bid = intval($_GET['confirm_booking']);

    // Look up this booking's room/dates so we can re-check availability
    // right before confirming — pending bookings don't hold a unit, so
    // two pending requests can exist for the same last unit at once.
    // This blocks confirming one if the room has already filled up
    // from OTHER confirmed bookings in the meantime.
    $stmt = $conn->prepare("SELECT room_type, check_in, check_out, guests, total_price, guest_name, guest_email FROM bookings WHERE booking_id = ?");
    $stmt->bind_param("i", $bid);
    $stmt->execute();
    $booking_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking_info) {
        $error = "Booking not found.";
    } elseif (!isRoomAvailable($conn, $booking_info['room_type'], $booking_info['check_in'], $booking_info['check_out'], $bid)) {
        $error = "Can't confirm — " . htmlspecialchars($booking_info['room_type']) . " has no units left for " . fmtAdminDate($booking_info['check_in']) . " to " . fmtAdminDate($booking_info['check_out']) . ". Cancel a conflicting booking first, or contact the guest about different dates.";
    } else {
        $stmt = $conn->prepare("UPDATE bookings SET status='confirmed' WHERE booking_id=?");
        $stmt->bind_param("i", $bid); $stmt->execute(); $stmt->close();

        $email_html = buildBookingConfirmationEmail(
            $booking_info['guest_name'],
            $booking_info['room_type'],
            $booking_info['check_in'],
            $booking_info['check_out'],
            $booking_info['guests'],
            $booking_info['total_price'],
            $bid
        );
        sendMail($booking_info['guest_email'], $booking_info['guest_name'], "Your CoraVergel Resort Booking is Confirmed", $email_html);

        $success = "Booking confirmed and email sent to " . htmlspecialchars($booking_info['guest_email']) . ".";
    }
}
if (isset($_GET['cancel_booking'])) {
    $stmt = $conn->prepare("UPDATE bookings SET status='cancelled' WHERE booking_id=?");
    $stmt->bind_param("i", intval($_GET['cancel_booking'])); $stmt->execute(); $stmt->close();
    $success = "Booking cancelled.";
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
                $success = "Room type added successfully.";
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                $error = "Could not add room — \"$room_name\" already exists.";
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_room') {
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
            $success = "Room updated successfully.";
        }
    }
}
if (isset($_GET['delete_room'])) {
    $room_id = intval($_GET['delete_room']);

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
    $success = "Room type removed.";
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

/* ── Occupancy (confirmed bookings this month / 30 days as rough %) ── */
$occ_result = $conn->query("SELECT COUNT(*) c FROM bookings WHERE status='confirmed' AND check_in>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND check_in<=LAST_DAY(CURDATE())");
$occ_count  = $occ_result ? $occ_result->fetch_assoc()['c'] : 0;
$occupancy  = min(100, round(($occ_count / max(1, 30)) * 100));

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

/* ── Rooms ── */
$rooms_list = [];
$rq = $conn->query("SELECT room_id, room_name, price, total_units, description, image, gallery, capacity, badge, tags FROM rooms ORDER BY room_name");
while ($row = $rq->fetch_assoc()) {
    $row['booked_today'] = countOverlappingBookings($conn, $row['room_name'], date('Y-m-d'), date('Y-m-d', strtotime('+1 day')));
    $row['confirmed_today'] = countOverlappingBookingsByStatus($conn, $row['room_name'], date('Y-m-d'), date('Y-m-d', strtotime('+1 day')), 'confirmed');
    $row['pending_today']   = countOverlappingBookingsByStatus($conn, $row['room_name'], date('Y-m-d'), date('Y-m-d', strtotime('+1 day')), 'pending');
    $rooms_list[] = $row;
}

/* ── Bookings ──
   No user_id on bookings — guest_name is the guest's identity as entered
   on the booking form. ── */
$bookings = [];
$bq = $conn->query("SELECT booking_id, guest_name full_name, guest_email, id_type, id_number, contact_number, room_type, check_in, check_out, guests, status, created_at, COALESCE(total_price,0) total_price
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
.topbar-search{position:relative;}
.search-dropdown{display:none;position:absolute;top:calc(100% + 8px);left:0;width:100%;min-width:400px;background:#fff;border-radius:10px;border:1px solid #e8e8e8;box-shadow:0 8px 32px rgba(0,0,0,.14);z-index:9999;overflow:hidden;max-height:440px;overflow-y:auto;}
.search-dropdown.open{display:block;}
.sd-section-label{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#aaa;padding:10px 16px 6px;background:#fafafa;border-bottom:1px solid #f0f0f0;}
.sd-section-label i{margin-right:5px;}
.sd-item{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:background .15s;border-bottom:1px solid #f5f5f5;}
.sd-item:last-child{border-bottom:none;}
.sd-item:hover{background:#f8f5f0;}
.sd-avatar{width:34px;height:34px;border-radius:50%;background:#1a1a2e;color:#c8a96e;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.sd-avatar.sd-avatar--booking{background:#f0f7ff;color:#1a6abf;}
.sd-body{flex:1;min-width:0;}
.sd-title{font-size:13px;font-weight:600;color:#1a1a2e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sd-title mark,.sd-meta mark{background:#fff3cd;color:#1a1a2e;border-radius:2px;padding:0 1px;font-style:normal;}
.sd-meta{font-size:11px;color:#999;margin-top:2px;}
.sd-badge{font-size:10px;font-weight:600;padding:3px 9px;border-radius:20px;flex-shrink:0;text-transform:capitalize;}
.sd-badge--confirmed{background:#e8f5e9;color:#2e7d32;}
.sd-badge--pending{background:#fff8e1;color:#f57f17;}
.sd-badge--cancelled{background:#fce4ec;color:#c62828;}
.sd-empty{padding:28px 16px;text-align:center;color:#bbb;font-size:13px;}
.sd-empty i{font-size:22px;display:block;margin-bottom:8px;color:#ddd;}
.sd-footer{padding:8px 16px;background:#fafafa;border-top:1px solid #f0f0f0;font-size:11px;color:#bbb;text-align:center;}
.ni--blue{background:#e8f0fe;color:#1a6abf;}
.filter-toggle-wrap{position:relative;display:inline-block;margin-bottom:18px;}
.filter-toggle-btn{display:inline-flex;align-items:center;gap:10px;padding:10px 18px;border-radius:10px;border:1.5px solid #e8e3db;background:#fff;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;color:#1a1a2e;cursor:pointer;transition:all .2s;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.filter-toggle-btn.open{background:#1a1a2e;color:#fff;border-color:#1a1a2e;}
.filter-toggle-btn .ftb-icon{width:28px;height:28px;border-radius:7px;background:#f5f0e8;color:#a07840;display:flex;align-items:center;justify-content:center;font-size:13px;transition:all .2s;}
.filter-toggle-btn.open .ftb-icon{background:rgba(255,255,255,.15);color:#c8a96e;}
.filter-toggle-btn .ftb-label{flex:1;}
.filter-toggle-btn .ftb-active-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:#c8a96e;color:#1a1a2e;margin-left:2px;transition:all .2s;}
.filter-toggle-btn.open .ftb-active-pill{background:rgba(200,169,110,.3);color:#0000;}
.filter-toggle-btn .ftb-chevron{font-size:11px;color:#bbb;transition:transform .2s;}
.filter-toggle-btn.open .ftb-chevron{transform:rotate(180deg);color:rgba(255,255,255,.5);}
.filter-dropdown{display:none;position:absolute;top:calc(100% + 8px);left:0;background:#fff;border-radius:12px;border:1px solid #e8e3db;box-shadow:0 8px 28px rgba(0,0,0,.14);z-index:999;min-width:220px;overflow:hidden;animation:fdIn .15s ease;}
.filter-dropdown.open{display:block;}
@keyframes fdIn{from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);}}
.fd-header{padding:10px 16px 8px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#aaa;border-bottom:1px solid #f0f0f0;background:#fafafa;}
.fd-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 16px;cursor:pointer;transition:background .12s;border-bottom:1px solid #f8f5f0;font-family:'DM Sans',sans-serif;}
.fd-item:last-child{border-bottom:none;}
.fd-item.fd-active{background:#f5f1eb;}
.fd-item-left{display:flex;align-items:center;gap:10px;}
.fd-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.fd-dot--all{background:#1a1a2e;}
.fd-dot--pending{background:#ff9800;}
.fd-dot--confirmed{background:#4caf50;}
.fd-dot--cancelled{background:#f44336;}
.fd-item-label{font-size:13px;font-weight:500;color:#333;}
.fd-item.fd-active .fd-item-label{font-weight:700;color:#1a1a2e;}
.fd-count{font-size:11px;font-weight:700;padding:3px 9px;border-radius:10px;background:#f0ede8;color:#888;}
.fd-item.fd-active .fd-count{background:#1a1a2e;color:#fff;}
.fd-check{font-size:12px;color:#4caf50;display:none;}
.fd-item.fd-active .fd-check{display:block;}.date-filter-wrap{position:relative;}
.btn-filter{display:inline-flex;align-items:center;gap:10px;padding:8px 14px 8px 10px;border:1.5px solid #e8e5df;border-radius:12px;background:#fff;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .2s;white-space:nowrap;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.btn-filter:hover{border-color:#1a1a2e;box-shadow:0 2px 8px rgba(0,0,0,.1);}
.btn-filter.active{border-color:#1a1a2e;background:#1a1a2e;}
.btn-filter.active .btn-filter-icon{background:rgba(255,255,255,.15);color:#c8a96e;}
.btn-filter.active .btn-filter-label{color:rgba(255,255,255,.6);}
.btn-filter.active .btn-filter-val{color:#fff;}
.btn-filter.active .btn-filter-chevron{color:rgba(255,255,255,.6);transform:rotate(180deg);}
.btn-filter-icon{width:32px;height:32px;border-radius:8px;background:#f5f0e8;color:#a07840;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;transition:all .2s;}
.btn-filter-text{display:flex;flex-direction:column;align-items:flex-start;gap:1px;}
.btn-filter-label{font-size:10px;font-weight:600;color:#aaa;letter-spacing:.06em;text-transform:uppercase;line-height:1;transition:color .2s;}
.btn-filter-val{font-size:13px;font-weight:600;color:#1a1a2e;line-height:1.2;transition:color .2s;}
.btn-filter-chevron{font-size:10px;color:#bbb;margin-left:2px;transition:all .2s;flex-shrink:0;}
#bookingsTable{min-width:960px;}
.table-wrap{overflow-x:auto;overflow-y:visible;}
#section-bookings .table-card{overflow:visible;}
.action-dropdown{display:none;position:fixed;background:var(--white);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow-md);min-width:148px;z-index:9999;overflow:hidden;animation:adDropIn .15s ease;}
.action-menu .action-dropdown{display:none;}
.action-menu.open .action-dropdown{display:block;}
@keyframes adDropIn{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:translateY(0);}}
.bmodal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;}
.bmodal-overlay.open{display:flex;}
.bmodal-box{background:#fff;border-radius:16px;width:100%;max-width:1000px;margin:20px;overflow:hidden;border:0.5px solid #e0ddd8;max-height:calc(100vh - 40px);overflow-y:auto;}
.bmodal-top{background:#1a1a2e;padding:24px 28px;}
.bmodal-top-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.bmodal-label{font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:rgba(200,169,110,.7);}
.bmodal-close{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.1);border:none;color:rgba(255,255,255,.6);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.bmodal-close:hover{background:rgba(255,255,255,.2);color:#fff;}
.bmodal-identity{display:flex;align-items:center;gap:14px;}
.bmodal-icon{width:48px;height:48px;border-radius:12px;background:rgba(76,175,80,.15);border:0.5px solid rgba(76,175,80,.3);color:#66bb6a;font-size:20px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.bmodal-title{font-size:20px;font-weight:700;color:#fff;font-family:'Cormorant Garamond',serif;margin-bottom:3px;}
.bmodal-guest{font-size:13px;color:rgba(255,255,255,.55);}
.bmodal-guest span{color:#c8a96e;font-weight:600;}
.bmodal-conf-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;background:rgba(76,175,80,.15);border:0.5px solid rgba(76,175,80,.3);color:#66bb6a;font-size:11px;font-weight:600;margin-top:6px;}
.bmodal-body{padding:24px 28px;}
.bmodal-fields{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px;}
.bmodal-field{padding:12px 14px;border-radius:10px;background:#fafaf8;border:0.5px solid #f0ede8;}
.bmodal-field-lbl{font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#bbb;margin-bottom:4px;}
.bmodal-field-val{font-size:14px;font-weight:600;color:#1a1a2e;}
.bmodal-divider{height:0.5px;background:#f0ede8;margin:16px 0;}
.bmodal-summary{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:10px;background:#fafaf8;border:0.5px solid #f0ede8;}
.bmodal-summary-item{text-align:center;}
.bmodal-summary-lbl{font-size:10px;font-weight:600;color:#bbb;text-transform:uppercase;letter-spacing:.06em;margin-bottom:3px;}
.bmodal-summary-val{font-size:16px;font-weight:700;color:#1a1a2e;}
.bmodal-summary-sep{width:0.5px;height:36px;background:#e8e5e0;}
.bmodal-footer{padding:16px 28px 24px;}
.bmodal-close-btn{width:100%;padding:12px;border-radius:10px;background:#1a1a2e;color:#fff;border:none;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s;}
.bmodal-close-btn:hover{background:#2d2d4e;}
.btn-view-info{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;border:1px solid #4caf50;background:#e8f5e9;color:#2e7d32;font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;white-space:nowrap;}
.btn-view-info:hover{background:#4caf50;color:#fff;}
.flatpickr-months{background:#fff!important;padding:10px 0 6px!important;}
.flatpickr-month,.flatpickr-current-month .flatpickr-monthDropdown-months,.flatpickr-current-month input.cur-year{color:#1a1a2e!important;fill:#1a1a2e!important;font-family:'DM Sans',sans-serif!important;font-size:14px!important;font-weight:600!important;}
.flatpickr-weekdays,.flatpickr-weekdaycontainer{background:#fff!important;display:flex!important;width:100%!important;}
span.flatpickr-weekday{font-size:11px!important;font-weight:600!important;color:#bbb!important;background:#fff!important;flex:1!important;text-align:center!important;}
.flatpickr-day{font-family:'DM Sans',sans-serif!important;font-size:13px!important;color:#333!important;border-radius:0!important;max-width:39px!important;height:39px!important;line-height:39px!important;}
.flatpickr-day:hover{background:#f0ede8!important;border-color:#f0ede8!important;}
.flatpickr-day.today{font-weight:700!important;color:#1a1a2e!important;box-shadow:inset 0 0 0 1.5px #252545!important;background:transparent!important;border-color:transparent!important;}
.flatpickr-day.selected,.flatpickr-day.startRange,.flatpickr-day.endRange,.flatpickr-day.selected:hover,.flatpickr-day.startRange:hover,.flatpickr-day.endRange:hover{background:#252545!important;border-color:#252545!important;color:#fff!important;}
.flatpickr-day.inRange{background:#e8e8e8!important;border-color:#e8e8e8!important;color:#333!important;box-shadow:-5px 0 0 #e8e8e8,5px 0 0 #e8e8e8!important;}
.flatpickr-day.prevMonthDay,.flatpickr-day.nextMonthDay{color:#ccc!important;}
.flatpickr-prev-month,.flatpickr-next-month{color:#888!important;fill:#888!important;}
.flatpickr-prev-month:hover,.flatpickr-next-month:hover{color:#1a1a2e!important;fill:#1a1a2e!important;background:transparent!important;}
.dayContainer{display:flex!important;flex-wrap:wrap!important;width:307px!important;min-width:307px!important;max-width:307px!important;justify-content:space-around!important;}
.flatpickr-rContainer,.flatpickr-days{display:block!important;width:307px!important;}
.flatpickr-innerContainer{display:block!important;}
.overview-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;}
.overview-grid--wide{grid-template-columns:2fr 1fr;}
.kpi-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;}
.kpi-card{background:#fff;border:1px solid #f0ece4;border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);transition:box-shadow .2s;}
.kpi-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
.kpi-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.kpi-body{min-width:0;}
.kpi-label{font-size:11px;color:#aaa;font-weight:500;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;}
.kpi-value{font-size:22px;font-weight:600;line-height:1.1;color:#1a1a2e;}
.kpi-change{font-size:11px;margin-top:3px;display:flex;align-items:center;gap:3px;}
.kpi-change.up{color:#2e7d32;} .kpi-change.down{color:#c62828;} .kpi-change.neutral{color:#999;}
.quick-actions-bar{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #f0ece4;border-radius:12px;padding:12px 16px;margin-bottom:16px;flex-wrap:wrap;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.qa-label{font-size:11px;font-weight:600;color:#aaa;letter-spacing:.06em;text-transform:uppercase;margin-right:4px;white-space:nowrap;}
.qa-action-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;border:1.5px solid #e8e3db;background:#fafaf8;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:500;color:#555;cursor:pointer;transition:all .18s;white-space:nowrap;}
.qa-action-btn i{font-size:12px;}
.qa-action-btn:hover{border-color:#1a1a2e;color:#1a1a2e;background:#f5f1eb;transform:translateY(-1px);box-shadow:0 3px 8px rgba(0,0,0,.08);}
.qa-action-btn.qa-green{border-color:#b8ddb8;background:#f0faf0;color:#2e7d32;}
.qa-action-btn.qa-green:hover{background:#2e7d32;color:#fff;border-color:#2e7d32;}
.qa-action-btn.qa-gold{border-color:#e8d5a3;background:#fffbf0;color:#a07840;}
.qa-action-btn.qa-gold:hover{background:#a07840;color:#fff;border-color:#a07840;}
.qa-action-btn.qa-red{border-color:#f0b8b8;background:#fff5f5;color:#c62828;}
.qa-action-btn.qa-red:hover{background:#c62828;color:#fff;border-color:#c62828;}
.qa-action-btn.qa-blue{border-color:#b8d0f0;background:#f0f5ff;color:#1a6abf;}
.qa-action-btn.qa-blue:hover{background:#1a6abf;color:#fff;border-color:#1a6abf;}
.mini-cal-card{background:#fff;border:1px solid #f0ece4;border-radius:12px;padding:0;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.mc-header{padding:16px 20px 12px;border-bottom:1px solid #f5f2ed;display:flex;align-items:center;justify-content:space-between;}
.mc-title{font-size:14px;font-weight:600;color:#1a1a2e;}
.mc-nav{display:flex;align-items:center;gap:6px;}
.mc-nav-btn{width:26px;height:26px;border-radius:6px;border:1px solid #e8e5df;background:#fafaf8;color:#888;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;transition:all .15s;}
.mc-nav-btn:hover{border-color:#1a1a2e;color:#1a1a2e;background:#f5f1eb;}
.mc-month-label{font-size:12px;font-weight:600;color:#1a1a2e;min-width:80px;text-align:center;}
.mc-body{padding:12px 16px 0;}
.mc-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;}
.mc-day-head{font-size:10px;font-weight:600;color:#bbb;text-align:center;padding:4px 0;}
.mc-day{font-size:11px;text-align:center;padding:5px 2px;border-radius:6px;cursor:pointer;position:relative;transition:background .1s;color:#555;line-height:1.4;}
.mc-day:hover{background:#f5f1eb;color:#1a1a2e;}
.mc-day.today{background:#1a1a2e!important;color:#c8a96e!important;font-weight:600;}
.mc-day.has-booking::after{content:'';position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:4px;height:4px;border-radius:50%;background:#c8a96e;}
.mc-day.busy{background:#fff8e8;}
.mc-day.other-month{color:#ddd;}
.mc-legend{display:flex;gap:12px;padding:10px 16px;border-top:1px solid #f5f2ed;margin-top:8px;}
.mc-legend-item{display:flex;align-items:center;gap:5px;font-size:10px;color:#aaa;}
.mc-legend-dot{width:7px;height:7px;border-radius:50%;}
.mc-upcoming{padding:12px 16px;border-top:1px solid #f5f2ed;}
.mc-upcoming-title{font-size:11px;font-weight:600;color:#aaa;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;}
.mc-checkin-item{display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #faf8f5;font-size:12px;}
.mc-checkin-item:last-child{border-bottom:none;}
.mc-checkin-date{font-weight:600;color:#1a1a2e;min-width:44px;}
.mc-checkin-guest{color:#555;flex:1;margin:0 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.status-pill{font-size:10px;font-weight:600;padding:2px 8px;border-radius:10px;white-space:nowrap;}
.sp-confirmed{background:#e8f5e9;color:#2e7d32;}
.sp-pending{background:#fff8e1;color:#e65100;}
.rev-card{background:#fff;border:1px solid #f0ece4;border-radius:12px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.rev-card-title{font-size:14px;font-weight:600;color:#1a1a2e;margin-bottom:4px;}
.rev-card-sub{font-size:11px;color:#aaa;margin-bottom:16px;}
.rev-total{font-size:28px;font-weight:700;color:#1a1a2e;margin-bottom:4px;}
.rev-change{font-size:12px;display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-weight:600;}
.rev-change.up{background:#e8f5e9;color:#2e7d32;}
.rev-change.down{background:#fce4ec;color:#c62828;}
.rev-change.neutral{background:#f5f5f5;color:#888;}
.rev-breakdown{margin-top:16px;}
.rev-row{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
.rev-room-name{font-size:12px;color:#555;min-width:90px;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.rev-bar-bg{flex:1;height:6px;background:#f5f2ed;border-radius:3px;overflow:hidden;}
.rev-bar-fill{height:100%;border-radius:3px;background:#1a1a2e;transition:width .6s ease;}
.rev-bar-fill.alt1{background:#c8a96e;}
.rev-bar-fill.alt2{background:#a07840;}
.rev-bar-fill.alt3{background:#e8d5a3;}
.rev-amt{font-size:11px;font-weight:600;color:#1a1a2e;min-width:52px;text-align:right;}
.charts-row-new{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px;}
.chart-card-new{background:#fff;border:1px solid #f0ece4;border-radius:12px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.chart-card-header-new{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;}
.chart-card-header-new h3{font-size:14px;font-weight:600;color:#1a1a2e;margin:0;}
.chart-card-header-new p{font-size:11px;color:#aaa;margin:3px 0 0;}
.ad-item.ad-view {
    outline: none;
    border: none;
    box-shadow: none;
    background: transparent;
    width: 100%;
    text-align: left;
    cursor: pointer;
    font-family: inherit;
    font-size: inherit;
    color: inherit;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ad-item.ad-view:focus,
.ad-item.ad-view:focus-visible {
    outline: none;
    border: none;
    box-shadow: none;
    background: transparent;
}
.ad-item.ad-view:hover {
    background: #f8f5f0;
}
.bottom-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}

/* ══════════════════════════════════════
   ANIMATED HAMBURGER (matches guest dashboard)
   Replaces the plain fa-bars icon on the
   sidebar-toggle button.
══════════════════════════════════════ */
.sidebar-toggle.nav-hamburger{
    width:38px; height:38px; background:transparent; border:none;
    cursor:pointer; display:flex; flex-direction:column; align-items:center;
    justify-content:center; gap:5px; padding:0;
}
.sidebar-toggle.nav-hamburger span{
    display:block; width:20px; height:2px; background:#1a1a2e;
    border-radius:2px; transition:transform .25s ease, opacity .2s ease;
}
.sidebar-toggle.nav-hamburger.open span:nth-child(1){ transform:translateY(7px) rotate(45deg); }
.sidebar-toggle.nav-hamburger.open span:nth-child(2){ opacity:0; }
.sidebar-toggle.nav-hamburger.open span:nth-child(3){ transform:translateY(-7px) rotate(-45deg); }
.photo-drop-zone{border:2px dashed #e0dbd0;border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;background:#fafaf8;}
.photo-drop-zone:hover,.photo-drop-zone.dragover{border-color:#c8a96e;background:#fdfbf6;}
.photo-drop-zone i{font-size:22px;color:#c8a96e;margin-bottom:6px;display:block;}
.photo-drop-zone .pdz-title{font-size:13px;font-weight:600;color:#1a1a2e;}
.photo-drop-zone .pdz-sub{font-size:11px;color:#aaa;margin-top:2px;}
.photo-thumb-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:12px;}
.photo-thumb{position:relative;aspect-ratio:1;border-radius:8px;overflow:hidden;border:1px solid #f0ede8;}
.photo-thumb img{width:100%;height:100%;object-fit:cover;}
.photo-thumb-remove{position:absolute;top:3px;right:3px;width:18px;height:18px;border-radius:50%;background:rgba(0,0,0,.6);color:#fff;border:none;font-size:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;}
.photo-thumb-remove:hover{background:#dc2626;}
.photo-thumb-existing{position:relative;aspect-ratio:1;border-radius:8px;overflow:hidden;border:1px solid #f0ede8;}
.photo-thumb-existing img{width:100%;height:100%;object-fit:cover;}
.photo-count-pill{font-size:11px;font-weight:600;color:#a07840;margin-top:6px;}
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
        <a href="../frontend/guest.php" class="sb-item" target="_blank">
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
                <input type="text" placeholder="Search bookings..."
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
            <button class="qa-action-btn qa-red" onclick="filterByStatus('cancelled',document.querySelector('.qpill--cancelled'));showSection('bookings',document.querySelectorAll('.sb-item')[1])">
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
                            <span style="width:10px;height:10px;border-radius:2px;background:#1a1a2e;display:inline-block"></span>Bookings
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
                <i class="fa-solid fa-check fd-check"></i>
            </div>
        </div>
        <div class="fd-item" id="fd-pending" onclick="selectFilter('pending','Pending',this)">
            <div class="fd-item-left">
                <span class="fd-dot fd-dot--pending"></span>
                <span class="fd-item-label">Pending</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <i class="fa-solid fa-check fd-check"></i>
            </div>
        </div>
        <div class="fd-item" id="fd-confirmed" onclick="selectFilter('confirmed','Confirmed',this)">
            <div class="fd-item-left">
                <span class="fd-dot fd-dot--confirmed"></span>
                <span class="fd-item-label">Confirmed</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <i class="fa-solid fa-check fd-check"></i>
            </div>
        </div>
        <div class="fd-item" id="fd-cancelled" onclick="selectFilter('cancelled','Cancelled',this)">
            <div class="fd-item-left">
                <span class="fd-dot fd-dot--cancelled"></span>
                <span class="fd-item-label">Cancelled</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <i class="fa-solid fa-check fd-check"></i>
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
                        <button class="btn-filter" id="dateFilterBtn" onclick="toggleDateFilter()">
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
                                $bm_idnumber = htmlspecialchars($b['id_number'] ?? '', ENT_QUOTES);
                                $bm_contact  = htmlspecialchars($b['contact_number'] ?? '', ENT_QUOTES);
                                $bm_view_call = "openBookingModal('$bm_name','$bm_room','$bm_checkin','$bm_checkout',$nights,{$b['guests']},'$bm_booked','$bm_email','$bm_idtype','$bm_idnumber','$bm_contact','{$b['status']}')";
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
                                        <a href="admin_dashboard.php?confirm_booking=<?=$b['booking_id']?>"
                                           onclick="return confirm('Confirm this booking?')" class="ad-item ad-confirm">
                                            <i class="fa-solid fa-circle-check"></i> Confirm
                                        </a>
                                        <a href="admin_dashboard.php?cancel_booking=<?=$b['booking_id']?>"
                                           onclick="return confirm('Cancel this booking?')" class="ad-item ad-cancel">
                                            <i class="fa-solid fa-ban"></i> Cancel
                                        </a>
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
                        <tr><th>#</th><th>Photo</th><th>Room Name</th><th>Price / night</th><th>Total Units</th><th>Booked Today</th><th>Actions</th></tr>
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
                            <td><?=$rm['total_units']?></td>
                            <td>
                                <?php if($rm['booked_today'] >= $rm['total_units']): ?>
                                    <span class="status-badge status--cancelled"><i class="fa-solid fa-ban"></i> Full (<?=$rm['booked_today']?>/<?=$rm['total_units']?>)</span>
                                <?php else: ?>
                                    <span class="status-badge status--confirmed"><?=$rm['booked_today']?>/<?=$rm['total_units']?> booked</span>
                                <?php endif; ?>
                                <?php if($rm['pending_today'] > 0): ?>
                                <div style="font-size:10px;color:#e65100;margin-top:3px;">
                                    <i class="fa-solid fa-clock" style="font-size:9px;"></i> +<?=$rm['pending_today']?> pending (not yet counted)
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
                                <a href="admin_dashboard.php?delete_room=<?=$rm['room_id']?>"
                                   onclick="return confirm('Delete this room type? Existing bookings for it will remain in history but no new bookings can be made for it.')"
                                   class="btn-view-info" style="border-color:#dc2626;background:#fff5f5;color:#dc2626;margin-left:6px;">
                                    <i class="fa-solid fa-trash"></i> Delete
                                </a>
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
            <div class="bmodal-fields">
                <div class="bmodal-field">
                    <div class="bmodal-field-lbl">Email</div>
                    <div class="bmodal-field-val" id="bm-email" style="font-size:13px;"></div>
                </div>
                <div class="bmodal-field">
                    <div class="bmodal-field-lbl">Contact number</div>
                    <div class="bmodal-field-val" id="bm-contact"></div>
                </div>
                <div class="bmodal-field">
                    <div class="bmodal-field-lbl">Booked on</div>
                    <div class="bmodal-field-val" id="bm-booked" style="font-size:13px;"></div>
                </div>
            </div>
            <div class="bmodal-fields">
                <div class="bmodal-field" style="grid-column:span 1.5;">
                    <div class="bmodal-field-lbl">ID type</div>
                    <div class="bmodal-field-val" id="bm-idtype"></div>
                </div>
                <div class="bmodal-field" style="grid-column:span 1.5;">
                    <div class="bmodal-field-lbl">ID number</div>
                    <div class="bmodal-field-val" id="bm-idnumber"></div>
                </div>
            </div>

            <div class="bmodal-divider"></div>
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
                } else { dfpFrom=''; dfpTo=''; }
            },
            onOpen:function(){ btn.classList.add('active'); chevron.style.transform='rotate(180deg)'; },
            onClose:function(){ btn.classList.remove('active'); chevron.style.transform=''; }
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
function filterByStatus(status,el){ selectFilter(status,status.charAt(0).toUpperCase()+status.slice(1),document.getElementById('fd-'+status)); }
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

const bookingData=<?=json_encode(array_map(fn($b)=>['id'=>$b['booking_id'],'name'=>$b['full_name'],'room'=>$b['room_type'],'checkin'=>$b['check_in'],'checkout'=>$b['check_out'],'status'=>$b['status']],$bookings))?>;
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

function toggleMenu(id, e) {
    e.stopPropagation();
    const wrap = document.getElementById('am-' + id);
    const isOpen = wrap.classList.contains('open');
    document.querySelectorAll('.action-menu.open').forEach(m => m.classList.remove('open'));
    if (!isOpen) { wrap.classList.add('open'); positionDrop(wrap); }
}

function positionDrop(wrap) {
    const btn = wrap.querySelector('.action-btn');
    const rect = btn.getBoundingClientRect();
    const drop = wrap.querySelector('.action-dropdown');
    drop.style.top = (rect.bottom + window.scrollY + 6) + 'px';
    drop.style.left = 'auto';
    drop.style.right = (window.innerWidth - rect.right) + 'px';
}

function openBookingModal(name,room,checkin,checkout,nights,guests,booked,email,idType,idNumber,contact,status){
    document.getElementById('bm-name').textContent=name; document.getElementById('bm-room').textContent=room;
    document.getElementById('bm-checkin').textContent=checkin; document.getElementById('bm-checkout').textContent=checkout;
    document.getElementById('bm-nights').textContent=nights+' night'+(nights!=1?'s':'');
    document.getElementById('bm-guests').textContent=guests+' guest'+(guests!=1?'s':'');
    document.getElementById('bm-booked').textContent=booked;
    document.getElementById('bm-email').textContent=email||'Not provided';
    document.getElementById('bm-idtype').textContent=idType||'Not provided';
    document.getElementById('bm-idnumber').textContent=idNumber||'Not provided';
    document.getElementById('bm-contact').textContent=contact||'Not provided';

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
    if(adminPicker&&adminPicker.isOpen){ const dfw=document.querySelector('.date-filter-wrap'); if(dfw&&!dfw.contains(e.target)) adminPicker.close(); }
    const fw=document.getElementById('filterToggleWrap'); if(fw&&!fw.contains(e.target)){document.getElementById('filterToggleBtn').classList.remove('open');document.getElementById('filterDropdown').classList.remove('open');}
    document.querySelectorAll('.action-menu.open').forEach(m=>m.classList.remove('open'));
});
document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ document.getElementById('searchDropdown').classList.remove('open'); document.getElementById('globalSearch').value=''; closeBookingModal(); if(adminPicker) adminPicker.close(); } });

const alertEl=document.getElementById('dashAlert');
if(alertEl){ setTimeout(()=>{ alertEl.style.transition='opacity 0.5s'; alertEl.style.opacity='0'; setTimeout(()=>alertEl.remove(),500); },4000); }
</script>
</body>
</html>