<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];

$msg = $msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $bid = intval($_POST['booking_id'] ?? 0);
    if ($bid > 0) {
        $chk = $conn->prepare("SELECT booking_id, status FROM bookings WHERE booking_id = ? AND user_id = ?");
        $chk->bind_param("ii", $bid, $user_id);
        $chk->execute();
        $chk->store_result();
        $chk->bind_result($b_id, $b_status);
        $chk->fetch();

        if ($chk->num_rows === 0) {
            $msg      = "Booking not found.";
            $msg_type = "error";
        } elseif (in_array($b_status, ['cancelled', 'rejected'])) {
            $msg      = "This booking is already cancelled or rejected.";
            $msg_type = "error";
        } else {
            $upd = $conn->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = ? AND user_id = ?");
            $upd->bind_param("ii", $bid, $user_id);
            $upd->execute();
            $msg      = "Booking #" . str_pad($bid, 5, '0', STR_PAD_LEFT) . " has been cancelled successfully.";
            $msg_type = "success";
            $upd->close();
        }
        $chk->close();
    }
}

/* ── Fetch user info ── */
$uq = $conn->prepare("SELECT full_name, email, created_at FROM users WHERE user_id = ?");
$uq->bind_param("i", $user_id);
$uq->execute();
$uq->bind_result($u_name, $u_email, $u_joined);
$uq->fetch();
$uq->close();

/* ── Fetch bookings ── */
$active_tab = $_GET['tab'] ?? 'all';
$allowed_tabs = ['all', 'pending', 'confirmed', 'cancelled'];
if (!in_array($active_tab, $allowed_tabs)) $active_tab = 'all';

$where = $active_tab !== 'all' ? "AND status = '" . $conn->real_escape_string($active_tab) . "'" : '';

