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
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
    --navy:#0a1628;--navy2:#142240;--navy3:#1e3360;--navy4:#243d75;
    --gold:#c9a84c;--gold-lt:#e4c97e;--gold-dk:#a07830;
    --gold-a:rgba(201,168,76,.13);--gold-b:rgba(201,168,76,.28);
    --off:#f9f7f3;--off2:#f0ece4;--off3:#e2dbd0;
    --muted:#8a8070;--text:#1a1a2e;--white:#ffffff;
    --green:#059669;--green-a:rgba(5,150,105,.12);
    --amber:#d97706;--amber-a:rgba(217,119,6,.12);
    --slate:#64748b;--slate-a:rgba(100,116,139,.12);
    --rose:#be123c;--rose-a:rgba(190,18,60,.1);
    --nav-w:260px;--ease:cubic-bezier(.4,0,.2,1);
    --serif:'Cormorant Garamond',Georgia,serif;
    --sans:'DM Sans',system-ui,sans-serif;
}
html{scroll-behavior:smooth;}
body{font-family:var(--sans);background:var(--off);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
button{font-family:var(--sans);cursor:pointer;border:none;background:none;}

/* ══ SIDEBAR ══ */
.sidebar{
    position:fixed;top:0;left:0;bottom:0;width:var(--nav-w);
    background:var(--navy);display:flex;flex-direction:column;
    overflow-y:auto;scrollbar-width:none;z-index:200;
    border-right:1px solid rgba(201,168,76,.1);
    transition:transform .38s var(--ease);
}
.sidebar::-webkit-scrollbar{display:none;}

/* Profile block */
.sb-profile{
    padding:28px 18px 20px;
    border-bottom:1px solid rgba(255,255,255,.06);
    display:flex;flex-direction:column;align-items:center;text-align:center;flex-shrink:0;
}
.sb-avatar{
    width:72px;height:72px;border-radius:50%;
    background:linear-gradient(135deg,#4a6fa5,#2e4d8a);
    display:flex;align-items:center;justify-content:center;
    font-family:var(--serif);font-size:30px;font-weight:700;color:#fff;
    border:2px solid rgba(201,168,76,.35);
    box-shadow:0 0 0 4px rgba(201,168,76,.1);
    margin-bottom:12px;cursor:default;
}
.sb-uname{font-size:14px;font-weight:600;color:#fff;margin-bottom:6px;}
.sb-edit-link{
    display:inline-flex;align-items:center;gap:5px;
    font-size:12px;color:rgba(255,255,255,.38);
    transition:color .18s;cursor:pointer;background:none;border:none;
    font-family:var(--sans);
}
.sb-edit-link:hover{color:var(--gold);}
.sb-edit-link i{font-size:11px;}

/* Nav */
.sb-nav{padding:10px 10px 6px;flex:1;}
.sb-section{font-size:9px;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.2);font-weight:600;padding:12px 10px 4px;}
.sb-link{
    display:flex;align-items:center;gap:10px;
    padding:9px 12px;border-radius:9px;
    color:rgba(255,255,255,.4);font-size:13px;font-weight:500;
    transition:all .18s;margin-bottom:2px;cursor:pointer;
    background:none;border:none;width:100%;text-align:left;font-family:var(--sans);
}
.sb-link:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.85);}
.sb-link i{width:16px;text-align:center;font-size:13px;flex-shrink:0;}  
.sb-cnt{
    margin-left:auto;min-width:19px;height:19px;padding:0 5px;border-radius:9px;
    background:var(--gold);color:var(--navy);font-size:9px;font-weight:700;
    display:flex;align-items:center;justify-content:center;
}
.sb-cnt.dim{background:rgba(255,255,255,.12);color:rgba(255,255,255,.5);}
.sb-hr{height:1px;background:rgba(255,255,255,.05);margin:8px 10px;}
.sb-bottom{padding:10px;border-top:1px solid rgba(255,255,255,.06);flex-shrink:0;}
.sb-signout{
    display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:9px;
    color:rgba(255,255,255,.28);font-size:13px;font-weight:500;transition:all .18s;
}
.sb-signout:hover{background:rgba(201,168,76,.1);color:var(--gold);}

