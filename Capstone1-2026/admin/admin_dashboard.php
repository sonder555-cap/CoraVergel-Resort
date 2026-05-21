<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: ../user/login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'];
$success = '';
$error = '';

// Handle delete user
if (isset($_GET['delete_user'])) {
    $del_id = intval($_GET['delete_user']);
    if ($del_id === $_SESSION['admin_id']) {
        $error = "You cannot delete your own admin account.";
    } else {
        $conn->query("DELETE FROM bookings WHERE user_id = $del_id");
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role != 'admin'");
        $stmt->bind_param("i", $del_id);
        $stmt->execute();
        $stmt->close();
        $success = "User deleted successfully.";
    }
}

// Handle delete booking
if (isset($_GET['delete_booking'])) {
    $del_bid = intval($_GET['delete_booking']);
    $stmt = $conn->prepare("DELETE FROM bookings WHERE booking_id = ?");
    $stmt->bind_param("i", $del_bid);
    $stmt->execute();
    $stmt->close();
    $success = "Booking deleted successfully.";
}

// Handle confirm booking
if (isset($_GET['confirm_booking'])) {
    $con_bid = intval($_GET['confirm_booking']);
    $stmt = $conn->prepare("UPDATE bookings SET status = 'confirmed' WHERE booking_id = ?");
    $stmt->bind_param("i", $con_bid);
    $stmt->execute();
    $stmt->close();
    $success = "Booking confirmed.";
}

// Handle cancel booking
if (isset($_GET['cancel_booking'])) {
    $can_bid = intval($_GET['cancel_booking']);
    $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = ?");
    $stmt->bind_param("i", $can_bid);
    $stmt->execute();
    $stmt->close();
    $success = "Booking cancelled.";
}

// Stats
$total_bookings = $conn->query("SELECT COUNT(*) as c FROM bookings")->fetch_assoc()['c'];
$total_users    = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'user'")->fetch_assoc()['c'];
$confirmed      = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE status = 'confirmed'")->fetch_assoc()['c'];
$pending_count  = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE status = 'pending'")->fetch_assoc()['c'];
$cancelled      = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE status = 'cancelled'")->fetch_assoc()['c'];
$upcoming       = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE check_in >= CURDATE()")->fetch_assoc()['c'];

// Room type chart data
$room_stats = [];
$rs = $conn->query("SELECT room_type, COUNT(*) as total FROM bookings GROUP BY room_type ORDER BY total DESC");
while ($row = $rs->fetch_assoc()) $room_stats[] = $row;

// Monthly chart data
$monthly_stats = [];
$ms = $conn->query("SELECT DATE_FORMAT(created_at, '%b') as month, COUNT(*) as total FROM bookings WHERE YEAR(created_at) = YEAR(CURDATE()) GROUP BY MONTH(created_at) ORDER BY MONTH(created_at)");
while ($row = $ms->fetch_assoc()) $monthly_stats[] = $row;

// All bookings
$bookings = [];
$bq = $conn->query("SELECT b.booking_id, u.full_name, b.room_type, b.check_in, b.check_out, b.guests, b.status, b.created_at FROM bookings b JOIN users u ON b.user_id = u.user_id ORDER BY b.created_at DESC");
while ($row = $bq->fetch_assoc()) $bookings[] = $row;

// All users
$users = [];
$uq = $conn->query("SELECT user_id, full_name, email, role, created_at FROM users ORDER BY created_at DESC");
while ($row = $uq->fetch_assoc()) $users[] = $row;

