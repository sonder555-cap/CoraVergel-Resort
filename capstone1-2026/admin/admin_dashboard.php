<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';
require_once '../config/availability.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: ../user/login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'];
$success = '';
$error   = '';

/* ── Handlers ── */
if (isset($_GET['delete_user'])) {
    $del_id = intval($_GET['delete_user']);
    if ($del_id === $_SESSION['admin_id']) {
        $error = "You cannot delete your own admin account.";
    } else {
        $conn->query("DELETE FROM bookings WHERE user_id = $del_id");
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role != 'admin'");
        $stmt->bind_param("i", $del_id); $stmt->execute(); $stmt->close();
        $success = "User deleted successfully.";
    }
}
if (isset($_GET['delete_booking'])) {
    $stmt = $conn->prepare("DELETE FROM bookings WHERE booking_id = ?");
    $stmt->bind_param("i", intval($_GET['delete_booking'])); $stmt->execute(); $stmt->close();
    $success = "Booking deleted.";
}
if (isset($_GET['confirm_booking'])) {
    $bid = intval($_GET['confirm_booking']);
    $stmt = $conn->prepare("UPDATE bookings SET status='confirmed' WHERE booking_id=?");
    $stmt->bind_param("i", $bid); $stmt->execute(); $stmt->close();
    $success = "Booking confirmed.";
}
if (isset($_GET['cancel_booking'])) {
    $stmt = $conn->prepare("UPDATE bookings SET status='cancelled' WHERE booking_id=?");
    $stmt->bind_param("i", intval($_GET['cancel_booking'])); $stmt->execute(); $stmt->close();
    $success = "Booking cancelled.";
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_room') {
    $room_name   = htmlspecialchars(strip_tags(trim($_POST['room_name'])), ENT_QUOTES, 'UTF-8');
    $price       = (float) $_POST['price'];
    $total_units = intval($_POST['total_units']);
    $description = htmlspecialchars(strip_tags(trim($_POST['description'] ?? '')), ENT_QUOTES, 'UTF-8');

    if (empty($room_name) || $price <= 0 || $total_units < 1) {
        $error = "Please fill in all room fields with valid values.";
    } else {
        $image_filename = null;
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $file_type     = mime_content_type($_FILES['image']['tmp_name']);

            if (!in_array($file_type, $allowed_types)) {
                $error = "Room image must be a JPG, PNG, or WEBP file.";
            } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                $error = "Room image must be under 5MB.";
            } else {
                $upload_dir = '../assets/images/rooms/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $image_filename = 'room_' . uniqid() . '.' . strtolower($ext);
                move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_filename);
            }
        }

        if (empty($error)) {
            try {
                $stmt = $conn->prepare("INSERT INTO rooms (room_name, price, total_units, description, image) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sdiss", $room_name, $price, $total_units, $description, $image_filename);
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
    $description = htmlspecialchars(strip_tags(trim($_POST['description'] ?? '')), ENT_QUOTES, 'UTF-8');

    if ($price <= 0 || $total_units < 1) {
        $error = "Please enter a valid price and unit count.";
    } else {
        $image_filename = null; // null = don't touch the image column
        if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $file_type     = mime_content_type($_FILES['image']['tmp_name']);

            if (!in_array($file_type, $allowed_types)) {
                $error = "Room image must be a JPG, PNG, or WEBP file.";
            } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                $error = "Room image must be under 5MB.";
            } else {
                $upload_dir = '../assets/images/rooms/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $image_filename = 'room_' . uniqid() . '.' . strtolower($ext);
                move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_filename);
            }
        }

        if (empty($error)) {
            if ($image_filename !== null) {
                $stmt = $conn->prepare("UPDATE rooms SET price = ?, total_units = ?, description = ?, image = ? WHERE room_id = ?");
                $stmt->bind_param("dissi", $price, $total_units, $description, $image_filename, $room_id);
            } else {
                $stmt = $conn->prepare("UPDATE rooms SET price = ?, total_units = ?, description = ? WHERE room_id = ?");
                $stmt->bind_param("disi", $price, $total_units, $description, $room_id);
            }
            $stmt->execute();
            $stmt->close();
            $success = "Room updated successfully.";
        }
    }
}
if (isset($_GET['delete_room'])) {
    $room_id = intval($_GET['delete_room']);
    $stmt = $conn->prepare("DELETE FROM rooms WHERE room_id = ?");
    $stmt->bind_param("i", $room_id);
    $stmt->execute();
    $stmt->close();
    $success = "Room type removed.";
}

/* ── Stats ── */
$total_bookings = $conn->query("SELECT COUNT(*) c FROM bookings")->fetch_assoc()['c'];
$total_users    = $conn->query("SELECT COUNT(*) c FROM users WHERE role='user'")->fetch_assoc()['c'];
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