/* Mobile */
.sb-toggle{
    display:none;position:fixed;top:14px;left:14px;z-index:250;
    width:38px;height:38px;border-radius:50%;background:var(--navy);color:#fff;
    font-size:15px;align-items:center;justify-content:center;
    box-shadow:0 4px 16px rgba(0,0,0,.3);border:none;cursor:pointer;
}
.sb-mask{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:190;backdrop-filter:blur(4px);}

/* ══ MAIN ══ */
.main{margin-left:var(--nav-w);min-height:100vh;padding:0 0 80px;}

/* ── Views: show/hide ── */
.view{display:none;}
.view.active{display:block;}

/* ══ HERO BANNER ══ */
.hero-banner{background:var(--navy);padding:40px 44px 36px;position:relative;overflow:hidden;}
.hero-banner::before{content:'';position:absolute;top:-80px;right:-80px;width:280px;height:280px;border-radius:50%;background:rgba(201,168,76,.07);pointer-events:none;}
.hero-banner::after{content:'';position:absolute;bottom:-50px;right:200px;width:150px;height:150px;border-radius:50%;background:rgba(201,168,76,.04);pointer-events:none;}
.hero-banner-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(201,168,76,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(201,168,76,.04) 1px,transparent 1px);background-size:40px 40px;pointer-events:none;}
.hero-inner{display:flex;align-items:center;gap:28px;position:relative;z-index:1;}
.hero-avatar{width:78px;height:78px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--gold) 0%,var(--gold-dk) 100%);display:flex;align-items:center;justify-content:center;font-family:var(--serif);font-size:34px;font-weight:700;color:var(--navy);border:3px solid rgba(201,168,76,.4);box-shadow:0 0 0 6px rgba(201,168,76,.1),0 8px 28px rgba(0,0,0,.3);}
.hero-info{flex:1;}
.hero-greet{font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:var(--gold);font-weight:600;margin-bottom:5px;}
.hero-name{font-family:var(--serif);font-size:clamp(24px,3vw,36px);font-weight:400;color:#fff;line-height:1.1;}
.hero-name em{font-style:italic;color:var(--gold-lt);}
.hero-sub{font-size:13px;color:rgba(255,255,255,.35);margin-top:5px;}
.hero-tags{display:flex;align-items:center;gap:10px;margin-top:14px;flex-wrap:wrap;}
.hero-tag{display:inline-flex;align-items:center;gap:5px;padding:4px 13px;border-radius:100px;font-size:11px;font-weight:500;background:rgba(255,255,255,.07);color:rgba(255,255,255,.42);border:1px solid rgba(255,255,255,.08);}
.hero-tag.gold{background:var(--gold-a);color:var(--gold);border-color:var(--gold-b);}
.hero-tag i{font-size:9px;}
.hero-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;}
.btn-primary{display:inline-flex;align-items:center;gap:7px;background:var(--gold);color:var(--navy);padding:11px 22px;border-radius:100px;font-size:13px;font-weight:700;letter-spacing:.05em;transition:all .22s;white-space:nowrap;border:none;cursor:pointer;}
.btn-primary:hover{background:var(--gold-lt);transform:translateY(-1px);box-shadow:0 6px 20px rgba(201,168,76,.35);}
.btn-ghost{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.75);padding:11px 22px;border-radius:100px;font-size:13px;font-weight:600;transition:all .22s;white-space:nowrap;border:1px solid rgba(255,255,255,.1);cursor:pointer;}
.btn-ghost:hover{background:rgba(255,255,255,.14);color:#fff;}

/* ══ CONTENT ══ */
.content{padding:32px 44px;}

/* Alert */
.alert{display:flex;align-items:center;gap:11px;padding:13px 18px;border-radius:14px;font-size:13.5px;font-weight:500;margin-bottom:24px;animation:slideDown .3s ease;}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:translateY(0);}}
.alert.success{background:var(--green-a);color:#065f46;border:1px solid rgba(5,150,105,.2);}
.alert.error{background:var(--rose-a);color:var(--rose);border:1px solid rgba(190,18,60,.18);}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:32px;}
.stat-card{background:var(--white);border-radius:18px;padding:20px 22px;border:1px solid var(--off3);position:relative;overflow:hidden;transition:transform .22s,box-shadow .22s;}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(26,26,46,.1);}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:2px 2px 0 0;}
.stat-card.c-all::before{background:var(--navy);}
.stat-card.c-pend::before{background:var(--amber);}
.stat-card.c-conf::before{background:var(--green);}
.stat-card.c-canc::before{background:var(--slate);}
.stat-ic{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:14px;}
.stat-card.c-all .stat-ic{background:rgba(26,26,46,.07);color:var(--navy);}
.stat-card.c-pend .stat-ic{background:var(--amber-a);color:var(--amber);}
.stat-card.c-conf .stat-ic{background:var(--green-a);color:var(--green);}
.stat-card.c-canc .stat-ic{background:var(--slate-a);color:var(--slate);}
.stat-num{font-family:var(--serif);font-size:36px;font-weight:600;color:var(--navy);line-height:1;}
.stat-lbl{font-size:11.5px;color:var(--muted);font-weight:500;margin-top:4px;}

