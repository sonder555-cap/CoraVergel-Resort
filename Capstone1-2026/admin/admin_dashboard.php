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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — CoraVergel Resort</title>
<link rel="icon" href="../assets/images/logo/cv_logo.png" sizes="any">
<link rel="stylesheet" href="../assets/css/admin.css">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ── Search dropdown ── */
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
.sd-badge--guest{background:#e8eaf6;color:#3949ab;}
.sd-badge--admin{background:#fce4ec;color:#b71c1c;}
.sd-empty{padding:28px 16px;text-align:center;color:#bbb;font-size:13px;}
.sd-empty i{font-size:22px;display:block;margin-bottom:8px;color:#ddd;}
.sd-footer{padding:8px 16px;background:#fafafa;border-top:1px solid #f0f0f0;font-size:11px;color:#bbb;text-align:center;}
.ni--blue{background:#e8f0fe;color:#1a6abf;}
/* ── Filter toggle menu ── */
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
#usersTable{min-width:600px;}
.table-wrap{overflow-x:auto;overflow-y:visible;}
#section-bookings .table-card,
#section-users .table-card{overflow:visible;}
.action-dropdown{display:none;position:fixed;background:var(--white);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow-md);min-width:148px;z-index:9999;overflow:hidden;animation:adDropIn .15s ease;}
.action-menu .action-dropdown{display:none;}
.action-menu.open .action-dropdown{display:block;}
@keyframes adDropIn{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:translateY(0);}}
/* ── Booking Modal (compact) ── */
/* ══ BOOKING MODAL ══ */
.bmodal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;}
.bmodal-overlay.open{display:flex;}
.bmodal-box{background:#fff;border-radius:16px;width:100%;max-width:520px;margin:20px;overflow:hidden;border:0.5px solid #e0ddd8;}
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

/* ══ GUEST MODAL ══ */
.gmodal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;}
.gmodal-overlay.open{display:flex;}
.gmodal-box{background:#fff;border-radius:16px;width:100%;max-width:520px;margin:20px;overflow:hidden;border:0.5px solid #e0ddd8;}
.gmodal-top{background:#1a1a2e;padding:28px 28px 24px;}
.gmodal-top-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.gmodal-label{font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:rgba(200,169,110,.7);}
.gmodal-close{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.1);border:none;color:rgba(255,255,255,.6);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.gmodal-close:hover{background:rgba(255,255,255,.2);color:#fff;}
.gmodal-identity{display:flex;align-items:center;gap:16px;}
.gmodal-avatar{width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#c8a96e,#a07840);color:#1a1a2e;font-size:18px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:2px solid rgba(200,169,110,.3);}
.gmodal-name{font-size:20px;font-weight:700;color:#fff;font-family:'Cormorant Garamond',serif;margin-bottom:6px;}
.gmodal-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 12px;border-radius:20px;background:rgba(255,255,255,.08);border:0.5px solid rgba(255,255,255,.15);color:rgba(255,255,255,.65);font-size:11px;font-weight:500;}
.gmodal-body{padding:24px 28px;}
.gmodal-group-title{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#bbb;margin-bottom:10px;padding-bottom:8px;border-bottom:0.5px solid #f0ede8;}
.gmodal-fields{display:grid;gap:8px;}
.gmodal-fields.three{grid-template-columns:1fr 1fr 1fr;}
.gmodal-field{padding:12px 14px;border-radius:10px;background:#fafaf8;border:0.5px solid #f0ede8;}
.gmodal-field-lbl{font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#bbb;margin-bottom:4px;}
.gmodal-field-val{font-size:14px;font-weight:600;color:#1a1a2e;}
.gmodal-status-dot{display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:#16a34a;}
.gmodal-status-dot::before{content:'';width:7px;height:7px;border-radius:50%;background:#16a34a;display:inline-block;}
.gmodal-footer{padding:16px 28px 24px;display:flex;gap:10px;border-top:0.5px solid #f0ede8;}
.gmodal-btn-close{flex:1;padding:11px;border-radius:10px;background:#f5f5f3;color:#555;border:0.5px solid #e8e5e0;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:all .15s;text-decoration:none;}
.gmodal-btn-close:hover{background:#ebebea;color:#1a1a2e;}
.gmodal-btn-delete{flex:1;padding:11px;border-radius:10px;background:#fff5f5;color:#dc2626;border:0.5px solid rgba(220,38,38,.2);font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;transition:all .15s;text-decoration:none;}
.gmodal-btn-delete:hover{background:#dc2626;color:#fff;}
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

/* ── NEW: Overview upgrades ── */
.overview-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;}
.overview-grid--wide{grid-template-columns:2fr 1fr;}
.kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px;}
.kpi-card{background:#fff;border:1px solid #f0ece4;border-radius:12px;padding:16px 18px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);transition:box-shadow .2s;}
.kpi-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
.kpi-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.kpi-body{min-width:0;}
.kpi-label{font-size:11px;color:#aaa;font-weight:500;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;}
.kpi-value{font-size:22px;font-weight:600;line-height:1.1;color:#1a1a2e;}
.kpi-change{font-size:11px;margin-top:3px;display:flex;align-items:center;gap:3px;}
.kpi-change.up{color:#2e7d32;} .kpi-change.down{color:#c62828;} .kpi-change.neutral{color:#999;}

/* Quick Actions Bar */
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


/* Mini Calendar */
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

/* Revenue card */
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

/* Charts row */
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
/* Bottom grid */
.bottom-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
</style>
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
        <button class="sb-item active" onclick="showSection('overview',this)">
            <i class="fa-solid fa-chart-pie"></i><span>Dashboard</span>
        </button>
        <div class="sb-group-label">MANAGEMENT</div>
        <button class="sb-item" onclick="showSection('bookings',this)">
            <i class="fa-solid fa-calendar-check"></i><span>Bookings</span>
            <?php if($pending_count>0): ?><span class="sb-badge"><?=$pending_count?></span><?php endif; ?>
        </button>
        <button class="sb-item" onclick="showSection('users',this)">
            <i class="fa-solid fa-users"></i><span>Guests</span>
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

<!-- ══ MAIN ══ -->
<div class="main-wrap">

    <!-- Topbar -->
    <header class="topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
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

    <!-- ══════════════════════════════════════
         OVERVIEW — UPGRADED
    ══════════════════════════════════════ -->
    <section class="dash-section active" id="section-overview">
        <div class="section-header">
            <div>
                <h1>Dashboard</h1>
                <p>Welcome back, <?=htmlspecialchars($admin_name)?>. Here's what's happening today.</p>
            </div>
            <div class="section-date"><i class="fa-regular fa-calendar"></i> <?=date('F j, Y')?></div>
        </div>

        <!-- ── Quick Actions Bar ── -->
        <div class="quick-actions-bar">
            <span class="qa-label"><i class="fa-solid fa-bolt"></i> Quick actions</span>
            <?php if($pending_count > 0): ?>
            <?php endif; ?>
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

        <!-- ── KPI Cards ── -->
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

        <!-- ── Charts Row ── -->
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

        <!-- ── Bottom Grid: Activity Feed + Revenue + Calendar ── -->
        <div class="bottom-grid">

            <!-- Activity Feed -->


            <!-- Revenue Breakdown -->


            <!-- Mini Booking Calendar -->
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

        </div><!-- /bottom-grid -->
    </section>

    <!-- ══ BOOKINGS ══ -->
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

    <!-- ══ GUESTS ══ -->
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

</div><!-- /main-wrap -->

<!-- ══ BOOKING INFO MODAL ══ -->
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

<!-- ══ GUEST PROFILE MODAL ══ -->
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

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
/* ── Section nav ── */
function showSection(name,el){
    document.querySelectorAll('.dash-section').forEach(s=>s.classList.remove('active'));
    document.querySelectorAll('.sb-item').forEach(n=>n.classList.remove('active'));
    document.getElementById('section-'+name).classList.add('active');
    if(el) el.classList.add('active');
}
function toggleSidebar(){ document.getElementById('sidebar').classList.toggle('open'); }

/* ── Charts ── */
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

/* ── Mini Calendar ── */
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

/* ── Date filter ── */
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

/* ── Booking filters ── */
let currentStatus='all', currentSearch='', dfpFrom='', dfpTo='';

// ADD these new functions:
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
// Keep filterByStatus for backward compat (used in overview quick actions):
function filterByStatus(status,el){ selectFilter(status,status.charAt(0).toUpperCase()+status.slice(1),document.getElementById('fd-'+status)); }function filterBookings(){ currentSearch=document.getElementById('bookingSearch').value.trim().toLowerCase(); applyFilters(); }
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

/* ── Users filter ── */
function filterUsers(q){ const query=q.trim().toLowerCase(); document.querySelectorAll('.u-row').forEach(row=>{ row.style.display=!query||row.dataset.name.includes(query)?'':'none'; }); }

/* ── Global search ── */
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

/* ── Action menus ── */
/* ── Action menus (FIXED) ── */
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
}function closeUserMenu(id){ document.getElementById('um-'+id)?.classList.remove('open'); }

/* ── Booking modal ── */
function openBookingModal(name,room,checkin,checkout,nights,guests,booked){
    document.getElementById('bm-name').textContent=name; document.getElementById('bm-room').textContent=room;
    document.getElementById('bm-checkin').textContent=checkin; document.getElementById('bm-checkout').textContent=checkout;
    document.getElementById('bm-nights').textContent=nights+' night'+(nights!=1?'s':'');
    document.getElementById('bm-guests').textContent=guests+' guest'+(guests!=1?'s':'');
    document.getElementById('bm-booked').textContent=booked;
    document.getElementById('bookingModal').classList.add('open'); document.body.style.overflow='hidden';
}
function closeBookingModal(){ document.getElementById('bookingModal').classList.remove('open'); document.body.style.overflow=''; }

/* ── Guest modal ── */
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

/* ── Notifications ── */
function toggleNotif(e){ e.stopPropagation(); document.getElementById('notifPanel').classList.toggle('open'); document.getElementById('notifWrap').classList.toggle('open'); }
function closeNotif(){ document.getElementById('notifPanel').classList.remove('open'); document.getElementById('notifWrap').classList.remove('open'); }
function markAllRead(){ document.querySelectorAll('.notif-item--unread').forEach(el=>{ el.classList.remove('notif-item--unread'); el.querySelector('.ni-dot')?.remove(); }); document.querySelector('.notif-count')?.remove(); document.querySelector('.notif-unread-pill')?.remove(); document.querySelector('.notif-mark-all')?.remove(); }

/* ── Global click handler ── */
document.addEventListener('click',e=>{
    if(!document.getElementById('searchWrap').contains(e.target)) document.getElementById('searchDropdown').classList.remove('open');
    const nw=document.getElementById('notifWrap'); if(nw&&!nw.contains(e.target)) closeNotif();
    if(adminPicker&&adminPicker.isOpen){ const dfw=document.querySelector('.date-filter-wrap'); if(dfw&&!dfw.contains(e.target)) adminPicker.close(); }
    const fw=document.getElementById('filterToggleWrap'); if(fw&&!fw.contains(e.target)){document.getElementById('filterToggleBtn').classList.remove('open');document.getElementById('filterDropdown').classList.remove('open');}  // 👈 ADD HERE
    document.querySelectorAll('.action-menu.open').forEach(m=>m.classList.remove('open'));
});
/* ── Keyboard ── */
document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ document.getElementById('searchDropdown').classList.remove('open'); document.getElementById('globalSearch').value=''; closeBookingModal(); closeGuestModal(); if(adminPicker) adminPicker.close(); } });

/* ── Auto-hide alerts ── */
const alertEl=document.getElementById('dashAlert');
if(alertEl){ setTimeout(()=>{ alertEl.style.transition='opacity 0.5s'; alertEl.style.opacity='0'; setTimeout(()=>alertEl.remove(),500); },4000); }
</script>
</body>
</html>