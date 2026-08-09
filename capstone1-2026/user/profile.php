<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$msg = $msg_type = '';
$active_view = $_GET['view'] ?? 'main'; // main | edit | notifications

/* ══════════════════════════════════════════
   POST HANDLERS
══════════════════════════════════════════ */

/* ── Cancel booking ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $bid = intval($_POST['booking_id'] ?? 0);
    if ($bid > 0) {
        $chk = $conn->prepare("SELECT booking_id, status FROM bookings WHERE booking_id=? AND user_id=?");
        $chk->bind_param("ii", $bid, $user_id); $chk->execute(); $chk->store_result();
        $chk->bind_result($b_id, $b_status); $chk->fetch();
        if ($chk->num_rows === 0) {
            $msg = "Booking not found."; $msg_type = "error";
        } elseif (in_array(strtolower($b_status), ['confirmed','cancelled','rejected'])) {
            $msg = strtolower($b_stWatus) === 'confirmed'
                ? "Confirmed bookings cannot be cancelled. Please contact the resort directly."
                : "Already cancelled or rejected.";
            $msg_type = "error";
        } else {
            $upd = $conn->prepare("UPDATE bookings SET status='cancelled' WHERE booking_id=? AND user_id=?");
            $upd->bind_param("ii", $bid, $user_id); $upd->execute(); $upd->close();
            $msg = "Booking #".str_pad($bid,5,'0',STR_PAD_LEFT)." has been cancelled.";
            $msg_type = "success";
        }
        $chk->close();
    }
    $active_view = 'main';
}

/* ── Update profile ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $new_name  = trim($_POST['full_name'] ?? '');
    $new_phone = trim($_POST['phone'] ?? '');
    if (empty($new_name)) {
        $msg = "Full name cannot be empty."; $msg_type = "error";
    } elseif (!empty($new_phone) && !preg_match('/^[0-9+\-\s()]{7,20}$/', $new_phone)) {
        $msg = "Please enter a valid phone number."; $msg_type = "error";
    } else {
        $upd = $conn->prepare("UPDATE users SET full_name=?, phone=? WHERE user_id=?");
        $upd->bind_param("ssi", $new_name, $new_phone, $user_id); $upd->execute(); $upd->close();
        $msg = "Profile updated successfully."; $msg_type = "success";
    }
    $active_view = 'edit';
}

/* ── Change password ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $cur  = $_POST['current_password'] ?? '';
    $new  = $_POST['new_password'] ?? '';
    $conf = $_POST['confirm_password'] ?? '';
    $hq = $conn->prepare("SELECT password FROM users WHERE user_id=?");
    $hq->bind_param("i", $user_id); $hq->execute();
    $hq->bind_result($stored_hash); $hq->fetch(); $hq->close();
    if (!password_verify($cur, $stored_hash)) {
        $msg = "Current password is incorrect."; $msg_type = "error";
    } elseif (strlen($new) < 8) {
        $msg = "New password must be at least 8 characters."; $msg_type = "error";
    } elseif ($new !== $conf) {
        $msg = "Passwords do not match."; $msg_type = "error";
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $upd  = $conn->prepare("UPDATE users SET password=? WHERE user_id=?");
        $upd->bind_param("si", $hash, $user_id); $upd->execute(); $upd->close();
        $msg = "Password changed successfully."; $msg_type = "success";
    }
    $active_view = 'edit';
}

/* ══════════════════════════════════════════
   FETCH DATA
══════════════════════════════════════════ */

/* ── User info ── */
$uq = $conn->prepare("SELECT full_name, email, phone, created_at FROM users WHERE user_id=?");
$uq->bind_param("i", $user_id); $uq->execute();
$uq->bind_result($u_name, $u_email, $u_phone, $u_joined); $uq->fetch(); $uq->close();
$initials   = strtoupper(mb_substr($u_name, 0, 1));
$first_name = explode(' ', trim($u_name))[0];

/* ── Bookings ── */
$active_tab = $_GET['tab'] ?? 'all';
if (!in_array($active_tab, ['all','pending','confirmed','cancelled'])) $active_tab = 'all';
$where = $active_tab !== 'all' ? "AND status='".$conn->real_escape_string($active_tab)."'" : '';
$bq = $conn->query("SELECT booking_id,room_type,check_in,check_out,guests,status,created_at FROM bookings WHERE user_id=$user_id $where ORDER BY created_at DESC");
$bookings = [];
while ($row = $bq->fetch_assoc()) $bookings[] = $row;

