<?php
session_start();
require "../config/conn.php";
require "../config/security.php";
require "../config/mailer.php";

$error = "";
$show_otp_modal = false;

// ── REMEMBERED DEVICE CHECK (helper, used on login) ──
function checkRememberedDevice($conn, $admin_id) {
    if (empty($_COOKIE['admin_remember'])) return false;
    $parts = explode(':', $_COOKIE['admin_remember']);
    if (count($parts) !== 2) return false;
    [$selector, $validator] = $parts;

    $stmt = $conn->prepare("SELECT id, admin_id, validator_hash, expires_at FROM remember_tokens WHERE selector = ?");
    $stmt->bind_param("s", $selector);
    $stmt->execute();
    $stmt->bind_result($rid, $r_admin_id, $validator_hash, $expires_at);
    if (!$stmt->fetch()) { $stmt->close(); return false; }
    $stmt->close();

    if ($r_admin_id != $admin_id) return false;
    if (strtotime($expires_at) < time()) return false;
    if (!hash_equals($validator_hash, hash('sha256', $validator))) return false;

    return true;
}

// ── LOGIN HISTORY LOGGING ──
function logLoginEvent($conn, $admin_id, $username, $method) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $stmt = $conn->prepare("INSERT INTO login_history (admin_id, username, ip_address, user_agent, login_method) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $admin_id, $username, $ip, $ua, $method);
    $stmt->execute();
    $stmt->close();
}