/* ── New guests this month ── */
$new_guests_month = $conn->query("SELECT COUNT(*) c FROM users WHERE role='user' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetch_assoc()['c'];

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

/* ── Upcoming check-ins (next 7 days) ── */
$upcoming_checkins = [];
$uc = $conn->query("SELECT b.booking_id,u.full_name,b.room_type,b.check_in,b.status FROM bookings b JOIN users u ON b.user_id=u.user_id WHERE b.check_in BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY) ORDER BY b.check_in ASC LIMIT 5");
if ($uc) while ($row = $uc->fetch_assoc()) $upcoming_checkins[] = $row;

/* ── Rooms ── */
$rooms_list = [];
$rq = $conn->query("SELECT room_id, room_name, price, total_units, description, image FROM rooms ORDER BY room_name");
while ($row = $rq->fetch_assoc()) {
    $row['booked_today'] = countOverlappingBookings($conn, $row['room_name'], date('Y-m-d'), date('Y-m-d', strtotime('+1 day')));
    $rooms_list[] = $row;
}

/* ── Bookings ── */
$bookings = [];
$bq = $conn->query("SELECT b.booking_id,u.full_name,b.room_type,b.check_in,b.check_out,b.guests,b.status,b.created_at,COALESCE(b.total_price,0) total_price FROM bookings b JOIN users u ON b.user_id=u.user_id ORDER BY b.created_at DESC");
while ($row = $bq->fetch_assoc()) $bookings[] = $row;

/* ── Users ── */
$users = [];
$uq = $conn->query("SELECT user_id,full_name,email,phone,role,created_at FROM users ORDER BY created_at DESC");
while ($row = $uq->fetch_assoc()) $users[] = $row;

/* ── Notifications ── */
$notifications = [];
$nq = $conn->query("SELECT 'booking' notif_type,b.booking_id,u.full_name,b.room_type,b.check_in,b.check_out,b.status,b.created_at FROM bookings b JOIN users u ON b.user_id=u.user_id ORDER BY b.created_at DESC LIMIT 15");
while ($row = $nq->fetch_assoc()) $notifications[] = $row;

$unotif = $conn->query("SELECT 'new_user' notif_type,user_id booking_id,full_name,'' room_type,'' check_in,'' check_out,'new' status,created_at FROM users WHERE role='user' ORDER BY created_at DESC LIMIT 10");
while ($row = $unotif->fetch_assoc()) $notifications[] = $row;

usort($notifications, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$notifications = array_slice($notifications, 0, 20);

$unread_count = array_reduce($notifications, function($c,$n) {
    if ($n['notif_type']==='booking' && $n['status']==='pending') return $c+1;
    if ($n['notif_type']==='new_user' && date('Y-m-d',strtotime($n['created_at']))===date('Y-m-d')) return $c+1;
    return $c;
}, 0);

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
<meta name="port" content="width=device-width, initial-scale=1.0">
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
        <img src="../assets/images/logo/cv_logo.png" alt="Logo" class="sb-logo">
        <div class="sb-brand-text">
            <span class="sb-name">CoraVergel Resort</span>
            <span class="sb-sub">Admin Panel</span>
        </div>
    </div>
    <div class="sb-nav">
        <div class="sb-group-label">MAIN</div>
        <button class="sb-item active" onclick="showSection('overview',this);closeSidebarMobile();">
            <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
        </button>
        <div class="sb-group-label">MANAGEMENT</div>
        <button class="sb-item" onclick="showSection('bookings',this);closeSidebarMobile();">
            <i class="fa-solid fa-calendar-check"></i><span>Bookings</span>
            <?php if($pending_count>0): ?><span class="sb-badge"><?=$pending_count?></span><?php endif; ?>
        </button>
        <button class="sb-item" onclick="showSection('users',this);closeSidebarMobile();">
            <i class="fa-solid fa-users"></i><span>Guests</span>
        </button>
        <button class="sb-item" onclick="showSection('rooms',this);closeSidebarMobile();">
            <i class="fa-solid fa-bed"></i><span>Rooms</span>
        </button>
        <div class="sb-group-label">SITE</div>
        <a href="../frontend/guest.php" class="sb-item" target="_blank">
            <i class="fa-solid fa-globe"></i><span>View Website</span>
        </a>
    </div>
    <div class="sb-footer">
        <div class="sb-admin">
            <div class="sb-avatar"><?=strtoupper(substr($admin_name,0,2))?></div>
            <div class="sb-admin-info">
                <span class="sb-admin-name"><?=htmlspecialchars($admin_name)?></span>
                <span class="sb-admin-role">Administrator</span>
            </div>
        </div>
        <a href="../user/logout.php" class="sb-logout">
            <i class="fa-solid fa-right-from-bracket"></i> Sign Out
        </a>
    </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeSidebarMobile()"></div>

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
                <input type="text" placeholder="Search bookings, guests..."
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
                            $ago         = human_time_diff(strtotime($n['created_at']));
                            $is_new_user = $n['notif_type']==='new_user';
                            $is_today    = date('Y-m-d',strtotime($n['created_at']))===date('Y-m-d');
                            $is_unread   = (!$is_new_user && $n['status']==='pending') || ($is_new_user && $is_today);
                            if($is_new_user){ $icon='fa-user-plus'; $icon_cls='ni--blue'; }
                            else {
                                $icon     = $n['status']==='confirmed'?'fa-circle-check':($n['status']==='cancelled'?'fa-ban':'fa-clock');
                                $icon_cls = $n['status']==='confirmed'?'ni--green':($n['status']==='cancelled'?'ni--red':'ni--gold');
                            }
                        ?>
                        <div class="notif-item <?=$is_unread?'notif-item--unread':''?>"
                             onclick="<?=$is_new_user?'goToGuest('.$n['booking_id'].')':'goToBooking('.$n['booking_id'].')'?>">
                            <div class="ni-icon <?=$icon_cls?>"><i class="fa-solid <?=$icon?>"></i></div>
                            <div class="ni-body">
                                <div class="ni-title">
                                    <?php if($is_new_user): ?>
                                        <strong><?=htmlspecialchars($n['full_name'])?></strong> created an account
                                    <?php elseif($n['status']==='pending'): ?>
                                        <strong><?=htmlspecialchars($n['full_name'])?></strong> made a new booking
                                    <?php elseif($n['status']==='confirmed'): ?>
                                        Booking confirmed for <strong><?=htmlspecialchars($n['full_name'])?></strong>
                                    <?php else: ?>
                                        Booking cancelled — <strong><?=htmlspecialchars($n['full_name'])?></strong>
                                    <?php endif; ?>
                                </div>
                                <?php if(!$is_new_user): ?>
                                <div class="ni-meta">
                                    <span class="ni-room"><i class="fa-solid fa-bed"></i> <?=htmlspecialchars($n['room_type'])?></span>
                                    <span class="ni-sep">·</span>
                                    <span><?=date('M d',strtotime($n['check_in']))?> → <?=date('M d',strtotime($n['check_out']))?></span>
                                </div>
                                <?php else: ?>
                                <div class="ni-meta"><i class="fa-solid fa-user"></i> New guest registered</div>
                                <?php endif; ?>
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
            <div class="topbar-admin">
                <div class="topbar-avatar"><?=strtoupper(substr($admin_name,0,2))?></div>
                <div class="topbar-admin-info">
                    <span><?=htmlspecialchars($admin_name)?></span>
                    <small>Admin</small>
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
            <button class="qa-action-btn qa-gold" onclick="showSection('users',document.querySelectorAll('.sb-item')[2])">
                <i class="fa-solid fa-users"></i> Manage Guests
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
            <div class="kpi-card">
                <div class="kpi-icon" style="background:#e8f0fe;color:#1a6abf"><i class="fa-solid fa-users"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total Guests</div>
                    <div class="kpi-value"><?=$total_users?></div>
                    <?php if($new_guests_month > 0): ?>
                    <div class="kpi-change up"><i class="fa-solid fa-arrow-up-right"></i> +<?=$new_guests_month?> this month</div>
                    <?php else: ?>
                    <div class="kpi-change neutral">Registered users</div>
                    <?php endif; ?>
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
                            <td>
                                <?php if($b['status']==='pending'): ?>
                                <div class="action-menu" id="am-<?=$b['booking_id']?>">
                                    <button class="action-btn" onclick="toggleMenu(<?=$b['booking_id']?>,event)">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>
                                    <div class="action-dropdown">
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
                                <?php elseif($b['status']==='confirmed'):
                                    $bm_name    = htmlspecialchars($b['full_name'],ENT_QUOTES);
                                    $bm_room    = htmlspecialchars($b['room_type'],ENT_QUOTES);
                                    $bm_checkin = date('M d, Y',strtotime($b['check_in']));
                                    $bm_checkout= date('M d, Y',strtotime($b['check_out']));
                                    $bm_booked  = date('M d, Y',strtotime($b['created_at']));
                                ?>
                                <button class="btn-view-info"
                                    onclick="openBookingModal('<?=$bm_name?>','<?=$bm_room?>','<?=$bm_checkin?>','<?=$bm_checkout?>',<?=$nights?>,<?=$b['guests']?>,'<?=$bm_booked?>')">
                                    <i class="fa-solid fa-circle-info"></i> View
                                </button>
                                <?php else: ?>
                                <span class="action-none"><i class="fa-solid fa-ban"></i> Cancelled</span>
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

    <!-- GUESTS -->
    <section class="dash-section" id="section-users">
        <div class="section-header">
            <div><h1>Guests</h1><p>All registered guest accounts</p></div>
        </div>

        <div class="stats-row stats-row--sm">
            <div class="stat-card">
                <div class="stat-icon-wrap stat-icon--navy"><i class="fa-solid fa-users"></i></div>
                <div class="stat-body"><span class="stat-label">Total Guests</span><span class="stat-value"><?=$total_users?></span></div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-head">
                <div><h3>Guest List</h3><p><?=count($users)?> registered</p></div>
                <div class="table-controls">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" placeholder="Search guest..." oninput="filterUsers(this.value)">
                    </div>
                </div>
            </div>
            <div class="table-wrap">
                <table id="usersTable">
                    <thead>
                        <tr><th>#</th><th>Guest Name</th><th>Email</th><th>Role</th><th>Registered</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($users)): ?>
                        <tr><td colspan="6" class="empty-cell">No users found.</td></tr>
                        <?php else: foreach($users as $i => $u): ?>
                        <tr class="u-row" data-name="<?=strtolower(htmlspecialchars($u['full_name']))?>">
                            <td class="row-num"><?=$i+1?></td>
                            <td>
                                <div class="guest-cell">
                                    <div class="guest-avatar"><?=strtoupper(substr($u['full_name'],0,1))?></div>
                                    <?=htmlspecialchars($u['full_name'])?>
                                </div>
                            </td>
                            <td class="text-muted"><?=htmlspecialchars($u['email'])?></td>
                            <td><?=$u['role']==='admin'?'<span class="tag tag--admin">Admin</span>':'<span class="tag tag--guest">Guest</span>'?></td>
                            <td class="text-muted"><?=date('M d, Y',strtotime($u['created_at']))?></td>
                            <td>
                                <?php if($u['role']!=='admin'): ?>
                                <div class="action-menu" id="um-<?=$u['user_id']?>">
                                    <button class="action-btn" onclick="toggleUserMenu(<?=$u['user_id']?>,event)">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>
                                    <div class="action-dropdown">
                                        <button class="ad-item ad-view" onclick="openGuestModal(
                                            '<?=htmlspecialchars($u['full_name'],ENT_QUOTES)?>',
                                            '<?=htmlspecialchars($u['email'],ENT_QUOTES)?>',
                                            '<?=$u['role']?>',
                                            '<?=date('F j, Y',strtotime($u['created_at']))?>',
                                            <?=$u['user_id']?>,
                                            '<?=$u['role']?>',
                                            '<?=htmlspecialchars($u['phone']??'',ENT_QUOTES)?>'
                                        );closeUserMenu(<?=$u['user_id']?>);">
                                            <i class="fa-solid fa-eye"></i> View Profile
                                        </button>
                                        <a href="admin_dashboard.php?delete_user=<?=$u['user_id']?>"
                                           class="ad-item ad-delete"
                                           onclick="return confirm('Delete this guest and all their bookings?')">
                                            <i class="fa-solid fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </div>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:.78rem;">Protected</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
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
                            </td>
                            <td>
                                <button class="btn-view-info" onclick="openEditRoomModal(
                                    <?=$rm['room_id']?>,
                                    '<?=htmlspecialchars($rm['room_name'],ENT_QUOTES)?>',
                                    <?=$rm['price']?>,
                                    <?=$rm['total_units']?>,
                                    '<?=htmlspecialchars($rm['description'] ?? '', ENT_QUOTES)?>',
                                    <?=!empty($rm['image']) ? "'../assets/images/rooms/".htmlspecialchars($rm['image'],ENT_QUOTES)."'" : 'null'?>
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
                <div class="bmodal-icon">✓</div>
                <div>
                    <div class="bmodal-title">Booking confirmed</div>
                    <div class="bmodal-guest">Guest — <span id="bm-name"></span></div>
                    <div class="bmodal-conf-badge">✓ Confirmed reservation</div>
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
            <div class="bmodal-fields">
                <div class="bmodal-field">
                    <div class="bmodal-field-lbl">Booked on</div>
                    <div class="bmodal-field-val" id="bm-booked" style="font-size:13px;"></div>
                </div>
                <div class="bmodal-field" style="grid-column:span 2;">
                    <div class="bmodal-field-lbl">Note</div>
                    <div class="bmodal-field-val" style="font-size:13px;color:#aaa;font-weight:400;">No special requests</div>
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
                    <div class="bmodal-summary-val" style="font-size:13px;color:#16a34a;">Confirmed</div>
                </div>
            </div>
        </div>
        <div class="bmodal-footer">
            <button class="bmodal-close-btn" onclick="closeBookingModal()">Close</button>
        </div>
    </div>
</div>

<!-- GUEST PROFILE MODAL -->
<div class="gmodal-overlay" id="guestModal" onclick="closeGuestModal()">
    <div class="gmodal-box" onclick="event.stopPropagation()">
        <div class="gmodal-top">
            <div class="gmodal-top-row">
                <span class="gmodal-label">Guest profile</span>
                <button class="gmodal-close" onclick="closeGuestModal()">✕</button>
            </div>
            <div class="gmodal-identity">
                <div class="gmodal-avatar" id="gm-avatar">JP</div>
                <div>
                    <div class="gmodal-name" id="gm-name">—</div>
                    <span class="gmodal-badge" id="gm-badge">👤 Guest Member</span>
                </div>
            </div>
        </div>
        <div class="gmodal-body">
            <div class="gmodal-group-title">Contact</div>
            <div class="gmodal-fields three">
                <div class="gmodal-field">
                    <div class="gmodal-field-lbl">User ID</div>
                    <div class="gmodal-field-val" id="gm-id">—</div>
                </div>
                <div class="gmodal-field">
                    <div class="gmodal-field-lbl">Email</div>
                    <div class="gmodal-field-val" id="gm-email" style="font-size:12px;">—</div>
                </div>
                <div class="gmodal-field">
                    <div class="gmodal-field-lbl">Phone</div>
                    <div class="gmodal-field-val" id="gm-phone">—</div>
                </div>
            </div>
            <div class="gmodal-group-title" style="margin-top:16px;">Account</div>
            <div class="gmodal-fields three">
                <div class="gmodal-field">
                    <div class="gmodal-field-lbl">Role</div>
                    <div class="gmodal-field-val" id="gm-role">—</div>
                </div>
                <div class="gmodal-field">
                    <div class="gmodal-field-lbl">Member since</div>
                    <div class="gmodal-field-val" id="gm-joined" style="font-size:13px;">—</div>
                </div>
                <div class="gmodal-field">
                    <div class="gmodal-field-lbl">Status</div>
                    <div class="gmodal-field-val"><span class="gmodal-status-dot">Active</span></div>
                </div>
            </div>
        </div>
        <div class="gmodal-footer">
            <button class="gmodal-btn-close" onclick="closeGuestModal()">✕ Close</button>
            <a class="gmodal-btn-delete" id="gm-delete-link" href="#"
               onclick="return confirm('Delete this guest and all their bookings?')">
                🗑 Delete guest
            </a>
        </div>
    </div>
</div>

<!-- ADD ROOM MODAL -->
<div class="bmodal-overlay" id="addRoomModal" onclick="if(event.target===this)closeAddRoomModal()">
    <div class="bmodal-box" onclick="event.stopPropagation()">
        <div class="bmodal-top">
            <div class="bmodal-top-row">
                <span class="bmodal-label">New room type</span>
                <button class="bmodal-close" onclick="closeAddRoomModal()">✕</button>
            </div>
            <div class="bmodal-identity">
                <div class="bmodal-icon"><i class="fa-solid fa-bed"></i></div>
                <div><div class="bmodal-title">Add Room Type</div></div>
            </div>
        </div>
        <form method="POST" action="admin_dashboard.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_room">
            <div class="bmodal-body">
                <div class="bmodal-field" style="margin-bottom:10px;">
                    <div class="bmodal-field-lbl">Room Name</div>
                    <input type="text" name="room_name" required placeholder="e.g. Deluxe Room"
                        style="width:100%;border:none;background:transparent;font-size:14px;font-weight:600;color:#1a1a2e;outline:none;">
                </div>
                <div class="bmodal-fields">
                    <div class="bmodal-field">
                        <div class="bmodal-field-lbl">Price / night (₱)</div>
                        <input type="number" name="price" step="0.01" min="1" required
                            style="width:100%;border:none;background:transparent;font-size:14px;font-weight:600;color:#1a1a2e;outline:none;">
                    </div>
                    <div class="bmodal-field">
                        <div class="bmodal-field-lbl">Total Units</div>
                        <input type="number" name="total_units" min="1" required
                            style="width:100%;border:none;background:transparent;font-size:14px;font-weight:600;color:#1a1a2e;outline:none;">
                    </div>
                </div>
                <div class="bmodal-field" style="margin-top:8px;">
                    <div class="bmodal-field-lbl">Description</div>
                    <textarea name="description" rows="3" placeholder="Short description guests will see on the booking page..."
                        style="width:100%;border:none;background:transparent;font-size:13px;color:#1a1a2e;outline:none;font-family:inherit;resize:vertical;"></textarea>
                </div>
                <div class="bmodal-field" style="margin-top:8px;">
                    <div class="bmodal-field-lbl">Room Photo</div>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                        style="width:100%;font-size:12px;color:#555;">
                    <div style="font-size:11px;color:#aaa;margin-top:4px;">JPG, PNG, or WEBP — max 5MB. Optional; a placeholder is used if left blank.</div>
                </div>
            </div>
            <div class="bmodal-footer" style="display:flex;gap:10px;">
                <button type="button" class="bmodal-close-btn" style="background:#f5f5f3;color:#555;" onclick="closeAddRoomModal()">Cancel</button>
                <button type="submit" class="bmodal-close-btn">Confirm</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT ROOM MODAL -->
<div class="bmodal-overlay" id="editRoomModal" onclick="if(event.target===this)closeEditRoomModal()">
    <div class="bmodal-box" onclick="event.stopPropagation()">
        <div class="bmodal-top">
            <div class="bmodal-top-row">
                <span class="bmodal-label">Edit room</span>
                <button class="bmodal-close" onclick="closeEditRoomModal()">✕</button>
            </div>
            <div class="bmodal-identity">
                <div class="bmodal-icon"><i class="fa-solid fa-bed"></i></div>
                <div><div class="bmodal-title" id="er-name">—</div></div>
            </div>
        </div>
        <form method="POST" action="admin_dashboard.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_room">
            <input type="hidden" name="room_id" id="er-room-id">
            <div class="bmodal-body">
                <div class="bmodal-fields">
                    <div class="bmodal-field">
                        <div class="bmodal-field-lbl">Price / night (₱)</div>
                        <input type="number" name="price" id="er-price" step="0.01" min="1" required
                            style="width:100%;border:none;background:transparent;font-size:14px;font-weight:600;color:#1a1a2e;outline:none;">
                    </div>
                    <div class="bmodal-field">
                        <div class="bmodal-field-lbl">Total Units</div>
                        <input type="number" name="total_units" id="er-units" min="1" required
                            style="width:100%;border:none;background:transparent;font-size:14px;font-weight:600;color:#1a1a2e;outline:none;">
                    </div>
                </div>
                <div class="bmodal-field" style="margin-top:8px;">
                    <div class="bmodal-field-lbl">Description</div>
                    <textarea name="description" id="er-description" rows="3" placeholder="Short description guests will see on the booking page..."
                        style="width:100%;border:none;background:transparent;font-size:13px;color:#1a1a2e;outline:none;font-family:inherit;resize:vertical;"></textarea>
                </div>
                <div class="bmodal-field" style="margin-top:8px;">
                    <div class="bmodal-field-lbl">Current Photo</div>
                    <img id="er-current-image" src="" alt="" style="max-width:120px;border-radius:8px;margin:6px 0;display:none;">
                    <div id="er-no-image" style="font-size:12px;color:#aaa;margin:6px 0;">No photo uploaded yet.</div>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                        style="width:100%;font-size:12px;color:#555;">
                    <div style="font-size:11px;color:#aaa;margin-top:4px;">Upload a new file only if you want to replace the current photo.</div>
                </div>
                <p style="font-size:11px;color:#aaa;margin-top:10px;">Room name can't be changed here — delete and re-add if you need to rename it (this keeps old bookings intact).</p>
            </div>
            <div class="bmodal-footer" style="display:flex;gap:10px;">
                <button type="button" class="bmodal-close-btn" style="background:#f5f5f3;color:#555;" onclick="closeEditRoomModal()">Cancel</button>
                <button type="submit" class="bmodal-close-btn">Save Changes</button>
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
    document.getElementById('sidebarBackdrop').classList.toggle('open');
}
function closeSidebarMobile(){
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('adminHamburger').classList.remove('open');
    document.getElementById('sidebarBackdrop').classList.remove('open');
}