// ── NOTIFICATIONS: bookings ──
$notifications = [];
$nq = $conn->query("
    SELECT
        'booking' as notif_type,
        b.booking_id,
        u.full_name,
        b.room_type,
        b.check_in,
        b.check_out,
        b.status,
        b.created_at
    FROM bookings b
    JOIN users u ON b.user_id = u.user_id
    ORDER BY b.created_at DESC
    LIMIT 15
");
while ($row = $nq->fetch_assoc()) $notifications[] = $row;

// ── NOTIFICATIONS: new user registrations ──
$unotif = $conn->query("
    SELECT
        'new_user' as notif_type,
        user_id    as booking_id,
        full_name,
        ''         as room_type,
        ''         as check_in,
        ''         as check_out,
        'new'      as status,
        created_at
    FROM users
    WHERE role = 'user'
    ORDER BY created_at DESC
    LIMIT 10
");
while ($row = $unotif->fetch_assoc()) $notifications[] = $row;

// Sort newest first
usort($notifications, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$notifications = array_slice($notifications, 0, 20);

// Unread count: pending bookings + users registered today
$unread_count = array_reduce($notifications, function($c, $n) {
    if ($n['notif_type'] === 'booking'  && $n['status'] === 'pending') return $c + 1;
    if ($n['notif_type'] === 'new_user' && date('Y-m-d', strtotime($n['created_at'])) === date('Y-m-d')) return $c + 1;
    return $c;
}, 0);

// Human-readable time diff
function human_time_diff($timestamp) {
    $diff = time() - $timestamp;
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60)    . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600)  . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — CoraVergel Resort</title>
    <link rel="icon" href="../assets/images/logo/cv_logo.png" sizes="any">
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
    /* ── SEARCH DROPDOWN ── */
    .topbar-search { position: relative; }

    .search-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        width: 100%;
        min-width: 400px;
        background: #fff;
        border-radius: 10px;
        border: 1px solid #e8e8e8;
        box-shadow: 0 8px 32px rgba(0,0,0,.14);
        z-index: 9999;
        overflow: hidden;
        max-height: 440px;
        overflow-y: auto;
    }
    .search-dropdown.open { display: block; }

    .sd-section-label {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: #aaa;
        padding: 10px 16px 6px;
        background: #fafafa;
        border-bottom: 1px solid #f0f0f0;
    }
    .sd-section-label i { margin-right: 5px; }

    .sd-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 16px;
        cursor: pointer;
        transition: background .15s;
        border-bottom: 1px solid #f5f5f5;
    }
    .sd-item:last-child { border-bottom: none; }
    .sd-item:hover { background: #f8f5f0; }

    .sd-avatar {
        width: 34px; height: 34px;
        border-radius: 50%;
        background: #1a1a2e;
        color: #c8a96e;
        font-size: 12px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .sd-avatar.sd-avatar--booking { background: #f0f7ff; color: #1a6abf; }

    .sd-body { flex: 1; min-width: 0; }

    .sd-title {
        font-size: 13px;
        font-weight: 600;
        color: #1a1a2e;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sd-title mark {
        background: #fff3cd;
        color: #1a1a2e;
        border-radius: 2px;
        padding: 0 1px;
        font-style: normal;
    }

    .sd-meta { font-size: 11px; color: #999; margin-top: 2px; }
    .sd-meta mark {
        background: #fff3cd;
        color: #555;
        border-radius: 2px;
        padding: 0 1px;
        font-style: normal;
    }

    .sd-badge {
        font-size: 10px;
        font-weight: 600;
        padding: 3px 9px;
        border-radius: 20px;
        flex-shrink: 0;
        text-transform: capitalize;
    }
    .sd-badge--confirmed { background: #e8f5e9; color: #2e7d32; }
    .sd-badge--pending   { background: #fff8e1; color: #f57f17; }
    .sd-badge--cancelled { background: #fce4ec; color: #c62828; }
    .sd-badge--guest     { background: #e8eaf6; color: #3949ab; }
    .sd-badge--admin     { background: #fce4ec; color: #b71c1c; }

    .sd-empty {
        padding: 28px 16px;
        text-align: center;
        color: #bbb;
        font-size: 13px;
    }
    .sd-empty i { font-size: 22px; display: block; margin-bottom: 8px; color: #ddd; }

    .sd-footer {
        padding: 8px 16px;
        background: #fafafa;
        border-top: 1px solid #f0f0f0;
        font-size: 11px;
        color: #bbb;
        text-align: center;
    }

    /* ── NOTIFICATION: blue icon for new users ── */
    .ni--blue { background: #e8f0fe; color: #1a6abf; }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════
     SIDEBAR
══════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sb-brand">
        <img src="../assets/images/logo/cv_logo.png" alt="Logo" class="sb-logo">
        <div class="sb-brand-text">
            <span class="sb-name">CoraVergel</span>
            <span class="sb-sub">Admin Panel</span>
        </div>
    </div>

    <div class="sb-nav">
        <div class="sb-group-label">MAIN</div>
        <button class="sb-item active" onclick="showSection('overview', this)">
            <i class="fa-solid fa-chart-pie"></i>
            <span>Dashboard</span>
        </button>

        <div class="sb-group-label">MANAGEMENT</div>
        <button class="sb-item" onclick="showSection('bookings', this)">
            <i class="fa-solid fa-calendar-check"></i>
            <span>Bookings</span>
            <?php if ($pending_count > 0): ?>
                <span class="sb-badge"><?= $pending_count ?></span>
            <?php endif; ?>
        </button>
        <button class="sb-item" onclick="showSection('users', this)">
            <i class="fa-solid fa-users"></i>
            <span>Guests</span>
        </button>

        <div class="sb-group-label">SITE</div>
        <a href="../frontend/guest.php" class="sb-item" target="_blank">
            <i class="fa-solid fa-globe"></i>
            <span>View Website</span>
        </a>
    </div>

    <div class="sb-footer">
        <div class="sb-admin">
            <div class="sb-avatar"><?= strtoupper(substr($admin_name, 0, 2)) ?></div>
            <div class="sb-admin-info">
                <span class="sb-admin-name"><?= htmlspecialchars($admin_name) ?></span>
                <span class="sb-admin-role">Administrator</span>
            </div>
        </div>
        <a href="../user/logout.php" class="sb-logout">
            <i class="fa-solid fa-right-from-bracket"></i> Sign Out
        </a>
    </div>
</aside>

<!-- ══════════════════════════════════════
     MAIN AREA
══════════════════════════════════════ -->
<div class="main-wrap">

    <!-- Topbar -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="topbar-search" id="searchWrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input
                    type="text"
                    placeholder="Search bookings, guests..."
                    id="globalSearch"
                    oninput="globalSearchFn(this.value)"
                    onfocus="globalSearchFn(this.value)"
                    autocomplete="off">
                <div class="search-dropdown" id="searchDropdown"></div>
            </div>
        </div>

        <div class="topbar-right">

            <!-- NOTIFICATION BELL -->
            <div class="notif-wrap" id="notifWrap">
                <button class="notif-bell" id="notifBell" onclick="toggleNotif(event)">
                    <i class="fa-solid fa-bell"></i>
                    <?php if ($unread_count > 0): ?>
                        <span class="notif-count"><?= $unread_count ?></span>
                    <?php endif; ?>
                </button>

                <div class="notif-panel" id="notifPanel">
                    <div class="notif-panel-head">
                        <div class="notif-panel-title">
                            <i class="fa-solid fa-bell"></i> Notifications
                            <?php if ($unread_count > 0): ?>
                                <span class="notif-unread-pill"><?= $unread_count ?> new</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($unread_count > 0): ?>
                            <button class="notif-mark-all" onclick="markAllRead()">Mark all read</button>
                        <?php endif; ?>
                    </div>

                    <div class="notif-list" id="notifList">
                        <?php if (empty($notifications)): ?>
                            <div class="notif-empty">
                                <i class="fa-regular fa-bell-slash"></i>
                                <p>No notifications yet</p>
                            </div>
                        <?php else: ?>

                            <?php foreach ($notifications as $n):
                                $ago         = human_time_diff(strtotime($n['created_at']));
                                $is_new_user = $n['notif_type'] === 'new_user';
                                $is_today    = date('Y-m-d', strtotime($n['created_at'])) === date('Y-m-d');
                                $is_unread   = (!$is_new_user && $n['status'] === 'pending')
                                            || ($is_new_user  && $is_today);

                                if ($is_new_user) {
                                    $icon     = 'fa-user-plus';
                                    $icon_cls = 'ni--blue';
                                } else {
                                    $icon     = $n['status'] === 'confirmed' ? 'fa-circle-check'
                                              : ($n['status'] === 'cancelled' ? 'fa-ban' : 'fa-clock');
                                    $icon_cls = $n['status'] === 'confirmed' ? 'ni--green'
                                              : ($n['status'] === 'cancelled' ? 'ni--red' : 'ni--gold');
                                }
                            ?>
                            <div class="notif-item <?= $is_unread ? 'notif-item--unread' : '' ?>"
                                 id="ni-<?= $n['notif_type'] ?>-<?= $n['booking_id'] ?>"
                                 onclick="<?= $is_new_user
                                     ? 'goToGuest(' . $n['booking_id'] . ')'
                                     : 'goToBooking(' . $n['booking_id'] . ')' ?>">

                                <div class="ni-icon <?= $icon_cls ?>">
                                    <i class="fa-solid <?= $icon ?>"></i>
                                </div>

                                <div class="ni-body">
                                    <div class="ni-title">
                                        <?php if ($is_new_user): ?>
                                            <strong><?= htmlspecialchars($n['full_name']) ?></strong> created an account
                                        <?php elseif ($n['status'] === 'pending'): ?>
                                            <strong><?= htmlspecialchars($n['full_name']) ?></strong> made a new booking
                                        <?php elseif ($n['status'] === 'confirmed'): ?>
                                            Booking confirmed for <strong><?= htmlspecialchars($n['full_name']) ?></strong>
                                        <?php else: ?>
                                            Booking cancelled — <strong><?= htmlspecialchars($n['full_name']) ?></strong>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!$is_new_user): ?>
                                    <div class="ni-meta">
                                        <span class="ni-room"><i class="fa-solid fa-bed"></i> <?= htmlspecialchars($n['room_type']) ?></span>
                                        <span class="ni-sep">·</span>
                                        <span><?= date('M d', strtotime($n['check_in'])) ?> → <?= date('M d', strtotime($n['check_out'])) ?></span>
                                    </div>
                                    <?php else: ?>
                                    <div class="ni-meta">
                                        <i class="fa-solid fa-user"></i> New guest registered
                                    </div>
                                    <?php endif; ?>

                                    <div class="ni-time"><?= $ago ?></div>
                                </div>

                                <?php if ($is_unread): ?>
                                    <div class="ni-dot"></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>

                        <?php endif; ?>
                    </div>

                    <div class="notif-panel-foot">
                        <button onclick="showSection('bookings', document.querySelectorAll('.sb-item')[1]); closeNotif();">
                            View all bookings <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            <!-- END NOTIFICATION BELL -->

            <div class="topbar-admin">
                <div class="topbar-avatar"><?= strtoupper(substr($admin_name, 0, 2)) ?></div>
                <div class="topbar-admin-info">
                    <span><?= htmlspecialchars($admin_name) ?></span>
                    <small>Admin</small>
                </div>
            </div>
        </div>
    </header>

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="dash-alert dash-alert--success" id="dashAlert">
            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="dash-alert dash-alert--error" id="dashAlert">
            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- ══════════════════════════════
         OVERVIEW
    ══════════════════════════════ -->
    <section class="dash-section active" id="section-overview">
        <div class="section-header">
            <div>
                <h1>Dashboard</h1>
                <p>Welcome back, <?= htmlspecialchars($admin_name) ?>. Here's what's happening today.</p>
            </div>
            <div class="section-date">
                <i class="fa-regular fa-calendar"></i>
                <?= date('F j, Y') ?>
            </div>
        </div>

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon-wrap stat-icon--navy">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <div class="stat-body">
                    <span class="stat-label">Total Bookings</span>
                    <span class="stat-value"><?= $total_bookings ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrap stat-icon--green">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="stat-body">
                    <span class="stat-label">Confirmed</span>
                    <span class="stat-value"><?= $confirmed ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrap stat-icon--gold">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="stat-body">
                    <span class="stat-label">Pending</span>
                    <span class="stat-value"><?= $pending_count ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrap stat-icon--blue">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="stat-body">
                    <span class="stat-label">Total Guests</span>
                    <span class="stat-value"><?= $total_users ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrap stat-icon--red">
                    <i class="fa-solid fa-ban"></i>
                </div>
                <div class="stat-body">
                    <span class="stat-label">Cancelled</span>
                    <span class="stat-value"><?= $cancelled ?></span>
                </div>
            </div>
        </div>

        <div class="charts-row">
            <div class="chart-card chart-card--wide">
                <div class="chart-card-header">
                    <div>
                        <h3>Monthly Bookings</h3>
                        <p><?= date('Y') ?> overview</p>
                    </div>
                </div>
                <canvas id="monthlyChart" height="110"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-card-header">
                    <div>
                        <h3>By Room Type</h3>
                        <p>Distribution</p>
                    </div>
                </div>
                <canvas id="roomChart" height="160"></canvas>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-head">
                <div>
                    <h3>Recent Bookings</h3>
                    <p>Latest 5 reservations</p>
                </div>
                <button class="btn-view-all" onclick="showSection('bookings', document.querySelectorAll('.sb-item')[1])">
                    View All <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Room Type</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Guests</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bookings)): ?>
                        <tr><td colspan="6" class="empty-cell">No bookings yet.</td></tr>
                        <?php else: ?>
                        <?php foreach (array_slice($bookings, 0, 5) as $b): ?>
                        <tr>
                            <td>
                                <div class="guest-cell">
                                    <div class="guest-avatar"><?= strtoupper(substr($b['full_name'], 0, 1)) ?></div>
                                    <?= htmlspecialchars($b['full_name']) ?>
                                </div>
                            </td>
                            <td><span class="tag tag--room"><?= htmlspecialchars($b['room_type']) ?></span></td>
                            <td><?= date('M d, Y', strtotime($b['check_in'])) ?></td>
                            <td><?= date('M d, Y', strtotime($b['check_out'])) ?></td>
                            <td><?= $b['guests'] ?></td>
                            <td>
                                <?php if ($b['status'] === 'confirmed'): ?>
                                    <span class="status-badge status--confirmed"><i class="fa-solid fa-circle-check"></i> Confirmed</span>
                                <?php elseif ($b['status'] === 'cancelled'): ?>
                                    <span class="status-badge status--cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>
                                <?php else: ?>
                                    <span class="status-badge status--pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════
         BOOKINGS
    ══════════════════════════════ -->
    <section class="dash-section" id="section-bookings">
        <div class="section-header">
            <div>
                <h1>Bookings</h1>
                <p>Manage all resort reservations</p>
            </div>
        </div>

        <div class="quick-pills">
            <div class="qpill qpill--all active-pill" onclick="filterByStatus('all', this)">
                All <span><?= count($bookings) ?></span>
            </div>
            <div class="qpill qpill--pending" onclick="filterByStatus('pending', this)">
                Pending <span><?= $pending_count ?></span>
            </div>
            <div class="qpill qpill--confirmed" onclick="filterByStatus('confirmed', this)">
                Confirmed <span><?= $confirmed ?></span>
            </div>
            <div class="qpill qpill--cancelled" onclick="filterByStatus('cancelled', this)">
                Cancelled <span><?= $cancelled ?></span>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-head">
                <div>
                    <h3>All Bookings</h3>
                    <p id="bookingCount"><?= count($bookings) ?> total</p>
                </div>
                <div class="table-controls">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" placeholder="Search guest or room..." id="bookingSearch" oninput="filterBookings()">
                    </div>
                    <div class="date-filter-wrap">
                        <button class="btn-filter" onclick="toggleDateFilter()" id="dateFilterBtn">
                            <i class="fa-regular fa-calendar"></i> Filter Date
                        </button>
                        <div class="date-filter-popup" id="dateFilterPopup">
                            <div class="dfp-row">
                                <div class="dfp-field">
                                    <label>From</label>
                                    <input type="date" id="dfpFrom" onchange="applyDateFilter()">
                                </div>
                                <div class="dfp-field">
                                    <label>To</label>
                                    <input type="date" id="dfpTo" onchange="applyDateFilter()">
                                </div>
                            </div>
                            <button class="dfp-clear" onclick="clearDateFilter()">
                                <i class="fa-solid fa-xmark"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-wrap">
                <table id="bookingsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Guest</th>
                            <th>Room Type</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Nights</th>
                            <th>Guests</th>
                            <th>Status</th>
                            <th>Booked</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="bookingsTbody">
                        <?php if (empty($bookings)): ?>
                        <tr><td colspan="10" class="empty-cell">No bookings found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($bookings as $i => $b):
                            $nights = (new DateTime($b['check_in']))->diff(new DateTime($b['check_out']))->days;
                        ?>
                        <tr class="b-row"
                            data-bid="<?= $b['booking_id'] ?>"
                            data-name="<?= strtolower(htmlspecialchars($b['full_name'])) ?>"
                            data-room="<?= strtolower(htmlspecialchars($b['room_type'])) ?>"
                            data-status="<?= $b['status'] ?>"
                            data-checkin="<?= $b['check_in'] ?>"
                            data-checkout="<?= $b['check_out'] ?>">
                            <td class="row-num"><?= $i + 1 ?></td>
                            <td>
                                <div class="guest-cell">
                                    <div class="guest-avatar"><?= strtoupper(substr($b['full_name'], 0, 1)) ?></div>
                                    <div>
                                        <div class="guest-name"><?= htmlspecialchars($b['full_name']) ?></div>
                                        <div class="guest-booked">Booked <?= date('M d', strtotime($b['created_at'])) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="tag tag--room"><?= htmlspecialchars($b['room_type']) ?></span></td>
                            <td><?= date('M d, Y', strtotime($b['check_in'])) ?></td>
                            <td><?= date('M d, Y', strtotime($b['check_out'])) ?></td>
                            <td><?= $nights ?> night<?= $nights != 1 ? 's' : '' ?></td>
                            <td><?= $b['guests'] ?></td>
                            <td>
                                <?php if ($b['status'] === 'confirmed'): ?>
                                    <span class="status-badge status--confirmed"><i class="fa-solid fa-circle-check"></i> Confirmed</span>
                                <?php elseif ($b['status'] === 'cancelled'): ?>
                                    <span class="status-badge status--cancelled"><i class="fa-solid fa-ban"></i> Cancelled</span>
                                <?php else: ?>
                                    <span class="status-badge status--pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M d, Y', strtotime($b['created_at'])) ?></td>
                            <td>
                                <div class="action-menu" id="am-<?= $b['booking_id'] ?>">
                                    <button class="action-btn" onclick="toggleMenu(<?= $b['booking_id'] ?>, event)">
                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                    </button>
                                    <div class="action-dropdown">
                                        <?php if ($b['status'] !== 'confirmed'): ?>
                                        <a href="admin_dashboard.php?confirm_booking=<?= $b['booking_id'] ?>"
                                           onclick="return confirm('Confirm this booking?')" class="ad-item ad-confirm">
                                            <i class="fa-solid fa-circle-check"></i> Confirm
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($b['status'] !== 'cancelled'): ?>
                                        <a href="admin_dashboard.php?cancel_booking=<?= $b['booking_id'] ?>"
                                           onclick="return confirm('Cancel this booking?')" class="ad-item ad-cancel">
                                            <i class="fa-solid fa-ban"></i> Cancel
                                        </a>
                                        <?php endif; ?>
                                        <a href="admin_dashboard.php?delete_booking=<?= $b['booking_id'] ?>"
                                           onclick="return confirm('Delete permanently?')" class="ad-item ad-delete">
                                            <i class="fa-solid fa-trash"></i> Delete
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="empty-state" id="noBookings" style="display:none;">
                    <i class="fa-regular fa-calendar-xmark"></i>
                    <p>No bookings match your filters.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ══════════════════════════════
         GUESTS
    ══════════════════════════════ -->
    <section class="dash-section" id="section-users">
        <div class="section-header">
            <div>
                <h1>Guests</h1>
                <p>All registered guest accounts</p>
            </div>
        </div>

        <div class="stats-row stats-row--sm">
            <div class="stat-card">
                <div class="stat-icon-wrap stat-icon--navy">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="stat-body">
                    <span class="stat-label">Total Guests</span>
                    <span class="stat-value"><?= $total_users ?></span>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-head">
                <div>
                    <h3>Guest List</h3>
                    <p><?= count($users) ?> registered</p>
                </div>
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
                        <tr>
                            <th>#</th>
                            <th>Guest Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Registered</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                        <tr><td colspan="6" class="empty-cell">No users found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($users as $i => $u): ?>
                        <tr class="u-row" data-name="<?= strtolower(htmlspecialchars($u['full_name'])) ?>">
                            <td class="row-num"><?= $i + 1 ?></td>
                            <td>
                                <div class="guest-cell">
                                    <div class="guest-avatar"><?= strtoupper(substr($u['full_name'], 0, 1)) ?></div>
                                    <?= htmlspecialchars($u['full_name']) ?>
                                </div>
                            </td>
                            <td class="text-muted"><?= htmlspecialchars($u['email']) ?></td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="tag tag--admin">Admin</span>
                                <?php else: ?>
                                    <span class="tag tag--guest">Guest</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                            <td>
                                <?php if ($u['role'] !== 'admin'): ?>
                                <a href="admin_dashboard.php?delete_user=<?= $u['user_id'] ?>"
                                   class="btn-icon btn-icon--red"
                                   onclick="return confirm('Delete this guest and all their bookings?')"
                                   title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:0.78rem;">Protected</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

</div><!-- /main-wrap -->

<script>
/* ═══════════════════════════════════════
   SECTION NAV
═══════════════════════════════════════ */
function showSection(name, el) {
    document.querySelectorAll('.dash-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.sb-item').forEach(n => n.classList.remove('active'));
    document.getElementById('section-' + name).classList.add('active');
    if (el) el.classList.add('active');
}

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}

/* ═══════════════════════════════════════
   CHARTS
═══════════════════════════════════════ */
new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($monthly_stats, 'month')) ?: '[]' ?>,
        datasets: [{
            data: <?= json_encode(array_column($monthly_stats, 'total')) ?: '[]' ?>,
            borderColor: '#c8a96e',
            backgroundColor: 'rgba(200,169,110,0.08)',
            borderWidth: 2.5,
            pointBackgroundColor: '#a07840',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            fill: true,
            tension: 0.45,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1, color: '#aaa', font: { size: 11 } }, grid: { color: '#f5f5f5' } },
            x: { grid: { display: false }, ticks: { color: '#aaa', font: { size: 11 } } }
        }
    }
});

new Chart(document.getElementById('roomChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($room_stats, 'room_type')) ?: '[]' ?>,
        datasets: [{
            data: <?= json_encode(array_column($room_stats, 'total')) ?: '[]' ?>,
            backgroundColor: ['#c8a96e','#1a1a2e','#e8d5a3','#a07840','#252545'],
            borderWidth: 0,
            hoverOffset: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: true, position: 'bottom',
                labels: { font: { size: 11 }, color: '#555', padding: 14, boxWidth: 12 }
            }
        },
        cutout: '65%',
    }
});

/* ═══════════════════════════════════════
   BOOKING FILTERS
═══════════════════════════════════════ */
let currentStatus = 'all';
let currentSearch = '';
let dfpFrom = '', dfpTo = '';

function filterByStatus(status, el) {
    currentStatus = status;
    document.querySelectorAll('.qpill').forEach(p => p.classList.remove('active-pill'));
    el.classList.add('active-pill');
    applyFilters();
}

function filterBookings() {
    currentSearch = document.getElementById('bookingSearch').value.trim().toLowerCase();
    applyFilters();
}

function applyDateFilter() {
    dfpFrom = document.getElementById('dfpFrom').value;
    dfpTo   = document.getElementById('dfpTo').value;
    applyFilters();
}

function clearDateFilter() {
    dfpFrom = ''; dfpTo = '';
    document.getElementById('dfpFrom').value = '';
    document.getElementById('dfpTo').value   = '';
    document.getElementById('dateFilterPopup').classList.remove('open');
    document.getElementById('dateFilterBtn').classList.remove('active');
    applyFilters();
}

function toggleDateFilter() {
    document.getElementById('dateFilterPopup').classList.toggle('open');
    document.getElementById('dateFilterBtn').classList.toggle('active');
}

function applyFilters() {
    const rows  = document.querySelectorAll('.b-row');
    let visible = 0;
    rows.forEach(row => {
        const matchStatus = currentStatus === 'all' || row.dataset.status === currentStatus;
        const matchSearch = !currentSearch ||
                            row.dataset.name.includes(currentSearch) ||
                            row.dataset.room.includes(currentSearch);
        const matchDate   = (!dfpFrom || row.dataset.checkin >= dfpFrom) &&
                            (!dfpTo   || row.dataset.checkout <= dfpTo);
        const show = matchStatus && matchSearch && matchDate;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('bookingCount').textContent = visible + ' result' + (visible !== 1 ? 's' : '');
    document.getElementById('noBookings').style.display  = visible === 0 ? '' : 'none';
    document.getElementById('bookingsTable').style.display = visible === 0 ? 'none' : '';
}

/* ═══════════════════════════════════════
   USERS FILTER
═══════════════════════════════════════ */
function filterUsers(q) {
    const query = q.trim().toLowerCase();
    document.querySelectorAll('.u-row').forEach(row => {
        row.style.display = !query || row.dataset.name.includes(query) ? '' : 'none';
    });
}

/* ═══════════════════════════════════════
   GLOBAL SEARCH DROPDOWN
═══════════════════════════════════════ */
const bookingData = <?= json_encode(array_map(fn($b) => [
    'id'      => $b['booking_id'],
    'name'    => $b['full_name'],
    'room'    => $b['room_type'],
    'checkin' => $b['check_in'],
    'checkout'=> $b['check_out'],
    'status'  => $b['status'],
], $bookings)) ?>;

const guestData = <?= json_encode(array_map(fn($u) => [
    'id'    => $u['user_id'],
    'name'  => $u['full_name'],
    'email' => $u['email'],
    'role'  => $u['role'],
], $users)) ?>;

function highlight(text, query) {
    if (!query) return escapeHtml(text);
    const esc = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return escapeHtml(text).replace(new RegExp(`(${esc})`, 'gi'), '<mark>$1</mark>');
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
}

function globalSearchFn(q) {
    const query    = q.trim().toLowerCase();
    const dropdown = document.getElementById('searchDropdown');

    if (!query) {
        dropdown.classList.remove('open');
        dropdown.innerHTML = '';
        return;
    }

    const matchedBookings = bookingData.filter(b =>
        b.name.toLowerCase().includes(query) || b.room.toLowerCase().includes(query)
    ).slice(0, 5);

    const matchedGuests = guestData.filter(u =>
        u.name.toLowerCase().includes(query) || u.email.toLowerCase().includes(query)
    ).slice(0, 4);

    if (matchedBookings.length === 0 && matchedGuests.length === 0) {
        dropdown.innerHTML = `
            <div class="sd-empty">
                <i class="fa-regular fa-face-frown"></i>
                No results for "<strong>${escapeHtml(q)}</strong>"
            </div>`;
        dropdown.classList.add('open');
        return;
    }

    let html = '';

    if (matchedBookings.length > 0) {
        html += `<div class="sd-section-label"><i class="fa-solid fa-calendar-check"></i> Bookings</div>`;
        matchedBookings.forEach(b => {
            const ci = new Date(b.checkin).toLocaleDateString('en-US',  { month: 'short', day: 'numeric' });
            const co = new Date(b.checkout).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            html += `
            <div class="sd-item" onclick="goToBooking(${b.id})">
                <div class="sd-avatar sd-avatar--booking"><i class="fa-solid fa-calendar"></i></div>
                <div class="sd-body">
                    <div class="sd-title">${highlight(b.name, q)}</div>
                    <div class="sd-meta">${highlight(b.room, q)} &nbsp;·&nbsp; ${ci} → ${co}</div>
                </div>
                <span class="sd-badge sd-badge--${b.status}">${b.status}</span>
            </div>`;
        });
    }

    if (matchedGuests.length > 0) {
        html += `<div class="sd-section-label"><i class="fa-solid fa-users"></i> Guests</div>`;
        matchedGuests.forEach(u => {
            html += `
            <div class="sd-item" onclick="goToGuest(${u.id})">
                <div class="sd-avatar">${escapeHtml(u.name.substring(0,2).toUpperCase())}</div>
                <div class="sd-body">
                    <div class="sd-title">${highlight(u.name, q)}</div>
                    <div class="sd-meta">${highlight(u.email, q)}</div>
                </div>
                <span class="sd-badge sd-badge--${u.role}">${u.role}</span>
            </div>`;
        });
    }

    html += `<div class="sd-footer"><i class="fa-solid fa-magnifying-glass"></i> Showing top results</div>`;
    dropdown.innerHTML = html;
    dropdown.classList.add('open');
}

function goToBooking(bookingId) {
    closeSearchDropdown();
    showSection('bookings', document.querySelectorAll('.sb-item')[1]);
    setTimeout(() => {
        document.querySelectorAll('.b-row').forEach(r => r.classList.remove('row-highlight'));
        const target = document.querySelector(`[data-bid="${bookingId}"]`);
        if (target) {
            target.classList.add('row-highlight');
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, 150);
}

function goToGuest(userId) {
    closeSearchDropdown();
    showSection('users', document.querySelectorAll('.sb-item')[2]);
}

function closeSearchDropdown() {
    document.getElementById('searchDropdown').classList.remove('open');
    document.getElementById('globalSearch').value = '';
}

document.addEventListener('click', e => {
    const sw = document.getElementById('searchWrap');
    if (sw && !sw.contains(e.target))
        document.getElementById('searchDropdown').classList.remove('open');

    const dfw = document.querySelector('.date-filter-wrap');
    if (dfw && !dfw.contains(e.target)) {
        document.getElementById('dateFilterPopup')?.classList.remove('open');
        document.getElementById('dateFilterBtn')?.classList.remove('active');
    }
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.getElementById('searchDropdown').classList.remove('open');
        document.getElementById('globalSearch').value = '';
    }
});

/* ═══════════════════════════════════════
   ACTION MENU
═══════════════════════════════════════ */
function toggleMenu(id, e) {
    e.stopPropagation();
    const wrap   = document.getElementById('am-' + id);
    const isOpen = wrap.classList.contains('open');
    document.querySelectorAll('.action-menu.open').forEach(m => m.classList.remove('open'));
    if (!isOpen) wrap.classList.add('open');
}
document.addEventListener('click', () => {
    document.querySelectorAll('.action-menu.open').forEach(m => m.classList.remove('open'));
});

/* ═══════════════════════════════════════
   NOTIFICATIONS
═══════════════════════════════════════ */
function toggleNotif(e) {
    e.stopPropagation();
    document.getElementById('notifPanel').classList.toggle('open');
    document.getElementById('notifWrap').classList.toggle('open');
}

function closeNotif() {
    document.getElementById('notifPanel').classList.remove('open');
    document.getElementById('notifWrap').classList.remove('open');
}

function markAllRead() {
    document.querySelectorAll('.notif-item--unread').forEach(el => {
        el.classList.remove('notif-item--unread');
        el.querySelector('.ni-dot')?.remove();
    });
    document.querySelector('.notif-count')?.remove();
    document.querySelector('.notif-unread-pill')?.remove();
    document.querySelector('.notif-mark-all')?.remove();
}

document.addEventListener('click', e => {
    const wrap = document.getElementById('notifWrap');
    if (wrap && !wrap.contains(e.target)) closeNotif();
});

/* ═══════════════════════════════════════
   AUTO-HIDE ALERTS
═══════════════════════════════════════ */
const alertEl = document.getElementById('dashAlert');
if (alertEl) {
    setTimeout(() => {
        alertEl.style.transition = 'opacity 0.5s';
        alertEl.style.opacity    = '0';
        setTimeout(() => alertEl.remove(), 500);
    }, 4000);
}
</script>

</body>
</html>