/* Section head */
.sec-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px;flex-wrap:wrap;}
.sec-title{font-family:var(--serif);font-size:24px;font-weight:600;color:var(--navy);}
.sec-sub{font-size:12.5px;color:var(--muted);}

/* Tab strip */
.tab-strip{display:flex;gap:4px;padding:4px;background:var(--white);border:1px solid var(--off3);border-radius:100px;width:fit-content;margin-bottom:20px;flex-wrap:wrap;}
.tab-pill{display:flex;align-items:center;gap:6px;padding:8px 16px;border-radius:100px;font-size:12.5px;font-weight:500;color:var(--muted);transition:all .18s;white-space:nowrap;}
.tab-pill:hover{color:var(--navy);}
.tab-pill.on{background:var(--navy);color:#fff;font-weight:600;box-shadow:0 3px 12px rgba(26,26,46,.22);}
.tab-n{display:inline-flex;align-items:center;justify-content:center;min-width:17px;height:17px;padding:0 4px;border-radius:9px;font-size:9.5px;font-weight:700;background:rgba(0,0,0,.08);}
.tab-pill.on .tab-n{background:rgba(255,255,255,.18);}

/* Booking cards */
.booking-stack{display:flex;flex-direction:column;gap:10px;}
.bk-card{background:var(--white);border-radius:18px;border:1px solid var(--off3);overflow:hidden;transition:box-shadow .22s;animation:fadeUp .35s ease both;}
.bk-card:hover{box-shadow:0 4px 20px rgba(26,26,46,.08);}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
<?php for($i=1;$i<=30;$i++): ?>.bk-card:nth-child(<?=$i?>){animation-delay:<?=($i-1)*.045?>s;}
<?php endfor; ?>
.bk-row{display:flex;align-items:center;gap:14px;padding:16px 22px;cursor:pointer;user-select:none;transition:background .15s;}
.bk-row:hover{background:rgba(26,26,46,.015);}
.bk-bar{width:4px;height:44px;border-radius:4px;flex-shrink:0;}
.bk-bar.pending{background:var(--amber);}.bk-bar.confirmed{background:var(--green);}
.bk-bar.cancelled{background:var(--off3);}.bk-bar.rejected{background:var(--rose);}
.bk-main{flex:1;min-width:0;}
.bk-ref{font-size:10px;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;font-weight:600;margin-bottom:2px;}
.bk-room{font-family:var(--serif);font-size:17px;font-weight:600;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2;}
.bk-dates-chip{display:flex;align-items:center;gap:6px;font-size:12.5px;color:var(--muted);white-space:nowrap;flex-shrink:0;}
.bk-dates-chip i{font-size:10px;color:var(--gold);}
.bk-right{display:flex;align-items:center;gap:10px;flex-shrink:0;}
.s-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:100px;font-size:11px;font-weight:700;letter-spacing:.05em;white-space:nowrap;}
.s-pill::before{content:'';width:6px;height:6px;border-radius:50%;flex-shrink:0;}
.s-pending{background:var(--amber-a);color:#92400e;}.s-pending::before{background:var(--amber);}
.s-confirmed{background:var(--green-a);color:#065f46;}.s-confirmed::before{background:var(--green);}
.s-cancelled{background:var(--slate-a);color:#334155;}.s-cancelled::before{background:var(--slate);}
.s-rejected{background:var(--rose-a);color:var(--rose);}.s-rejected::before{background:var(--rose);}
.bk-chevron{width:27px;height:27px;border-radius:50%;border:1px solid var(--off3);display:flex;align-items:center;justify-content:center;font-size:10px;color:var(--muted);transition:all .28s var(--ease);flex-shrink:0;}
.bk-card.open .bk-chevron{transform:rotate(180deg);background:var(--navy);color:#fff;border-color:var(--navy);}
.bk-panel{max-height:0;overflow:hidden;transition:max-height .38s var(--ease);}
.bk-card.open .bk-panel{max-height:400px;border-top:1px solid var(--off2);}
.bk-detail{padding:20px 22px 22px;}
.bk-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:18px;}
.bk-field-lbl{font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:600;margin-bottom:5px;display:flex;align-items:center;gap:5px;}
.bk-field-lbl i{color:var(--gold);font-size:10px;}
.bk-field-val{font-size:14px;font-weight:600;color:var(--navy);}
.bk-field-sub{font-size:11.5px;color:var(--muted);margin-top:2px;}
.bk-foot{display:flex;align-items:center;justify-content:space-between;padding-top:15px;border-top:1px solid var(--off2);gap:12px;flex-wrap:wrap;}
.bk-timestamp{font-size:11.5px;color:var(--muted);display:flex;align-items:center;gap:5px;}
.btn-cancel{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:100px;border:1.5px solid rgba(190,18,60,.35);color:var(--rose);font-size:12px;font-weight:600;transition:all .18s;background:transparent;cursor:pointer;font-family:var(--sans);}
.btn-cancel:hover{background:var(--rose);color:#fff;border-color:var(--rose);}
.confirmed-note{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--green);font-weight:500;background:var(--green-a);border:1px solid rgba(5,150,105,.18);padding:6px 13px;border-radius:100px;}
.confirmed-note i{font-size:10px;}
.empty-wrap{text-align:center;padding:72px 40px;background:var(--white);border-radius:22px;border:1px solid var(--off3);}
.empty-ic{width:68px;height:68px;border-radius:50%;background:var(--off2);display:flex;align-items:center;justify-content:center;font-size:26px;color:var(--muted);margin:0 auto 20px;}
.empty-title{font-family:var(--serif);font-size:22px;font-weight:600;color:var(--navy);margin-bottom:8px;}
.empty-sub{font-size:13.5px;color:var(--muted);margin-bottom:22px;}

/* ══ VIEW HEADER (edit / notifications) ══ */
.view-header{
    background:var(--navy);padding:36px 44px 30px;
    position:relative;overflow:hidden;
}
.view-header::before{content:'';position:absolute;top:-60px;right:-60px;width:220px;height:220px;border-radius:50%;background:rgba(201,168,76,.07);pointer-events:none;}
.view-header-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(201,168,76,.04) 1px,transparent 1px),linear-gradient(90deg,rgba(201,168,76,.04) 1px,transparent 1px);background-size:40px 40px;pointer-events:none;}
.view-header-inner{display:flex;align-items:center;gap:16px;position:relative;z-index:1;}
.view-back{display:inline-flex;align-items:center;gap:7px;color:rgba(255,255,255,.4);font-size:12.5px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);padding:7px 14px;border-radius:100px;transition:all .2s;cursor:pointer;font-family:var(--sans);}
.view-back:hover{background:rgba(255,255,255,.13);color:#fff;}
.view-back i{font-size:11px;}
.view-eyebrow{font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:var(--gold);font-weight:600;margin-bottom:5px;}
.view-title{font-family:var(--serif);font-size:28px;font-weight:400;color:#fff;line-height:1.15;}
.view-title em{font-style:italic;color:var(--gold-lt);}
.view-sub{font-size:12.5px;color:rgba(255,255,255,.35);margin-top:5px;}

/* ══ EDIT PROFILE CARDS ══ */
.ep-content{padding:32px 44px;max-width:860px;}
.card{background:var(--white);border-radius:22px;border:1px solid var(--off3);overflow:hidden;margin-bottom:20px;transition:box-shadow .22s;}
.card:hover{box-shadow:0 4px 24px rgba(26,26,46,.07);}
.card-hd{display:flex;align-items:center;gap:14px;padding:22px 28px 20px;border-bottom:1px solid var(--off2);}
.card-hd-icon{width:42px;height:42px;border-radius:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:17px;}
.card-hd-icon.blue{background:rgba(59,130,246,.1);color:#3b82f6;}
.card-hd-icon.rose{background:var(--rose-a);color:var(--rose);}
.card-hd-icon.gold{background:var(--gold-a);color:var(--gold);}
.card-hd-title{font-family:var(--serif);font-size:20px;font-weight:600;color:var(--navy);line-height:1.1;}
.card-hd-sub{font-size:12px;color:var(--muted);margin-top:2px;}
.card-body{padding:26px 28px 28px;}
.avatar-row{display:flex;align-items:center;gap:20px;padding:18px 22px;border-radius:16px;background:var(--off);border:1px solid var(--off3);margin-bottom:24px;}
.edit-avatar{width:72px;height:72px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#4a6fa5,#2e4d8a);display:flex;align-items:center;justify-content:center;font-family:var(--serif);font-size:30px;font-weight:700;color:#fff;border:2px solid rgba(201,168,76,.35);position:relative;}
.avatar-badge{position:absolute;bottom:-2px;right:-2px;width:22px;height:22px;border-radius:50%;background:var(--gold);color:var(--navy);font-size:9px;border:2px solid var(--off);display:flex;align-items:center;justify-content:center;}
.avatar-name{font-size:16px;font-weight:600;color:var(--navy);margin-bottom:2px;}
.avatar-email{font-size:12.5px;color:var(--muted);}
.avatar-since{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--muted);background:var(--off2);border:1px solid var(--off3);padding:3px 10px;border-radius:100px;margin-top:7px;}
.avatar-since i{color:var(--gold);font-size:10px;}
.field-group{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px;}
.field-group.single{grid-template-columns:1fr;}
.field{display:flex;flex-direction:column;gap:6px;}
.field label{font-size:11.5px;font-weight:600;color:var(--navy);letter-spacing:.04em;text-transform:uppercase;display:flex;align-items:center;gap:6px;}
.field label i{color:var(--gold);font-size:11px;}
.field input{width:100%;padding:12px 16px;background:var(--off);border:1.5px solid var(--off3);border-radius:12px;font-family:var(--sans);font-size:14px;color:var(--navy);outline:none;transition:border-color .2s,box-shadow .2s;}
.field input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12);background:var(--white);}
.field input::placeholder{color:rgba(26,26,46,.25);}
.field input[readonly]{background:var(--off2);color:var(--muted);cursor:not-allowed;border-color:var(--off3);}
.field-hint{font-size:11.5px;color:var(--muted);margin-top:2px;display:flex;align-items:center;gap:5px;}
.field-hint i{font-size:10px;color:var(--gold);}
.pw-wrap{position:relative;}
.pw-wrap input{padding-right:44px;}
.pw-eye{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);font-size:14px;padding:4px;transition:color .18s;}
.pw-eye:hover{color:var(--navy);}
.pw-strength{margin-top:8px;}
.pw-strength-bar{height:4px;border-radius:4px;background:var(--off3);overflow:hidden;margin-bottom:4px;}
.pw-strength-fill{height:100%;border-radius:4px;width:0;transition:width .3s,background .3s;}
.pw-strength-label{font-size:11px;color:var(--muted);}
.form-divider{display:flex;align-items:center;gap:12px;margin:22px 0;color:var(--muted);font-size:12px;}
.form-divider::before,.form-divider::after{content:'';flex:1;height:1px;background:var(--off3);}
.submit-row{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding-top:4px;}
.btn-save{display:inline-flex;align-items:center;gap:8px;background:var(--navy);color:#fff;padding:12px 28px;border-radius:100px;font-size:13px;font-weight:600;letter-spacing:.04em;transition:all .22s;border:none;cursor:pointer;font-family:var(--sans);}
.btn-save:hover{background:var(--navy3);transform:translateY(-1px);box-shadow:0 6px 20px rgba(10,22,40,.25);}
.btn-save.gold-btn{background:var(--gold);color:var(--navy);}
.btn-save.gold-btn:hover{background:var(--gold-lt);box-shadow:0 6px 20px rgba(201,168,76,.35);}
.btn-discard{display:inline-flex;align-items:center;gap:8px;background:transparent;color:var(--muted);padding:12px 20px;border-radius:100px;font-size:13px;font-weight:500;transition:all .18s;border:1.5px solid var(--off3);cursor:pointer;font-family:var(--sans);}
.btn-discard:hover{background:var(--off);color:var(--navy);}
.tip-list{display:flex;flex-direction:column;gap:10px;margin-top:4px;}
.tip{display:flex;align-items:flex-start;gap:11px;padding:12px 16px;border-radius:12px;background:var(--off);border:1px solid var(--off3);font-size:12.5px;color:var(--muted);line-height:1.5;}
.tip-icon{width:28px;height:28px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;margin-top:1px;}
.tip-icon.g{background:var(--green-a);color:var(--green);}
.tip-icon.a{background:var(--amber-a);color:var(--amber);}
.tip-icon.b{background:rgba(59,130,246,.1);color:#3b82f6;}
.tip strong{color:var(--navy);font-weight:600;}

/* ══ NOTIFICATIONS VIEW ══ */
.notif-content{padding:32px 44px;max-width:720px;}
.notif-list{display:flex;flex-direction:column;gap:10px;}
.notif-item{
    display:flex;gap:14px;padding:18px 22px;
    background:var(--white);border-radius:18px;
    border:1px solid var(--off3);
    transition:box-shadow .2s,transform .2s;
    animation:fadeUp .3s ease both;
}
.notif-item:hover{box-shadow:0 4px 20px rgba(26,26,46,.08);transform:translateY(-1px);}
.notif-item.unread{border-left:3px solid var(--gold);}
<?php for($i=1;$i<=10;$i++): ?>.notif-item:nth-child(<?=$i?>){animation-delay:<?=($i-1)*.06?>s;}
<?php endfor; ?>
.notif-icon{width:44px;height:44px;border-radius:14px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:16px;}
.notif-icon.confirmed{background:var(--green-a);color:var(--green);}
.notif-icon.pending{background:var(--amber-a);color:var(--amber);}
.notif-icon.promo{background:var(--gold-a);color:var(--gold);}
.notif-icon.system{background:rgba(59,130,246,.1);color:#3b82f6;}
.notif-body{flex:1;min-width:0;}
.notif-text{font-size:13.5px;color:var(--text);line-height:1.5;margin-bottom:5px;}
.notif-text strong{font-weight:600;color:var(--navy);}
.notif-meta{display:flex;align-items:center;gap:8px;font-size:11px;color:var(--muted);}
.notif-unread-dot{width:7px;height:7px;border-radius:50%;background:var(--gold);flex-shrink:0;}
.notif-empty{text-align:center;padding:60px 20px;color:var(--muted);}
.notif-empty i{font-size:40px;color:var(--off3);display:block;margin-bottom:16px;}
.notif-filter{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.nf-btn{padding:7px 16px;border-radius:100px;font-size:12px;font-weight:500;border:1.5px solid var(--off3);background:var(--white);color:var(--muted);cursor:pointer;transition:all .18s;font-family:var(--sans);}
.nf-btn:hover{border-color:var(--navy);color:var(--navy);}
.nf-btn.on{background:var(--navy);color:#fff;border-color:var(--navy);}

/* ══ CANCEL MODAL ══ */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(10,10,22,.6);backdrop-filter:blur(8px);z-index:1000;align-items:center;justify-content:center;padding:20px;}
.modal-bg.show{display:flex;}
.modal-wrap{background:var(--white);border-radius:24px;width:100%;max-width:430px;box-shadow:0 24px 80px rgba(0,0,0,.22);overflow:hidden;animation:popIn .3s cubic-bezier(.34,1.56,.64,1);}
@keyframes popIn{from{opacity:0;transform:scale(.9) translateY(12px);}to{opacity:1;transform:scale(1) translateY(0);}}
.modal-top{background:var(--navy);padding:22px 26px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(201,168,76,.12);}
.modal-top h3{font-family:var(--serif);font-size:20px;font-weight:600;color:#fff;}
.modal-close{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.1);color:rgba(255,255,255,.55);font-size:13px;display:flex;align-items:center;justify-content:center;transition:all .18s;cursor:pointer;}
.modal-close:hover{background:rgba(255,255,255,.2);color:#fff;}
.modal-body{padding:26px;}
.m-preview{background:var(--off);border-radius:14px;padding:14px 18px;margin-bottom:18px;border:1px solid var(--off3);}
.m-preview .room{font-family:var(--serif);font-size:16px;font-weight:600;color:var(--navy);margin-bottom:5px;}
.m-preview .dates{font-size:12.5px;color:var(--muted);display:flex;align-items:center;gap:6px;}
.m-warning{display:flex;gap:10px;background:var(--rose-a);border:1px solid rgba(190,18,60,.18);border-radius:11px;padding:12px 14px;font-size:12.5px;color:var(--rose);margin-bottom:22px;line-height:1.5;}
.m-warning i{flex-shrink:0;margin-top:2px;}
.modal-acts{display:flex;gap:10px;}
.btn-keep{flex:1;padding:12px;border-radius:100px;border:1.5px solid var(--off3);background:transparent;color:var(--navy);font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;font-family:var(--sans);}
.btn-keep:hover{background:var(--off);}
.btn-yes-cancel{flex:1;padding:12px;border-radius:100px;background:var(--rose);color:#fff;font-size:13px;font-weight:600;cursor:pointer;transition:all .18s;font-family:var(--sans);border:none;display:flex;align-items:center;justify-content:center;gap:7px;}
.btn-yes-cancel:hover{background:#9f1239;}

/* ══ RESPONSIVE ══ */
@media(max-width:1100px){.stats-row{grid-template-columns:repeat(2,1fr);}.bk-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:768px){
    :root{--nav-w:0px;}
    .sb-toggle{display:flex;}
    .sidebar{transform:translateX(-260px);width:260px;}
    .sidebar.open{transform:translateX(0);}
    .sb-mask.show{display:block;}
    .main{margin-left:0;}
    .hero-banner,.view-header{padding:28px 20px 24px;}
    .hero-inner{flex-direction:column;align-items:flex-start;gap:16px;}
    .hero-actions{flex-direction:row;width:100%;}
    .content,.ep-content,.notif-content{padding:20px 16px;}
    .stats-row{grid-template-columns:repeat(2,1fr);gap:10px;}
    .bk-dates-chip{display:none;}
    .bk-grid{grid-template-columns:repeat(2,1fr);}
    .field-group{grid-template-columns:1fr;}
    .card-hd,.card-body{padding:18px 20px;}
}
@media(max-width:480px){
    .stats-row{grid-template-columns:repeat(2,1fr);}
    .bk-foot{flex-direction:column;align-items:flex-start;}
    .hero-actions{flex-direction:column;}
    .btn-primary,.btn-ghost{justify-content:center;}
    .submit-row{flex-direction:column-reverse;align-items:stretch;}
    .btn-save,.btn-discard{justify-content:center;}
}
</style>
</head>
<body>

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