/* ── Counts ── */
$counts = ['all'=>0,'pending'=>0,'confirmed'=>0,'cancelled'=>0];
$cq = $conn->query("SELECT status,COUNT(*) n FROM bookings WHERE user_id=$user_id GROUP BY status");
while ($row = $cq->fetch_assoc()) {
    $s = strtolower($row['status']);
    if (isset($counts[$s])) $counts[$s] += $row['n'];
    $counts['all'] += $row['n'];
}

/* ── Notifications ── */
$notifs = [
    ['type'=>'confirmed','icon'=>'fa-circle-check','text'=>'Booking <strong>#00042</strong> has been confirmed!','time'=>'2 hours ago','unread'=>true],
    ['type'=>'pending',  'icon'=>'fa-clock',       'text'=>'Booking <strong>#00041</strong> is awaiting approval.','time'=>'Yesterday','unread'=>true],
    ['type'=>'promo',    'icon'=>'fa-tag',          'text'=>'Special weekend rates are now available.','time'=>'3 days ago','unread'=>false],
];
$unread_count = count(array_filter($notifs, fn($n)=>$n['unread']));

/* ── Helpers ── */
function nightCount($ci,$co){ return max(1,(int)((strtotime($co)-strtotime($ci))/86400)); }
function statusInfo($s){
    return match(strtolower($s)){
        'pending'   => ['label'=>'Pending',  'cls'=>'s-pending'],
        'confirmed' => ['label'=>'Confirmed','cls'=>'s-confirmed'],
        'cancelled' => ['label'=>'Cancelled','cls'=>'s-cancelled'],
        'rejected'  => ['label'=>'Rejected', 'cls'=>'s-rejected'],
        default     => ['label'=>'Unknown',  'cls'=>'s-cancelled'],
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>My Profile — CoraVergel Resort</title>
<link rel="icon" href="../assets/images/cv_logo.png">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;0,700;1,400;1,600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="../assets/css/user.css">
</head>
<body class="profile-page">

<button class="sb-toggle" id="sbToggle" onclick="toggleSB()" aria-label="Menu">
    <i class="fa-solid fa-bars"></i>
</button>
<div class="sb-mask" id="sbMask" onclick="toggleSB()"></div>

<!-- ══════════ SIDEBAR ══════════ -->
<aside class="sidebar" id="sidebar">

    <div class="sb-profile">
        <div class="sb-avatar"><?= $initials ?></div>
        <div class="sb-uname"><?= htmlspecialchars($u_name) ?></div>
        <button class="sb-edit-link" onclick="switchView('edit')">
            <i class="fa-solid fa-pencil"></i> Edit Profile
        </button>
    </div>

    <nav class="sb-nav">
        <div class="sb-section">Overview</div>
        <a href="../user/dashboard.php" class="sb-link"><i class="fa-solid fa-house"></i> Home</a>
        <button class="sb-link active" id="nav-main" onclick="switchView('main')">
            <i class="fa-regular fa-user"></i> My Account
        </button>
        <button class="sb-link" id="nav-bookings" onclick="switchView('main')">
            <i class="fa-solid fa-shopping-bag"></i> My Bookings
        </button>
        <button class="sb-link" id="nav-notifs" onclick="switchView('notifications')" style="justify-content:space-between;">
            <span style="display:flex;align-items:center;gap:10px;">
                <i class="fa-solid fa-bell"></i> Notifications
            </span>
            <?php if($unread_count > 0): ?>
            <span class="sb-cnt"><?= $unread_count ?></span>
            <?php endif; ?>
        </button>
        <button class="sb-link" onclick="alert('Coming soon!')"><i class="fa-solid fa-ticket"></i> My Vouchers</button>
        <button class="sb-link" onclick="alert('Coming soon!')"><i class="fa-solid fa-coins"></i> Resort Credits</button>
        <a href="../frontend/reviews.php" class="sb-link"><i class="fa-regular fa-star"></i> My Reviews</a>
        <button class="sb-link" id="nav-edit" onclick="switchView('edit')"><i class="fa-solid fa-gear"></i> Settings</button>
    </nav>

    <div class="sb-bottom">
        <a href="../user/logout.php" class="sb-signout">
            <i class="fa-solid fa-right-from-bracket"></i> Sign Out
        </a>
    </div>
</aside>

<!-- ══════════ MAIN ══════════ -->
<main class="main">


<div class="view active" id="view-main">

    <div class="hero-banner">
        <div class="hero-banner-grid"></div>
        <div class="hero-inner">
            <div class="hero-avatar"><?= $initials ?></div>
            <div class="hero-info">
                <div class="hero-greet">Welcome back</div>
                <div class="hero-name">Hello, <em><?= htmlspecialchars($first_name) ?></em></div>
                <div class="hero-sub"><?= htmlspecialchars($u_email) ?></div>
                <div class="hero-tags">
                    <span class="hero-tag gold"><i class="fa-solid fa-star"></i> Guest Member</span>
                    <span class="hero-tag"><i class="fa-regular fa-calendar"></i> Since <?= date('F Y', strtotime($u_joined)) ?></span>
                    <span class="hero-tag"><i class="fa-solid fa-calendar-days"></i> <?= $counts['all'] ?> booking<?= $counts['all']!==1?'s':'' ?></span>
                </div>
            </div>
            <div class="hero-actions">
                <a href="../user/dashboard.php#booking-section" class="btn-primary">
                    <i class="fa-solid fa-plus"></i> New Booking
                </a>
                <a href="../user/rooms.php" class="btn-ghost">
                    <i class="fa-solid fa-bed"></i> Browse Rooms
                </a>
            </div>
        </div>
    </div>

    <div class="content">

        <?php if($msg && $active_view !== 'edit'): ?>
        <div class="alert <?= $msg_type ?>">
            <i class="fa-solid <?= $msg_type==='success'?'fa-circle-check':'fa-circle-exclamation' ?>"></i>
            <span><?= htmlspecialchars($msg) ?></span>
        </div>
        <?php endif; ?>

        <div class="stats-row">
            <div class="stat-card c-all"><div class="stat-ic"><i class="fa-solid fa-calendar-days"></i></div><div class="stat-num"><?= $counts['all'] ?></div><div class="stat-lbl">Total Bookings</div></div>
            <div class="stat-card c-pend"><div class="stat-ic"><i class="fa-solid fa-clock"></i></div><div class="stat-num"><?= $counts['pending'] ?></div><div class="stat-lbl">Awaiting Confirmation</div></div>
            <div class="stat-card c-conf"><div class="stat-ic"><i class="fa-solid fa-circle-check"></i></div><div class="stat-num"><?= $counts['confirmed'] ?></div><div class="stat-lbl">Confirmed Stays</div></div>
            <div class="stat-card c-canc"><div class="stat-ic"><i class="fa-solid fa-ban"></i></div><div class="stat-num"><?= $counts['cancelled'] ?></div><div class="stat-lbl">Cancelled</div></div>
        </div>

        <div class="sec-hd">
            <h2 class="sec-title">My Reservations</h2>
            <span class="sec-sub"><?= count($bookings) ?> record<?= count($bookings)!==1?'s':'' ?></span>
        </div>

        <div class="tab-strip" id="all-bookings">
            <?php foreach([['all','fa-calendar-days','All'],['pending','fa-clock','Pending'],['confirmed','fa-circle-check','Confirmed'],['cancelled','fa-ban','Cancelled']] as [$t,$ic,$lb]): ?>
            <a href="profile.php?tab=<?=$t?>#all-bookings" class="tab-pill <?= $active_tab===$t?'on':'' ?>">
                <i class="fa-solid <?=$ic?>"></i> <?=$lb?> <span class="tab-n"><?=$counts[$t]?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if(empty($bookings)): ?>
        <div class="empty-wrap">
            <div class="empty-ic"><i class="fa-regular fa-calendar-xmark"></i></div>
            <div class="empty-title">No reservations here</div>
            <p class="empty-sub"><?= $active_tab==='all' ? "You haven't made any bookings yet." : "No ".htmlspecialchars($active_tab)." bookings at the moment." ?></p>
            <a href="../user/dashboard.php#booking-section" class="btn-primary" style="display:inline-flex;"><i class="fa-solid fa-plus"></i> Book Your Stay</a>
        </div>
        <?php else: ?>
        <div class="booking-stack">
            <?php foreach($bookings as $idx => $b):
                $nts=$nts=nightCount($b['check_in'],$b['check_out']);
                $sl=strtolower($b['status']);$si=statusInfo($sl);
                $bfmt=str_pad($b['booking_id'],5,'0',STR_PAD_LEFT);
                $cid='bk-'.$b['booking_id'];$open=$idx===0?'open':'';
                $canCancel=($sl==='pending');
            ?>
            <div class="bk-card <?= $open ?>" id="<?= $cid ?>">
                <div class="bk-row" onclick="toggleBK('<?= $cid ?>')">
                    <div class="bk-bar <?= $sl ?>"></div>
                    <div class="bk-main">
                        <div class="bk-ref">Booking #<?= $bfmt ?></div>
                        <div class="bk-room"><?= htmlspecialchars($b['room_type']) ?></div>
                    </div>
                    <div class="bk-dates-chip">
                        <i class="fa-regular fa-calendar"></i>
                        <?= date('M j',strtotime($b['check_in'])) ?> &rarr; <?= date('M j, Y',strtotime($b['check_out'])) ?>
                        &nbsp;&middot;&nbsp; <?= $nts ?>N
                    </div>
                    <div class="bk-right">
                        <span class="s-pill <?= $si['cls'] ?>"><?= $si['label'] ?></span>
                        <div class="bk-chevron"><i class="fa-solid fa-chevron-down"></i></div>
                    </div>
                </div>
                <div class="bk-panel">
                    <div class="bk-detail">
                        <div class="bk-grid">
                            <div><div class="bk-field-lbl"><i class="fa-solid fa-plane-arrival"></i> Check-in</div><div class="bk-field-val"><?= date('M j, Y',strtotime($b['check_in'])) ?></div><div class="bk-field-sub"><?= date('l',strtotime($b['check_in'])) ?></div></div>
                            <div><div class="bk-field-lbl"><i class="fa-solid fa-plane-departure"></i> Check-out</div><div class="bk-field-val"><?= date('M j, Y',strtotime($b['check_out'])) ?></div><div class="bk-field-sub"><?= date('l',strtotime($b['check_out'])) ?></div></div>
                            <div><div class="bk-field-lbl"><i class="fa-solid fa-moon"></i> Duration</div><div class="bk-field-val"><?= $nts ?> night<?= $nts!==1?'s':'' ?></div></div>
                            <div><div class="bk-field-lbl"><i class="fa-solid fa-user-group"></i> Guests</div><div class="bk-field-val"><?= $b['guests'] ?> pax</div></div>
                        </div>
                        <div class="bk-foot">
                            <span class="bk-timestamp"><i class="fa-regular fa-clock"></i> Booked <?= date('M j, Y · g:i A',strtotime($b['created_at'])) ?></span>
                            <?php if($canCancel): ?>
                            <button class="btn-cancel" onclick="openCancel(<?= $b['booking_id'] ?>,'<?= addslashes(htmlspecialchars($b['room_type'])) ?>','<?= date('M j, Y',strtotime($b['check_in'])) ?>','<?= date('M j, Y',strtotime($b['check_out'])) ?>')">
                                <i class="fa-solid fa-xmark"></i> Cancel Booking
                            </button>
                            <?php elseif($sl==='confirmed'): ?>
                            <span class="confirmed-note"><i class="fa-solid fa-circle-check"></i> Confirmed — contact resort to make changes</span>
                            <?php else: ?>
                            <span style="font-size:11.5px;color:var(--muted);font-style:italic;"><?= $sl==='cancelled'?'Booking cancelled':'Cannot be modified' ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div><!-- /view-main -->


<!-- ╔══════════════════════════════╗
     ║   VIEW: EDIT PROFILE         ║
     ╚══════════════════════════════╝ -->
<div class="view" id="view-edit">

    <div class="view-header">
        <div class="view-header-grid"></div>
        <div class="view-header-inner">
            <button class="view-back" onclick="switchView('main')">
                <i class="fa-solid fa-arrow-left"></i> Back
            </button>
            <div>
                <div class="view-eyebrow">Account Settings</div>
                <div class="view-title">Edit <em>Your Profile</em></div>
                <div class="view-sub">Manage your personal information and security</div>
            </div>
        </div>
    </div>

    <div class="ep-content">

        <?php if($msg && $active_view === 'edit'): ?>
        <div class="alert <?= $msg_type ?>">
            <i class="fa-solid <?= $msg_type==='success'?'fa-circle-check':'fa-circle-exclamation' ?>"></i>
            <span><?= htmlspecialchars($msg) ?></span>
        </div>
        <?php endif; ?>

        <!-- Personal Info -->
        <div class="card">
            <div class="card-hd">
                <div class="card-hd-icon blue"><i class="fa-regular fa-user"></i></div>
                <div><div class="card-hd-title">Personal Information</div><div class="card-hd-sub">Update your display name and contact number</div></div>
            </div>
            <div class="card-body">
                <div class="avatar-row">
                    <div class="edit-avatar"><?= $initials ?><span class="avatar-badge"><i class="fa-solid fa-pen"></i></span></div>
                    <div>
                        <div class="avatar-name"><?= htmlspecialchars($u_name) ?></div>
                        <div class="avatar-email"><?= htmlspecialchars($u_email) ?></div>
                        <div class="avatar-since"><i class="fa-regular fa-calendar"></i> Member since <?= date('F Y',strtotime($u_joined)) ?></div>
                    </div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="field-group">
                        <div class="field">
                            <label><i class="fa-solid fa-user"></i> Full Name</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($u_name) ?>" placeholder="Your full name" required>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-phone"></i> Phone Number</label>
                            <input type="tel" name="phone" value="<?= htmlspecialchars($u_phone ?? '') ?>" placeholder="e.g. +63 912 345 6789">
                            <span class="field-hint"><i class="fa-solid fa-circle-info"></i> Used for booking confirmations</span>
                        </div>
                    </div>
                    <div class="field-group single">
                        <div class="field">
                            <label><i class="fa-solid fa-envelope"></i> Email Address</label>
                            <input type="email" value="<?= htmlspecialchars($u_email) ?>" readonly>
                            <span class="field-hint"><i class="fa-solid fa-lock"></i> Email cannot be changed. Contact support if needed.</span>
                        </div>
                    </div>
                    <div class="submit-row">
                        <button type="button" class="btn-discard" onclick="switchView('main')">Discard</button>
                        <button type="submit" class="btn-save gold-btn"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-hd">
                <div class="card-hd-icon rose"><i class="fa-solid fa-lock"></i></div>
                <div><div class="card-hd-title">Change Password</div><div class="card-hd-sub">Keep your account safe with a strong password</div></div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="field-group single">
                        <div class="field">
                            <label><i class="fa-solid fa-lock"></i> Current Password</label>
                            <div class="pw-wrap">
                                <input type="password" name="current_password" id="cur_pw" placeholder="Enter your current password" required>
                                <button type="button" class="pw-eye" onclick="togglePw('cur_pw',this)"><i class="fa-regular fa-eye"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="form-divider">New Password</div>
                    <div class="field-group">
                        <div class="field">
                            <label><i class="fa-solid fa-key"></i> New Password</label>
                            <div class="pw-wrap">
                                <input type="password" name="new_password" id="new_pw" placeholder="Min. 8 characters" oninput="checkStrength(this.value)" required>
                                <button type="button" class="pw-eye" onclick="togglePw('new_pw',this)"><i class="fa-regular fa-eye"></i></button>
                            </div>
                            <div class="pw-strength" id="pwStrength" style="display:none;">
                                <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwFill"></div></div>
                                <div class="pw-strength-label" id="pwLabel"></div>
                            </div>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-key"></i> Confirm New Password</label>
                            <div class="pw-wrap">
                                <input type="password" name="confirm_password" id="conf_pw" placeholder="Repeat new password" oninput="checkMatch()" required>
                                <button type="button" class="pw-eye" onclick="togglePw('conf_pw',this)"><i class="fa-regular fa-eye"></i></button>
                            </div>
                            <span class="field-hint" id="matchHint" style="display:none;"><i class="fa-solid fa-circle-check" style="color:var(--green)"></i> Passwords match</span>
                        </div>
                    </div>
                    <div class="submit-row">
                        <button type="button" class="btn-discard" onclick="switchView('main')">Cancel</button>
                        <button type="submit" class="btn-save"><i class="fa-solid fa-shield-halved"></i> Update Password</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Security Tips -->
        <div class="card">
            <div class="card-hd">
                <div class="card-hd-icon gold"><i class="fa-solid fa-shield-halved"></i></div>
                <div><div class="card-hd-title">Security Tips</div><div class="card-hd-sub">Keep your CoraVergel account protected</div></div>
            </div>
            <div class="card-body">
                <div class="tip-list">
                    <div class="tip"><div class="tip-icon g"><i class="fa-solid fa-check"></i></div><div><strong>Use a strong password</strong> — Mix uppercase, lowercase, numbers, and symbols.</div></div>
                    <div class="tip"><div class="tip-icon a"><i class="fa-solid fa-triangle-exclamation"></i></div><div><strong>Never share your password</strong> — CoraVergel staff will never ask for it.</div></div>
                    <div class="tip"><div class="tip-icon b"><i class="fa-solid fa-rotate"></i></div><div><strong>Update regularly</strong> — Change your password every few months.</div></div>
                    <div class="tip"><div class="tip-icon g"><i class="fa-solid fa-phone"></i></div><div><strong>Keep your phone number updated</strong> — We use it for booking confirmations.</div></div>
                </div>
            </div>
        </div>

    </div>
</div><!-- /view-edit -->


<!-- ╔══════════════════════════════╗
     ║   VIEW: NOTIFICATIONS        ║
     ╚══════════════════════════════╝ -->
<div class="view" id="view-notifications">

    <div class="view-header">
        <div class="view-header-grid"></div>
        <div class="view-header-inner">
            <button class="view-back" onclick="switchView('main')">
                <i class="fa-solid fa-arrow-left"></i> Back
            </button>
            <div>
                <div class="view-eyebrow">Your Inbox</div>
                <div class="view-title">Notifications</div>
                <div class="view-sub"><?= $unread_count ?> unread message<?= $unread_count!==1?'s':'' ?></div>
            </div>
        </div>
    </div>

    <div class="notif-content">

        <div class="notif-filter">
            <button class="nf-btn on" onclick="filterNotifs('all',this)">All</button>
            <button class="nf-btn" onclick="filterNotifs('unread',this)">Unread</button>
            <button class="nf-btn" onclick="filterNotifs('confirmed',this)">Confirmed</button>
            <button class="nf-btn" onclick="filterNotifs('pending',this)">Pending</button>
            <button class="nf-btn" onclick="filterNotifs('promo',this)">Promos</button>
        </div>

        <div class="notif-list" id="notifList">
            <?php foreach($notifs as $n): ?>
            <div class="notif-item <?= $n['unread']?'unread':'' ?>" data-type="<?= $n['type'] ?>">
                <div class="notif-icon <?= $n['type'] ?>">
                    <i class="fa-solid <?= $n['icon'] ?>"></i>
                </div>
                <div class="notif-body">
                    <div class="notif-text"><?= $n['text'] ?></div>
                    <div class="notif-meta">
                        <i class="fa-regular fa-clock"></i> <?= $n['time'] ?>
                        <?php if($n['unread']): ?>
                        <span class="notif-unread-dot"></span> New
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</div><!-- /view-notifications -->

</main><!-- /main -->

<!-- Cancel Modal -->
<div class="modal-bg" id="cancelModal">
    <div class="modal-wrap">
        <div class="modal-top">
            <h3>Cancel Reservation</h3>
            <button class="modal-close" onclick="closeCancel()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="m-preview">
                <div class="room" id="mc_room">—</div>
                <div class="dates"><i class="fa-solid fa-calendar" style="color:var(--gold);font-size:11px;"></i><span id="mc_dates">—</span></div>
            </div>
            <div class="m-warning"><i class="fa-solid fa-circle-exclamation"></i> This cannot be undone. You'll need to make a new booking if you change your mind.</div>
            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="booking_id" id="mc_id" value="">
                <div class="modal-acts">
                    <button type="button" class="btn-keep" onclick="closeCancel()">Keep It</button>
                    <button type="submit" class="btn-yes-cancel"><i class="fa-solid fa-xmark"></i> Yes, Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* ── Initial view from PHP ── */
const initView = '<?= $active_view ?>';

/* Also read URL param on load in case linked directly */
const urlView = new URLSearchParams(window.location.search).get('view');
if(urlView && ['main','edit','notifications'].includes(urlView)){
    switchView(urlView);
} else {
    switchView(initView);
}

/* If hash is #all-bookings, scroll to it after view loads */
if(window.location.hash === '#all-bookings'){
    setTimeout(()=>{
        const el = document.getElementById('all-bookings');
        if(el) el.scrollIntoView({behavior:'smooth', block:'start'});
    }, 300);
}
/* ── View switcher ── */
function switchView(v){
    document.querySelectorAll('.view').forEach(el=>el.classList.remove('active'));
    document.getElementById('view-'+v).classList.add('active');
    window.scrollTo({top:0,behavior:'smooth'});
}

/* Load correct view on page load */
switchView(initView);

/* ── Sidebar toggle ── */
function toggleSB(){
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sbMask').classList.toggle('show');
}

/* ── Booking accordion ── */
function toggleBK(id){
    const c=document.getElementById(id),was=c.classList.contains('open');
    document.querySelectorAll('.bk-card.open').forEach(x=>x.classList.remove('open'));
    if(!was) c.classList.add('open');
}

/* ── Cancel modal ── */
function openCancel(id,room,ci,co){
    document.getElementById('mc_id').value=id;
    document.getElementById('mc_room').textContent=room;
    document.getElementById('mc_dates').textContent=ci+' → '+co;
    document.getElementById('cancelModal').classList.add('show');
    document.body.style.overflow='hidden';
}
function closeCancel(){
    document.getElementById('cancelModal').classList.remove('show');
    document.body.style.overflow='';
}
document.getElementById('cancelModal').addEventListener('click',function(e){if(e.target===this)closeCancel();});

/* ── Password helpers ── */
function togglePw(id,btn){
    const input=document.getElementById(id);
    const isText=input.type==='text';
    input.type=isText?'password':'text';
    btn.querySelector('i').className=isText?'fa-regular fa-eye':'fa-regular fa-eye-slash';
}
function checkStrength(val){
    const wrap=document.getElementById('pwStrength');
    const fill=document.getElementById('pwFill');
    const label=document.getElementById('pwLabel');
    if(!val){wrap.style.display='none';return;}
    wrap.style.display='block';
    let score=0;
    if(val.length>=8) score++;
    if(val.length>=12) score++;
    if(/[A-Z]/.test(val)) score++;
    if(/[0-9]/.test(val)) score++;
    if(/[^A-Za-z0-9]/.test(val)) score++;
    const levels=[{w:'15%',bg:'#ef4444',t:'Too weak'},{w:'30%',bg:'#f97316',t:'Weak'},{w:'55%',bg:'#eab308',t:'Fair'},{w:'78%',bg:'#3b82f6',t:'Good'},{w:'100%',bg:'#059669',t:'Strong'}];
    const l=levels[Math.min(score,4)];
    fill.style.width=l.w; fill.style.background=l.bg;
    label.textContent=l.t; label.style.color=l.bg;
    checkMatch();
}
function checkMatch(){
    const nw=document.getElementById('new_pw').value;
    const cf=document.getElementById('conf_pw').value;
    const hint=document.getElementById('matchHint');
    hint.style.display=(cf.length>0&&nw===cf)?'flex':'none';
}

/* ── Notification filter ── */
function filterNotifs(type,btn){
    document.querySelectorAll('.nf-btn').forEach(b=>b.classList.remove('on'));
    btn.classList.add('on');
    document.querySelectorAll('.notif-item').forEach(item=>{
        if(type==='all') item.style.display='flex';
        else if(type==='unread') item.style.display=item.classList.contains('unread')?'flex':'none';
        else item.style.display=item.dataset.type===type?'flex':'none';
    });
}

/* ── Auto-dismiss alert ── */
const al=document.querySelector('.alert');
if(al) setTimeout(()=>{al.style.transition='opacity .5s,transform .5s';al.style.opacity='0';al.style.transform='translateY(-6px)';setTimeout(()=>al.remove(),500);},5000);
</script>
</body>
</html>