const monthlyLabels = <?=json_encode(array_column($monthly_stats,'month'))?:'\[\]'?>;
const monthlyBookings = <?=json_encode(array_column($monthly_stats,'total'))?:'\[\]'?>;
const monthlyRevenue = <?=json_encode(array_column($monthly_stats,'revenue'))?:'\[\]'?>;
const hasRevenue = monthlyRevenue.some(v=>v>0);

const monthlyDatasets = [{
    type:'bar', label:'Bookings', data:monthlyBookings,
    backgroundColor:'rgba(26,26,46,0.85)', borderRadius:5, yAxisID:'y'
}];
if(hasRevenue){
    monthlyDatasets.push({
        type:'line', label:'Revenue (₱)', data:monthlyRevenue,
        borderColor:'#c8a96e', backgroundColor:'rgba(200,169,110,0.06)',
        borderWidth:2.5, borderDash:[5,3], pointBackgroundColor:'#a07840',
        pointBorderColor:'#fff', pointBorderWidth:2, pointRadius:5,
        fill:true, tension:0.45, yAxisID:'y1'
    });
}

new Chart(document.getElementById('monthlyChart'),{
    data:{labels:monthlyLabels, datasets:monthlyDatasets},
    options:{
        responsive:true,
        plugins:{legend:{display:false}},
        scales:{
            y:{beginAtZero:true,ticks:{stepSize:1,color:'#aaa',font:{size:11}},grid:{color:'#f5f5f5'},title:{display:true,text:'Bookings',color:'#aaa',font:{size:10}}},
            y1:hasRevenue?{position:'right',beginAtZero:true,ticks:{color:'#c8a96e',font:{size:10},callback:v=>'₱'+v.toLocaleString()},grid:{display:false}}:{display:false},
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
            backgroundColor:['#1a1a2e','#c8a96e','#a07840','#e8d5a3','#252545'],
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

function filterUsers(q){ const query=q.trim().toLowerCase(); document.querySelectorAll('.u-row').forEach(row=>{ row.style.display=!query||row.dataset.name.includes(query)?'':'none'; }); }

const bookingData=<?=json_encode(array_map(fn($b)=>['id'=>$b['booking_id'],'name'=>$b['full_name'],'room'=>$b['room_type'],'checkin'=>$b['check_in'],'checkout'=>$b['check_out'],'status'=>$b['status']],$bookings))?>;
const guestData=<?=json_encode(array_map(fn($u)=>['id'=>$u['user_id'],'name'=>$u['full_name'],'email'=>$u['email'],'role'=>$u['role']],$users))?>;
function highlight(t,q){ if(!q) return escapeHtml(t); const e=q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); return escapeHtml(t).replace(new RegExp(`(${e})`,'gi'),'<mark>$1</mark>'); }
function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function globalSearchFn(q){
    const query=q.trim().toLowerCase(); const dd=document.getElementById('searchDropdown');
    if(!query){ dd.classList.remove('open'); dd.innerHTML=''; return; }
    const mb=bookingData.filter(b=>b.name.toLowerCase().includes(query)||b.room.toLowerCase().includes(query)).slice(0,5);
    const mg=guestData.filter(u=>u.name.toLowerCase().includes(query)||u.email.toLowerCase().includes(query)).slice(0,4);
    if(!mb.length&&!mg.length){ dd.innerHTML=`<div class="sd-empty"><i class="fa-regular fa-face-frown"></i>No results for "<strong>${escapeHtml(q)}</strong>"</div>`; dd.classList.add('open'); return; }
    let html='';
    if(mb.length){ html+=`<div class="sd-section-label"><i class="fa-solid fa-calendar-check"></i> Bookings</div>`; mb.forEach(b=>{ const ci=new Date(b.checkin).toLocaleDateString('en-US',{month:'short',day:'numeric'}); const co=new Date(b.checkout).toLocaleDateString('en-US',{month:'short',day:'numeric'}); html+=`<div class="sd-item" onclick="goToBooking(${b.id})"><div class="sd-avatar sd-avatar--booking"><i class="fa-solid fa-calendar"></i></div><div class="sd-body"><div class="sd-title">${highlight(b.name,q)}</div><div class="sd-meta">${highlight(b.room,q)} · ${ci} → ${co}</div></div><span class="sd-badge sd-badge--${b.status}">${b.status}</span></div>`; }); }
    if(mg.length){ html+=`<div class="sd-section-label"><i class="fa-solid fa-users"></i> Guests</div>`; mg.forEach(u=>{ html+=`<div class="sd-item" onclick="goToGuest(${u.id})"><div class="sd-avatar">${escapeHtml(u.name.substring(0,2).toUpperCase())}</div><div class="sd-body"><div class="sd-title">${highlight(u.name,q)}</div><div class="sd-meta">${highlight(u.email,q)}</div></div><span class="sd-badge sd-badge--${u.role}">${u.role}</span></div>`; }); }
    html+=`<div class="sd-footer"><i class="fa-solid fa-magnifying-glass"></i> Showing top results</div>`;
    dd.innerHTML=html; dd.classList.add('open');
}
function goToBooking(id){ closeSearchDropdown(); showSection('bookings',document.querySelectorAll('.sb-item')[1]); setTimeout(()=>{ document.querySelectorAll('.b-row').forEach(r=>r.classList.remove('row-highlight')); const t=document.querySelector(`[data-bid="${id}"]`); if(t){ t.classList.add('row-highlight'); t.scrollIntoView({behavior:'smooth',block:'center'}); } },150); }
function goToGuest(id){ closeSearchDropdown(); showSection('users',document.querySelectorAll('.sb-item')[2]); }
function closeSearchDropdown(){ document.getElementById('searchDropdown').classList.remove('open'); document.getElementById('globalSearch').value=''; }

function toggleMenu(id, e) {
    e.stopPropagation();
    const wrap = document.getElementById('am-' + id);
    const isOpen = wrap.classList.contains('open');
    document.querySelectorAll('.action-menu.open').forEach(m => m.classList.remove('open'));
    if (!isOpen) { wrap.classList.add('open'); positionDrop(wrap); }
}

function toggleUserMenu(id, e) {
    e.stopPropagation();
    const wrap = document.getElementById('um-' + id);
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
function closeUserMenu(id){ document.getElementById('um-'+id)?.classList.remove('open'); }

function openBookingModal(name,room,checkin,checkout,nights,guests,booked){
    document.getElementById('bm-name').textContent=name; document.getElementById('bm-room').textContent=room;
    document.getElementById('bm-checkin').textContent=checkin; document.getElementById('bm-checkout').textContent=checkout;
    document.getElementById('bm-nights').textContent=nights+' night'+(nights!=1?'s':'');
    document.getElementById('bm-guests').textContent=guests+' guest'+(guests!=1?'s':'');
    document.getElementById('bm-booked').textContent=booked;
    document.getElementById('bookingModal').classList.add('open'); document.body.style.overflow='hidden';
}
function closeBookingModal(){ document.getElementById('bookingModal').classList.remove('open'); document.body.style.overflow=''; }

function openGuestModal(name,email,role,joined,userId,userRole,phone){
    const initials=name.split(' ').map(w=>w[0]).join('').substring(0,2).toUpperCase();
    document.getElementById('gm-avatar').textContent=initials;
    document.getElementById('gm-name').textContent=name;
    document.getElementById('gm-id').textContent='#'+userId;
    document.getElementById('gm-email').textContent=email;
    document.getElementById('gm-phone').textContent=phone||'Not provided';
    document.getElementById('gm-role').textContent=role.charAt(0).toUpperCase()+role.slice(1);
    document.getElementById('gm-joined').textContent=joined;
    const badge=document.getElementById('gm-badge');
    if(role==='admin'){ badge.innerHTML='<i class="fa-solid fa-shield-halved"></i> Administrator'; badge.style.cssText='background:rgba(220,38,38,.15);border-color:rgba(220,38,38,.3);color:#fca5a5;'; }
    else { badge.innerHTML='<i class="fa-solid fa-user"></i> Guest Member'; badge.style.cssText=''; }
    const del=document.getElementById('gm-delete-link');
    del.href='admin_dashboard.php?delete_user='+userId;
    del.style.display=userRole!=='admin'?'flex':'none';
    document.getElementById('guestModal').classList.add('open'); document.body.style.overflow='hidden';
}
function closeGuestModal(){ document.getElementById('guestModal').classList.remove('open'); document.body.style.overflow=''; }

function openAddRoomModal(){ document.getElementById('addRoomModal').classList.add('open'); document.body.style.overflow='hidden'; }
function closeAddRoomModal(){ document.getElementById('addRoomModal').classList.remove('open'); document.body.style.overflow=''; }
function openEditRoomModal(id,name,price,units,description,imageUrl){
    document.getElementById('er-room-id').value=id;
    document.getElementById('er-name').textContent=name;
    document.getElementById('er-price').value=price;
    document.getElementById('er-units').value=units;
    document.getElementById('er-description').value=description || '';

    const imgEl = document.getElementById('er-current-image');
    const noImgEl = document.getElementById('er-no-image');
    if (imageUrl) {
        imgEl.src = imageUrl;
        imgEl.style.display = 'block';
        noImgEl.style.display = 'none';
    } else {
        imgEl.style.display = 'none';
        noImgEl.style.display = 'block';
    }

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
document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ document.getElementById('searchDropdown').classList.remove('open'); document.getElementById('globalSearch').value=''; closeBookingModal(); closeGuestModal(); if(adminPicker) adminPicker.close(); closeSidebarMobile(); } });

const alertEl=document.getElementById('dashAlert');
if(alertEl){ setTimeout(()=>{ alertEl.style.transition='opacity 0.5s'; alertEl.style.opacity='0'; setTimeout(()=>alertEl.remove(),500); },4000); }
</script>
</body>
</html>