function setRememberCookie($conn, $admin_id) {
    $selector  = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(33));
    $hash      = hash('sha256', $validator);
    $expires   = date('Y-m-d H:i:s', strtotime('+30 days'));

    $del = $conn->prepare("DELETE FROM remember_tokens WHERE admin_id = ?");
    $del->bind_param("i", $admin_id); $del->execute(); $del->close();

    $ins = $conn->prepare("INSERT INTO remember_tokens (admin_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)");
    $ins->bind_param("isss", $admin_id, $selector, $hash, $expires);
    $ins->execute(); $ins->close();

    setcookie('admin_remember', $selector . ':' . $validator, [
        'expires'  => strtotime('+30 days'),
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ── LOGIN ATTEMPT LOCKOUT ──
function getLockoutSeconds($conn, $email) { 
    $stmt = $conn->prepare("SELECT locked_until FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email); $stmt->execute();
    $stmt->bind_result($locked_until);
    if (!$stmt->fetch()) { $stmt->close(); return 0; }
    $stmt->close();
    if (empty($locked_until)) return 0;
    $remaining = strtotime($locked_until) - time();
    return $remaining > 0 ? $remaining : 0;
}

function recordFailedAttempt($conn, $email) {
    $stmt = $conn->prepare("SELECT attempt_count FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email); $stmt->execute();
    $stmt->bind_result($count);
    $exists = $stmt->fetch();
    $stmt->close();

    $count = $exists ? $count + 1 : 1;

    if ($count >= 5) {
        $locked_until = date('Y-m-d H:i:s', strtotime('+3 minutes'));
        if ($exists) {
            $u = $conn->prepare("UPDATE login_attempts SET attempt_count = 0, locked_until = ? WHERE email = ?");
            $u->bind_param("ss", $locked_until, $email); $u->execute(); $u->close();
        } else {
            $i = $conn->prepare("INSERT INTO login_attempts (email, attempt_count, locked_until) VALUES (?, 0, ?)");
            $i->bind_param("ss", $email, $locked_until); $i->execute(); $i->close();
        }
        return ['locked' => true, 'seconds' => 180, 'attempts_left' => 0];
    }

    if ($exists) {
        $u = $conn->prepare("UPDATE login_attempts SET attempt_count = ? WHERE email = ?");
        $u->bind_param("is", $count, $email); $u->execute(); $u->close();
    } else {
        $i = $conn->prepare("INSERT INTO login_attempts (email, attempt_count) VALUES (?, ?)");
        $i->bind_param("si", $email, $count); $i->execute(); $i->close();
    }
    return ['locked' => false, 'seconds' => 0, 'attempts_left' => 5 - $count];
}

function clearLoginAttempts($conn, $email) {
    $d = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
    $d->bind_param("s", $email); $d->execute(); $d->close();
}

// ── LOGIN ──
$field_errors = ['username' => '', 'password' => ''];
$lockout_seconds = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = sanitize($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username)) {
        $field_errors['username'] = "Please enter your username.";
    } elseif (empty($password)) {
        $field_errors['password'] = "Please enter your password.";
    } else {
        // Rate-limit check happens BEFORE we even touch the database for
        // credentials — blocks repeated guesses against the same username
        // regardless of whether that username exists.
        $lockout_seconds = getLockoutSeconds($conn, $username);
        if ($lockout_seconds > 0) {
            $mins = ceil($lockout_seconds / 60);
            $error = "Too many failed attempts. Please try again in {$mins} minute" . ($mins != 1 ? 's' : '') . ".";
        } else {
            $stmt = $conn->prepare("SELECT admin_id, full_name, email, password FROM admins WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 1) {
                $stmt->bind_result($id, $full_name, $email, $hashed_password);
                $stmt->fetch();
                if (password_verify($password, $hashed_password)) {
                    clearLoginAttempts($conn, $username);

                    // Trusted device on file — skip OTP entirely.
                    if (checkRememberedDevice($conn, $id)) {
                        session_regenerate_id(true);
                        $_SESSION['admin_id']   = $id;
                        $_SESSION['admin_name'] = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
                        $_SESSION['admin_role'] = 'admin';
                        logLoginEvent($conn, $id, $username, 'remembered');
                        header("Location: admin_dashboard.php");
                        exit();
                    }

                    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                    $del = $conn->prepare("DELETE FROM otp_codes WHERE email = ?");
                    $del->bind_param("s", $email); $del->execute(); $del->close();
                    $ins = $conn->prepare("INSERT INTO otp_codes (email, otp, expires_at) VALUES (?, ?, ?)");
                    $ins->bind_param("sss", $email, $otp, $otp_expires); $ins->execute(); $ins->close();

                    $_SESSION['temp_admin_id']   = $id;
                    $_SESSION['temp_admin_name'] = $full_name;
                    $_SESSION['temp_admin_email']= $email;
                    $_SESSION['temp_admin_username'] = $username;
                    $_SESSION['temp_remember']   = !empty($_POST['remember_device']);

                    $subject  = "CoraVergel Resort — Admin Login Code";
                    $bodyHtml = "<p>Hi {$full_name},</p>"
                              . "<p>Your one-time admin verification code is:</p>"
                              . "<h2 style='letter-spacing:4px;'>{$otp}</h2>"
                              . "<p>This code expires in 10 minutes.</p>"
                              . "<p>If you did not attempt to log in, please ignore this email.</p>"
                              . "<p>— CoraVergel Resort</p>";

                    // Admin OTPs always go to the resort's dedicated admin inbox,
                    // regardless of what email address is stored on the account.
                    sendMail('lexnnder15@gmail.com', $full_name, $subject, $bodyHtml);
                    $show_otp_modal = true;
                } else {
                    $result = recordFailedAttempt($conn, $username);
                    if ($result['locked']) {
                        $error = "Too many failed attempts. Your account is locked for 3 minutes.";
                        $lockout_seconds = $result['seconds'];
                    } else {
                        $field_errors['password'] = "Incorrect password.";
                        $error = $result['attempts_left'] . " attempt" . ($result['attempts_left'] != 1 ? 's' : '') . " remaining before temporary lockout.";
                    }
                }
            } else {
                // Rate-limit non-existent usernames too, and use a field-level
                // message rather than tipping off which part was wrong.
                $result = recordFailedAttempt($conn, $username);
                if ($result['locked']) {
                    $error = "Too many failed attempts. Your account is locked for 3 minutes.";
                    $lockout_seconds = $result['seconds'];
                } else {
                    $field_errors['username'] = "No admin account found with that username.";
                }
            }
            $stmt->close();
        }
    }
}

// ── VERIFY OTP ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    if (!isset($_SESSION['temp_admin_email'])) {
        $error = "Session expired. Please login again.";
    } else {
        $otp_input = trim($_POST['otp']);
        $email     = $_SESSION['temp_admin_email'];
        $stmt = $conn->prepare("SELECT otp, expires_at FROM otp_codes WHERE email = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("s", $email); $stmt->execute();
        $stmt->bind_result($db_otp, $db_expires); $stmt->fetch(); $stmt->close();

        if (empty($db_otp)) {
            $error = "OTP not found. Please try logging in again.";
        } elseif (strtotime($db_expires) < time()) {
            $error = "OTP has expired. Please login again.";
            unset($_SESSION['temp_admin_id'], $_SESSION['temp_admin_name'], $_SESSION['temp_admin_email']);
        } elseif ($otp_input == $db_otp) {
            $uid       = $_SESSION['temp_admin_id'];
            $full_name = $_SESSION['temp_admin_name'];
            $uname     = $_SESSION['temp_admin_username'] ?? '';

            session_regenerate_id(true);
            $_SESSION['admin_id']   = $uid;
            $_SESSION['admin_name'] = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
            $_SESSION['admin_role'] = 'admin';

            if (!empty($_SESSION['temp_remember'])) {
                setRememberCookie($conn, $uid);
            }

            logLoginEvent($conn, $uid, $uname, 'otp');

            unset($_SESSION['temp_admin_id'], $_SESSION['temp_admin_name'], $_SESSION['temp_admin_email'], $_SESSION['temp_admin_username'], $_SESSION['temp_remember']);

            $del = $conn->prepare("DELETE FROM otp_codes WHERE email = ?");
            $del->bind_param("s", $email); $del->execute(); $del->close();

            header("Location: admin_dashboard.php");
            exit();
        } else {
            $error = "Invalid OTP. Please try again.";
            $show_otp_modal = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link rel="icon" href="../assets/images/logo/cv_logo.png" sizes="any">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --navy-deep:#000000; --navy:#111111; --navy-soft:#2b2b2b;
    --gold:#333333; --gold-soft:#f2f2f2; --gold-line:rgba(255,255,255,.25);
    --ivory:#ffffff; --gray:#6b6b6b; --line:#dcdcdc; --error:#c0392b;

    --brand-bg:#0f0f1e; --brand-gold:#c9a94c; --brand-gold-soft:#e4cf9a;
    --brand-gold-line:rgba(201,169,76,.35);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
body{
    font-family:'DM Sans',sans-serif;
    display:flex; min-height:100vh; background:var(--ivory);
}

/* ── Left identity panel ── */
.brand-panel{
    position:relative; flex:0 0 42%;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    background:var(--brand-bg);
    overflow:hidden;
    padding:60px 40px;
}
.brand-panel::before{
    content:"";
    position:absolute; inset:0;
    background-image:repeating-linear-gradient(
        115deg, transparent 0 38px,
        rgba(201,169,76,.05) 38px 39px
    );
}
.brand-panel::after{
    content:"";
    position:absolute; inset:0;
    background:
        radial-gradient(circle at 30% 20%, rgba(201,169,76,.12), transparent 55%),
        radial-gradient(circle at 75% 85%, rgba(201,169,76,.08), transparent 50%);
}
.brand-content{ position:relative; text-align:center; animation:riseIn .7s ease both; }
@keyframes riseIn{ from{opacity:0; transform:translateY(10px);} to{opacity:1; transform:translateY(0);} }

.cv-logo-plain{
    width:280px; height:160px;
    object-fit:cover;
    margin:0 auto 22px;
    display:block;
}
.brand-name{
    font-family:'Cormorant Garamond',serif; font-weight:500;
    font-size:2.3rem; color:#faf8f4; letter-spacing:.01em;
    margin-bottom:14px;
}
.brand-rule{ width:36px; height:1px; background:var(--brand-gold-line); margin:0 auto 14px; }
.brand-tagline{
    font-size:.72rem; color:var(--brand-gold-soft); opacity:.75;
    letter-spacing:.22em; text-transform:uppercase;
}

/* ── Right form panel ── */
.form-panel{
    flex:1; display:flex; align-items:center; justify-content:center;
    padding:40px 24px;
    background:#fff;
}
.form-inner{ width:100%; max-width:340px; }
.form-eyebrow{
    font-size:.7rem; color:var(--gray); letter-spacing:.2em;
    text-transform:uppercase; font-weight:600; margin-bottom:10px;
}
.form-title{
    font-family:'Cormorant Garamond',serif; font-weight:500;
    font-size:2rem; color:var(--navy); margin-bottom:6px;
}
.form-sub{
    font-size:.85rem; color:var(--gray); margin-bottom:32px; line-height:1.5;
}
.al-alert{
    display:flex; align-items:center; gap:8px;
    padding:10px 14px; border-radius:6px;
    font-size:.82rem; margin-bottom:20px;
    background:#fbf0ee; border:1px solid #f0d4cf; color:var(--error);
}
.lf-group{
    position:relative;
    margin-bottom:22px;
}
.lf-label{
    display:block; font-size:.7rem; color:var(--navy); font-weight:600;
    letter-spacing:.1em; text-transform:uppercase; margin-bottom:8px;
    opacity:.65;
}
.lf-input-row{
    display:flex; align-items:center;
    border-bottom:1px solid #d8d8d8;
    transition:border-color .2s;
}
.lf-input-row:focus-within{ border-color:var(--navy); }
.lf-input-row.has-error{ border-color:var(--error); }
.lf-field-error{
    display:flex; align-items:center; gap:6px;
    font-size:.76rem; color:var(--error);
    margin-top:7px;
}
.lf-icon{
    width:26px; flex-shrink:0; color:#9a9a9a; font-size:.8rem;
    display:flex; align-items:center; justify-content:center;
}
.lf-input-row input{
    flex:1; padding:9px 6px 9px 0; border:none; background:transparent;
    font-family:'DM Sans',sans-serif; font-size:.92rem; color:var(--navy); outline:none;
}
.lf-input-row input::placeholder{ color:#c3c0ba; }
.lf-eye{
    background:none; border:none; padding:0; margin:0; cursor:pointer;
    color:#c3c0ba; display:none; align-items:center; justify-content:center;
    font-size:14px; line-height:1;
}
.lf-eye:hover{ color:var(--navy); }
.lf-remember{
    display:flex; align-items:center; gap:9px;
    font-size:.82rem; color:var(--gray); margin-bottom:26px; cursor:pointer;
    user-select:none;
}
.lf-remember input{
    appearance:none; width:18px; height:18px; flex-shrink:0;
    border:1.5px solid #cfc9ba; border-radius:5px; cursor:pointer;
    background:#fff;
    display:inline-flex; align-items:center; justify-content:center;
    transition:all .15s; position:relative;
}
.lf-remember input:checked{ background:var(--navy); border-color:var(--navy); }
.lf-remember input:checked::after{
    content:""; position:absolute;
    left:5px; top:2px;
    width:5px; height:9px;
    border-right:2px solid #fff;
    border-bottom:2px solid #fff;
    transform:rotate(45deg);
}
.lf-submit{
    width:100%; padding:14px; background:var(--navy); color:var(--ivory);
    border:none; border-radius:4px; font-family:'DM Sans',sans-serif;
    font-size:.82rem; font-weight:600; letter-spacing:.12em; text-transform:uppercase; cursor:pointer;
    display:flex; align-items:center; justify-content:center; gap:9px;
    transition:all .25s;
}
.lf-submit:hover{ background:var(--navy-soft); }
.lf-submit:disabled{
    background:#e8e5e0; color:#a8a29a; cursor:not-allowed;
    box-shadow:none;
}
.lf-submit:disabled:hover{ background:#e8e5e0; }
.al-back{
    display:flex; align-items:center; justify-content:center; gap:6px;
    text-align:center; margin-top:26px;
    font-size:.78rem; color:var(--gray); text-decoration:none;
}
.al-back:hover{ color:var(--navy); }

/* ── OTP modal ── */
.otp-overlay{
    display:none; position:fixed; inset:0; background:rgba(10,10,20,.7);
    z-index:500; align-items:center; justify-content:center; padding:20px;
    backdrop-filter:blur(2px);
}
.otp-overlay.open{ display:flex; }
.otp-box{
    background:var(--ivory); border-radius:6px; width:100%; max-width:400px;
    padding:44px 36px 34px; text-align:center;
    box-shadow:0 30px 80px rgba(0,0,0,.35);
    border-top:2px solid var(--gold);
}
.otp-icon{
    width:56px;height:56px;border-radius:50%;
    border:1px solid var(--gold-line);
    display:flex;align-items:center;justify-content:center;font-size:1.3rem;
    color:var(--gold); margin:0 auto 20px;
}
.otp-title{ font-family:'Cormorant Garamond',serif; font-weight:500; font-size:1.6rem; color:var(--navy); margin-bottom:8px; }
.otp-sub{ font-size:.84rem; color:var(--gray); margin-bottom:8px; line-height:1.5; }
.otp-email-label{ display:inline-block; font-size:.8rem; font-weight:600; color:var(--navy); margin-bottom:26px; letter-spacing:.02em; }
.otp-alert{
    display:flex; align-items:center; gap:8px; padding:10px 14px; border-radius:6px;
    background:#fbf0ee; border:1px solid #f0d4cf; color:var(--error); font-size:.82rem;
    margin-bottom:18px; text-align:left;
}
.otp-inputs{ display:flex; gap:8px; justify-content:center; margin-bottom:18px; }
.otp-digit{
    width:42px; height:52px; border-radius:6px; border:1.3px solid var(--line);
    background:#fff; text-align:center; font-family:'Cormorant Garamond',serif;
    font-size:1.5rem; font-weight:600; color:var(--navy); outline:none; transition:all .18s;
}
.otp-digit:focus{ border-color:var(--gold); box-shadow:0 0 0 3px rgba(201,169,76,.14); }
.otp-digit.filled{ border-color:var(--navy); background:#f7f5f0; }
.otp-digit.shake{ animation:shake .4s ease; }
@keyframes shake{ 0%,100%{transform:translateX(0);} 20%{transform:translateX(-5px);} 40%{transform:translateX(5px);} 60%{transform:translateX(-4px);} 80%{transform:translateX(3px);} }
.otp-timer{ font-size:.8rem; color:var(--gray); margin-bottom:22px; }
#otpCountdown{ font-weight:700; color:var(--navy); }
.otp-btn{
    width:100%; padding:14px; background:var(--navy); color:var(--ivory); border:none;
    border-radius:4px; font-family:'DM Sans',sans-serif; font-size:.82rem; font-weight:600;
    letter-spacing:.12em; text-transform:uppercase; cursor:pointer; display:flex; align-items:center; justify-content:center;
    gap:9px; transition:all .25s; margin-bottom:16px;
}
.otp-btn:hover:not(:disabled){ background:var(--gold); color:var(--navy-deep); }
.otp-btn:disabled{ opacity:.4; cursor:not-allowed; }
.otp-resend{ font-size:.8rem; color:var(--gray); margin-bottom:14px; }
#otpResendBtn{
    background:none; border:none; cursor:pointer; font-size:.8rem; font-family:'DM Sans',sans-serif;
    font-weight:600; color:var(--navy); text-decoration:underline; text-underline-offset:2px;
}
#otpResendBtn:disabled{ color:#bbb; cursor:not-allowed; text-decoration:none; }
.otp-back{
    background:none; border:none; cursor:pointer; font-size:.78rem; font-family:'DM Sans',sans-serif;
    color:var(--gray); display:inline-flex; align-items:center; gap:5px;
}
.otp-back:hover{ color:var(--navy); }

@media (max-width:820px){
    body{ flex-direction:column; }
    .brand-panel{ flex:0 0 auto; padding:36px 24px; }
    .brand-content{ animation:none; }
    .cv-logo-plain{ width:64px; height:64px; margin-bottom:14px; }
    .brand-name{ font-size:1.7rem; margin-bottom:10px; }
    .form-panel{ padding:36px 20px 60px; }
}
</style>
</head>
<body>

<!-- Identity panel -->
<div class="brand-panel">
    <div class="brand-content">
        <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort" class="cv-logo-plain">
        <div class="brand-rule"></div>
        <div class="brand-tagline">Where family fun begins</div>
    </div>
</div>

<!-- Sign-in panel -->
<div class="form-panel">
    <div class="form-inner">
        <div class="form-eyebrow">Admin Access</div>
        <div class="form-title">Welcome back</div>
        <div class="form-sub">Sign in to manage bookings, rooms, and guests.</div>

        <?php if ($error && !$show_otp_modal): ?>
        <div class="al-alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="admin_login.php" id="loginForm" novalidate>
            <input type="hidden" name="action" value="login">

        <div class="lf-group">
            <div class="lf-input-row<?= $field_errors['username'] ? ' has-error' : '' ?>">
                <span class="lf-icon"><i class="fa-regular fa-user"></i></span>
                <input type="text" name="username" placeholder="Username" required autocomplete="username"
                       value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES) : '' ?>">
            </div>
            <?php if ($field_errors['username']): ?>
            <div class="lf-field-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($field_errors['username']) ?></div>
            <?php endif; ?>
        </div>

            <div class="lf-group">
                <div class="lf-input-row<?= $field_errors['password'] ? ' has-error' : '' ?>">
                    <span class="lf-icon"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" name="password" id="pw-admin" placeholder="Passowrd" required autocomplete="current-password">
                    <button type="button" class="lf-eye" onclick="togglePw('pw-admin', this)"><i class="fa-regular fa-eye"></i>
                        
                    </button>
                </div>
                <?php if ($field_errors['password']): ?>
                <div class="lf-field-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($field_errors['password']) ?></div>
                <?php endif; ?>
            </div>

            <label class="lf-remember">
                <input type="checkbox" name="remember_device" value="1">
                <span>Remember me</span>
            </label>

            <button type="submit" class="lf-submit" id="loginSubmitBtn" <?= $lockout_seconds > 0 ? 'disabled' : '' ?>>
                <i class="fa-solid fa-right-to-bracket"></i> <span id="loginSubmitLabel">Sign In</span>
            </button>
        </form>

        <a href="../frontend/index.php" class="al-back">
            <i class="fa-solid fa-arrow-left"></i> Back to website
        </a>
    </div>
</div>

<!-- OTP MODAL -->
<div class="otp-overlay" id="otpOverlay">
    <div class="otp-box">
        <div class="otp-icon"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="otp-title">Verify It's You</div>
        <p class="otp-sub">We've sent a 6-digit code to the admin inbox.</p>
        <span class="otp-email-label"><?= isset($_SESSION['temp_admin_email']) ? htmlspecialchars($_SESSION['temp_admin_email']) : '' ?></span>

        <?php if ($show_otp_modal && $error): ?>
        <div class="otp-alert"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="admin_login.php" id="otpForm">
            <input type="hidden" name="action" value="verify_otp">
            <input type="hidden" name="otp" id="otp-hidden">
            <div class="otp-inputs" id="otpInputs">
                <input class="otp-digit" type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code">
                <input class="otp-digit" type="text" inputmode="numeric" maxlength="1">
                <input class="otp-digit" type="text" inputmode="numeric" maxlength="1">
                <input class="otp-digit" type="text" inputmode="numeric" maxlength="1">
                <input class="otp-digit" type="text" inputmode="numeric" maxlength="1">
                <input class="otp-digit" type="text" inputmode="numeric" maxlength="1">
            </div>
            <div class="otp-timer">Code expires in <span id="otpCountdown">10:00</span></div>
            <button type="submit" class="otp-btn" id="otpSubmitBtn" disabled>
                <i class="fa-solid fa-check"></i> Verify &amp; Sign In
            </button>
        </form>

        <p class="otp-resend">
            Didn't receive the code?
            <button type="button" id="otpResendBtn" disabled>Resend</button>
            <span id="otpResendTimer"></span>
        </p>
        <button class="otp-back" onclick="window.location.href='admin_login.php'">
            <i class="fa-solid fa-arrow-left"></i> Back to Sign In
        </button>
    </div>
</div>

<script>
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (inp.type === 'password') { inp.type = 'text'; icon.className = 'fa-regular fa-eye-slash'; }
    else { inp.type = 'password'; icon.className = 'fa-regular fa-eye'; }
    btn.style.display = 'flex';
}
document.querySelectorAll('.lf-group').forEach(group => {
    const inp = group.querySelector('input[type="password"]');
    const eye = group.querySelector('.lf-eye');
    if (!inp || !eye) return;
    eye.style.display = 'none';
    inp.addEventListener('input', () => { eye.style.display = inp.value.length > 0 ? 'flex' : 'none'; });
});

/* ── Instant styled field errors on submit (replaces native "required" popup) ── */
function showFieldError(input, msg) {
    const row = input.closest('.lf-input-row');
    row.classList.add('has-error');
    const group = row.closest('.lf-group');
    let err = group.querySelector('.lf-field-error');
    if (!err) {
        err = document.createElement('div');
        err.className = 'lf-field-error';
        row.insertAdjacentElement('afterend', err);
    }
    err.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + msg;
}
function clearFieldError(input) {
    const row = input.closest('.lf-input-row');
    row.classList.remove('has-error');
    const group = row.closest('.lf-group');
    const err = group.querySelector('.lf-field-error');
    if (err) err.remove();
}

const loginForm = document.getElementById('loginForm');
if (loginForm) {
    const uInput = loginForm.querySelector('input[name="username"]');
    const pInput = loginForm.querySelector('input[name="password"]');

    loginForm.addEventListener('submit', function (e) {
        let valid = true;
        if (!uInput.value.trim()) { showFieldError(uInput, 'Please enter your username.'); valid = false; }
        else { clearFieldError(uInput); }
        if (!pInput.value.trim()) { showFieldError(pInput, 'Please enter your password.'); valid = false; }
        else { clearFieldError(pInput); }
        if (!valid) e.preventDefault();
    });

    uInput.addEventListener('focus', () => clearFieldError(uInput));
    pInput.addEventListener('focus', () => clearFieldError(pInput));
    uInput.addEventListener('input', () => { if (uInput.value.trim()) clearFieldError(uInput); });
    pInput.addEventListener('input', () => { if (pInput.value.trim()) clearFieldError(pInput); });
}

const digits    = document.querySelectorAll('.otp-digit');
const submitBtn = document.getElementById('otpSubmitBtn');
const otpHidden = document.getElementById('otp-hidden');
let countdownInterval = null;

function openOtp() {
    document.getElementById('otpOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => digits[0]?.focus(), 300);
    startCountdown(10 * 60);
}

digits.forEach((digit, i) => {
    digit.addEventListener('keydown', e => {
        if (e.key === 'Backspace') {
            digit.value = ''; digit.classList.remove('filled');
            if (i > 0) digits[i - 1].focus();
            updateSubmitBtn(); e.preventDefault();
        } else if (e.key === 'ArrowLeft' && i > 0) digits[i-1].focus();
        else if (e.key === 'ArrowRight' && i < digits.length - 1) digits[i+1].focus();
    });
    digit.addEventListener('input', e => {
        const val = e.target.value.replace(/\D/g, '');
        digit.value = val ? val[val.length - 1] : '';
        digit.value ? digit.classList.add('filled') : digit.classList.remove('filled');
        if (digit.value && i < digits.length - 1) digits[i + 1].focus();
        updateSubmitBtn();
    });
    digit.addEventListener('paste', e => {
        e.preventDefault();
        const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
        if (paste.length === 6) {
            paste.split('').forEach((ch, idx) => { digits[idx].value = ch; digits[idx].classList.add('filled'); });
            digits[5].focus(); updateSubmitBtn();
        }
    });
});
function updateSubmitBtn() {
    const complete = [...digits].every(d => d.value.length === 1);
    submitBtn.disabled = !complete;
    if (complete) otpHidden.value = [...digits].map(d => d.value).join('');
}
function startCountdown(seconds) {
    clearInterval(countdownInterval);
    const el = document.getElementById('otpCountdown');
    const timerEl = document.querySelector('.otp-timer');
    let remaining = seconds;
    const update = () => {
        const m = Math.floor(remaining / 60), s = remaining % 60;
        el.textContent = `${m}:${s.toString().padStart(2, '0')}`;
        if (remaining <= 0) {
            clearInterval(countdownInterval);
            timerEl.innerHTML = '<span style="color:#e53e3e">Code expired. Please go back and try again.</span>';
            submitBtn.disabled = true;
        }
        remaining--;
    };
    update(); countdownInterval = setInterval(update, 1000);
}

<?php if ($show_otp_modal && $error): ?>
window.addEventListener('load', () => {
    openOtp();
    digits.forEach(d => { d.classList.remove('shake'); void d.offsetWidth; d.classList.add('shake'); });
});
<?php elseif ($show_otp_modal): ?>
window.addEventListener('load', openOtp);
<?php endif; ?>

<?php if ($lockout_seconds > 0): ?>
(function(){
    let remaining = <?= (int) $lockout_seconds ?>;
    const btn = document.getElementById('loginSubmitBtn');
    const lbl = document.getElementById('loginSubmitLabel');
    const tick = () => {
        const m = Math.floor(remaining / 60), s = remaining % 60;
        lbl.textContent = 'Try again in ' + m + ':' + s.toString().padStart(2, '0');
        if (remaining <= 0) {
            clearInterval(iv);
            btn.disabled = false;
            lbl.textContent = 'Sign In';
        }
        remaining--;
    };
    tick();
    const iv = setInterval(tick, 1000);
})();
<?php endif; ?>
</script>
</body>
</html>