$bq = $conn->query("
    SELECT booking_id, room_type, check_in, check_out, guests, status, created_at
    FROM bookings
    WHERE user_id = $user_id $where
    ORDER BY created_at DESC
");

$bookings = [];
while ($row = $bq->fetch_assoc()) $bookings[] = $row;

/* ── Count by status ── */
$counts = ['all' => 0, 'pending' => 0, 'confirmed' => 0, 'cancelled' => 0];
$cq = $conn->query("SELECT status, COUNT(*) as n FROM bookings WHERE user_id = $user_id GROUP BY status");
while ($row = $cq->fetch_assoc()) {
    $s = strtolower($row['status']);
    if (isset($counts[$s])) $counts[$s] += $row['n'];
    $counts['all'] += $row['n'];
}

/* ── Helpers ── */
function statusBadge($s) {
    $map = [
        'pending'   => ['#f59e0b', '#fef3c7', 'fa-clock',       'Pending'],
        'confirmed' => ['#10b981', '#d1fae5', 'fa-circle-check','Confirmed'],
        'cancelled' => ['#6b7280', '#f3f4f6', 'fa-ban',         'Cancelled'],
        'rejected'  => ['#ef4444', '#fee2e2', 'fa-circle-xmark','Rejected'],
    ];
    $d = $map[strtolower($s)] ?? ['#6b7280','#f3f4f6','fa-question','Unknown'];
    return "<span style='display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;background:{$d[1]};color:{$d[0]};font-size:0.75rem;font-weight:700;letter-spacing:.04em;'>
        <i class='fa-solid {$d[2]}' style='font-size:.7rem;'></i>{$d[3]}</span>";
}

function nightCount($ci, $co) {
    return max(1, (int)((strtotime($co) - strtotime($ci)) / 86400));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile</title>
    <link rel="icon" href="../assets/images/cv_logo.png">
    <link rel="stylesheet" href="../assets/css/profile.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <div class="topbar-left">
        <div class="topbar-lang">
            <i class="fa-solid fa-globe"></i>
            <select onchange="changeLanguage(this.value)" aria-label="Select Language">
                <option value="en" selected>English</option>
                <option value="fil">Filipino</option>
            </select>
        </div>
    </div>
    <div class="topbar-right">
        <a href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer" class="topbar-link">
            <i class="fa-solid fa-location-dot"></i> Barosong, Tigbauan, Iloilo
        </a>
        <span class="topbar-divider">|</span>
        <a href="mailto:coravergelresort@gmail.com" class="topbar-link">
            <i class="fa-regular fa-envelope"></i> coravergelresort@gmail.com
        </a>
    </div>
</div>

<!-- Mobile sidebar toggle -->
<button class="sb-toggle" id="sbToggle" onclick="toggleSidebar()">
    <i class="fa-solid fa-bars"></i>
</button>
<div class="sidebar-overlay" id="sbOverlay" onclick="toggleSidebar()"></div>

<!-- ════════════════════════════════════
     SIDEBAR
════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">

    <!-- Brand -->
    <a href="./dashboard.php" class="sb-brand">
        <div class="sb-logo-wrap">
            <img src="../assets/images/cv_logo.png" alt="CoraVergel" class="sb-logo-img">
            <span class="sb-logo-ring"></span>
        </div>
        <div class="sb-brand-text">
            <span class="sb-brand-name">CoraVergel</span>
            <span class="sb-brand-sub">Resort</span>
        </div>
    </a>

    <!-- User identity -->
    <div class="sb-user">
        <div class="sb-avatar"><?= strtoupper(mb_substr($u_name, 0, 1)) ?></div>
        <div class="sb-user-name"><?= htmlspecialchars($u_name) ?></div>
        <div class="sb-user-email"><?= htmlspecialchars($u_email) ?></div>
        <div class="sb-user-badge"><i class="fa-solid fa-star" style="font-size:.6rem;"></i> Guest Member</div>
    </div>

    <!-- Navigation -->
    <nav class="sb-nav">
        <div class="sb-nav-label">Menu</div>

        <a href="../user/dashboard.php" class="sb-link">
            <i class="fa-solid fa-house"></i> Dashboard
        </a>

        <a href="profile.php?page-header" class="sb-link <?= $active_tab === 'profile' ? 'active' : '' ?>">
            <i class="fa-regular fa-user"></i> My Profile
        </a>

        <div class="sb-nav-label" style="margin-top:8px;">Bookings</div>

        <a href="profile.php#all-bookings" class="sb-link <?= $active_tab === 'all' ? 'active' : '' ?>">
            <i class="fa-solid fa-calendar-days"></i> All Bookings
            <?php if ($counts['all']): ?>
                <span class="sb-badge"><?= $counts['all'] ?></span>
            <?php endif; ?>
        </a>

        <a href="profile.php?tab=pending" class="sb-link <?= $active_tab === 'pending' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock"></i> Pending
            <?php if ($counts['pending']): ?>
                <span class="sb-badge pending"><?= $counts['pending'] ?></span>
            <?php endif; ?>
        </a>

        <a href="profile.php?tab=confirmed" class="sb-link <?= $active_tab === 'confirmed' ? 'active' : '' ?>">
            <i class="fa-solid fa-circle-check"></i> Confirmed
            <?php if ($counts['confirmed']): ?>
                <span class="sb-badge" style="background:var(--green);"><?= $counts['confirmed'] ?></span>
            <?php endif; ?>
        </a>

        <a href="profile.php?tab=cancelled" class="sb-link <?= $active_tab === 'cancelled' ? 'active' : '' ?>">
            <i class="fa-solid fa-ban"></i> Cancelled
            <?php if ($counts['cancelled']): ?>
                <span class="sb-badge" style="background:var(--gray);"><?= $counts['cancelled'] ?></span>
            <?php endif; ?>
        </a>

        <div class="sb-divider"></div>
        <div class="sb-nav-label">Quick Links</div>

        <a href="../user/rooms.php" class="sb-link">
            <i class="fa-solid fa-bed"></i> Browse Rooms
        </a>

        <a href="../user/dashboard.php#booking-section" class="sb-link">
            <i class="fa-solid fa-plus"></i> New Booking
        </a>

        <a href="../frontend/reviews.php" class="sb-link">
            <i class="fa-regular fa-star"></i> Reviews
        </a>

        <a href="../frontend/special_offers.php" class="sb-link">
            <i class="fa-solid fa-tag"></i> Special Offers
        </a>
    </nav>

    <!-- Logout -->
    <div class="sb-bottom">
        <a href="../user/logout.php" class="sb-logout">
            <i class="fa-solid fa-right-from-bracket"></i> Sign Out
        </a>
    </div>

</aside>

<!-- ════════════════════════════════════
     MAIN CONTENT
════════════════════════════════════ -->
<main class="main">

    <!-- Page header -->
    <div id="page-header" class="page-header">
        <div class="page-title-group">
            <h1>My Profile</h1>
            <p>Manage your bookings and account information</p>
        </div>
        <div class="page-header-action">
            <a href="../user/dashboard.php#booking-section" class="btn-book-new">
                <i class="fa-solid fa-plus"></i> New Booking
            </a>
        </div>
    </div>

    <!-- Alert -->
    <?php if ($msg): ?>
    <div class="alert <?= $msg_type ?>">
        <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
    <?php endif; ?>

    <!-- Profile info card -->
    <div class="profile-info-card">
        <div class="pic-banner"></div>
        <div class="pic-body">
            <div class="pic-avatar-wrap">
                <div class="pic-avatar"><?= strtoupper(mb_substr($u_name, 0, 1)) ?></div>
            </div>
            <div class="pic-name"><?= htmlspecialchars($u_name) ?></div>
            <div class="pic-email"><?= htmlspecialchars($u_email) ?></div>
            <div class="pic-joined">
                <i class="fa-regular fa-calendar"></i>
                Member since <?= date('F Y', strtotime($u_joined)) ?>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon all"><i class="fa-solid fa-calendar-days"></i></div>
            <div>
                <div class="stat-num"><?= $counts['all'] ?></div>
                <div class="stat-lbl">Total Bookings</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon pending"><i class="fa-solid fa-clock"></i></div>
            <div>
                <div class="stat-num"><?= $counts['pending'] ?></div>
                <div class="stat-lbl">Pending</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon confirmed"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="stat-num"><?= $counts['confirmed'] ?></div>
                <div class="stat-lbl">Confirmed</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon cancelled"><i class="fa-solid fa-ban"></i></div>
            <div>
                <div class="stat-num"><?= $counts['cancelled'] ?></div>
                <div class="stat-lbl">Cancelled</div>
            </div>
        </div>
    </div>

    <!-- Filter tabs -->
    <div class="filter-tabs">
        <?php foreach ([
            ['all',       'fa-calendar-days',  'All',       ''],
            ['pending',   'fa-clock',          'Pending',   'pending'],
            ['confirmed', 'fa-circle-check',   'Confirmed', ''],
            ['cancelled', 'fa-ban',            'Cancelled', ''],
        ] as [$tab, $icon, $label, $badgeCls]): ?>
        <a href="profile.php?tab=<?= $tab ?>"
           class="filter-tab <?= $active_tab === $tab ? 'active' : '' ?>">
            <i class="fa-solid <?= $icon ?>"></i>
            <?= $label ?>
            <span class="tab-count"><?= $counts[$tab] ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Bookings list -->
    <div class="bookings-wrap">
        <div id="all-bookings" class="bw-header">
            <h2>
                <?php
                $tabLabels = ['all'=>'All Bookings','pending'=>'Pending Bookings','confirmed'=>'Confirmed Bookings','cancelled'=>'Cancelled Bookings'];
                echo $tabLabels[$active_tab];
                ?>
            </h2>
            <span><?= count($bookings) ?> record<?= count($bookings) !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (empty($bookings)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-regular fa-calendar-xmark"></i></div>
            <h3>No bookings found</h3>
            <p>
                <?php if ($active_tab === 'all'): ?>
                    You haven't made any bookings yet. Ready to plan your stay?
                <?php else: ?>
                    No <?= $active_tab ?> bookings to show.
                <?php endif; ?>
            </p>
            <a href="../user/dashboard.php#booking-section" class="btn-book-new" style="display:inline-flex;margin:0 auto;">
                <i class="fa-solid fa-plus"></i> Book Your First Stay
            </a>
        </div>

        <?php else: ?>
        <div class="booking-list">
            <?php foreach ($bookings as $b):
                $nts   = nightCount($b['check_in'], $b['check_out']);
                $canCancel = in_array(strtolower($b['status']), ['pending', 'confirmed']);
                $bid_fmt = '#' . str_pad($b['booking_id'], 5, '0', STR_PAD_LEFT);
            ?>
            <div class="booking-card">

                <!-- Top: ID + Room name + Status -->
                <div class="bc-top">
                    <div>
                        <div class="bc-id"><?= $bid_fmt ?></div>
                        <div class="bc-room"><?= htmlspecialchars($b['room_type']) ?></div>
                    </div>
                    <?= statusBadge($b['status']) ?>
                </div>

                <!-- Details grid -->
                <div class="bc-body">
                    <div>
                        <div class="bc-field-label"><i class="fa-solid fa-plane-arrival" style="margin-right:3px;"></i> Check-in</div>
                        <div class="bc-field-val"><?= date('M j, Y', strtotime($b['check_in'])) ?></div>
                        <div class="bc-field-sub"><?= date('D', strtotime($b['check_in'])) ?></div>
                    </div>
                    <div>
                        <div class="bc-field-label"><i class="fa-solid fa-plane-departure" style="margin-right:3px;"></i> Check-out</div>
                        <div class="bc-field-val"><?= date('M j, Y', strtotime($b['check_out'])) ?></div>
                        <div class="bc-field-sub"><?= date('D', strtotime($b['check_out'])) ?></div>
                    </div>
                    <div>
                        <div class="bc-field-label"><i class="fa-solid fa-moon" style="margin-right:3px;"></i> Duration</div>
                        <div class="bc-field-val"><?= $nts ?> night<?= $nts !== 1 ? 's' : '' ?></div>
                    </div>
                    <div>
                        <div class="bc-field-label"><i class="fa-solid fa-user-group" style="margin-right:3px;"></i> Guests</div>
                        <div class="bc-field-val"><?= $b['guests'] ?> pax</div>
                    </div>
                </div>

                <!-- Footer: booked at + cancel -->
                <div class="bc-footer">
                    <span class="bc-booked-at">
                        <i class="fa-regular fa-clock"></i>
                        Booked <?= date('M j, Y · g:i A', strtotime($b['created_at'])) ?>
                    </span>

                    <?php if ($canCancel): ?>
                    <button class="btn-cancel"
                            onclick="confirmCancel(<?= $b['booking_id'] ?>, '<?= addslashes(htmlspecialchars($b['room_type'])) ?>', '<?= date('M j, Y', strtotime($b['check_in'])) ?>', '<?= date('M j, Y', strtotime($b['check_out'])) ?>')">
                        <i class="fa-solid fa-xmark"></i> Cancel Booking
                    </button>
                    <?php else: ?>
                    <span style="font-size:0.75rem;color:var(--gray);font-style:italic;">
                        <?= strtolower($b['status']) === 'cancelled' ? 'Booking cancelled' : 'Cannot be cancelled' ?>
                    </span>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- ════════════════════════════════════
     CANCEL CONFIRM MODAL
════════════════════════════════════ -->
<div class="modal-overlay" id="cancelModal">
    <div class="modal-box">
        <div class="modal-hd">
            <h3><i class="fa-solid fa-triangle-exclamation" style="margin-right:8px;color:#f59e0b;"></i> Cancel Booking?</h3>
            <button class="modal-close-btn" onclick="closeCancel()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-bd">
            <p>You are about to cancel:</p>
            <p style="background:var(--light);padding:12px 16px;border-radius:8px;border:1px solid var(--border);margin-bottom:16px;line-height:1.7;">
                <strong id="mc_room">—</strong><br>
                <span style="font-size:0.83rem;color:var(--gray);">
                    <i class="fa-solid fa-calendar" style="color:var(--gold);margin-right:4px;"></i>
                    <span id="mc_dates">—</span>
                </span>
            </p>
            <div class="modal-warning">
                <i class="fa-solid fa-circle-exclamation"></i>
                This action cannot be undone. Once cancelled, you will need to make a new booking if you change your mind.
            </div>
            <form method="POST" action="profile.php?tab=<?= htmlspecialchars($active_tab) ?>">
                <input type="hidden" name="action"     value="cancel">
                <input type="hidden" name="booking_id" id="mc_id" value="">
                <div class="modal-acts">
                    <button type="button" class="btn-keep" onclick="closeCancel()">
                        Keep Booking
                    </button>
                    <button type="submit" class="btn-confirm-cancel">
                        <i class="fa-solid fa-xmark"></i> Yes, Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* ── Sidebar mobile toggle ── */
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sbOverlay').classList.toggle('show');
}

/* ── Cancel modal ── */
function confirmCancel(id, room, ci, co) {
    document.getElementById('mc_id').value    = id;
    document.getElementById('mc_room').textContent  = room;
    document.getElementById('mc_dates').textContent = ci + ' → ' + co;
    document.getElementById('cancelModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeCancel() {
    document.getElementById('cancelModal').classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) closeCancel();
});

/* ── Auto-dismiss alert after 5s ── */
const alertEl = document.querySelector('.alert');
if (alertEl) {
    setTimeout(() => {
        alertEl.style.transition = 'opacity .5s, transform .5s';
        alertEl.style.opacity = '0';
        alertEl.style.transform = 'translateY(-6px)';
        setTimeout(() => alertEl.remove(), 500);
    }, 5000);
}
</script>

</body>
</html>