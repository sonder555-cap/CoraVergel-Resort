<?php
session_start();
require "../config/conn.php";
require "../config/security.php";
require_once "../config/csrf.php";
require "../config/mailer.php";

$error = "";
$show_otp_modal = false;
$reset_error = "";
$reset_success = "";

// Flash message surfaced after a redirect (e.g. right after a successful
// password reset) — read once, then discarded.
if (!empty($_SESSION['flash_success'])) {
    $reset_success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Shows just enough of the destination email to be reassuring without
// exposing the whole address, e.g. "ja***@g***.com".
function maskEmail($email) {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    [$user, $domain] = $parts;
    $maskedUser = strlen($user) <= 2 ? substr($user, 0, 1) . '*' : substr($user, 0, 2) . str_repeat('*', max(1, strlen($user) - 2));
    $domainParts = explode('.', $domain);
    $host = $domainParts[0];
    $maskedHost = strlen($host) <= 1 ? $host : substr($host, 0, 1) . str_repeat('*', max(1, strlen($host) - 1));
    $tld = implode('.', array_slice($domainParts, 1));
    return $maskedUser . '@' . $maskedHost . ($tld ? '.' . $tld : '');
}

// Issues a fresh single-use reset token for $admin_id, stores only its hash
// (the raw token is a bearer credential and never touches the database),
// and emails a clickable reset link built from the current request's own
// host/path so this works regardless of domain/environment.
function sendPasswordResetLink($conn, $admin_id, $full_name, $email) {
    $token      = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);
    $expires    = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $del = $conn->prepare("DELETE FROM password_resets WHERE admin_id = ?");
    $del->bind_param("i", $admin_id); $del->execute(); $del->close();
    $ins = $conn->prepare("INSERT INTO password_resets (admin_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    $ins->bind_param("isss", $admin_id, $email, $token_hash, $expires); $ins->execute(); $ins->close();

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = strtok($_SERVER['REQUEST_URI'] ?? 'admin_login.php', '?');
    $link   = "{$scheme}://{$host}{$path}?token={$token}";

    $subject  = "CoraVergel Resort — Reset Your Admin Password";
    $bodyHtml = "<p>Hi {$full_name},</p>"
              . "<p>We received a request to reset your admin password. Click the button below to choose a new one:</p>"
              . "<p style='margin:22px 0;'><a href=\"{$link}\" style=\"background:#111;color:#fff;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:600;display:inline-block;\">Reset Password</a></p>"
              . "<p>This link expires in 30 minutes and can only be used once. If you didn't request this, you can safely ignore this email — your password will not be changed.</p>"
              . "<p style='color:#888;font-size:.85em;'>Or paste this link into your browser:<br>{$link}</p>"
              . "<p>— CoraVergel Resort</p>";

    return sendMail($email, $full_name, $subject, $bodyHtml);
}

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
    $stmt = $conn->prepare("SELECT attempts FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email); $stmt->execute();
    $stmt->bind_result($count);
    $exists = $stmt->fetch();
    $stmt->close();

    $count = $exists ? $count + 1 : 1;

    if ($count >= 5) {
        $locked_until = date('Y-m-d H:i:s', strtotime('+3 minutes'));
        if ($exists) {
            $u = $conn->prepare("UPDATE login_attempts SET attempts = 0, locked_until = ? WHERE email = ?");
            $u->bind_param("ss", $locked_until, $email); $u->execute(); $u->close();
        } else {
            $i = $conn->prepare("INSERT INTO login_attempts (email, attempts, locked_until) VALUES (?, 0, ?)");
            $i->bind_param("ss", $email, $locked_until); $i->execute(); $i->close();
        }
        return ['locked' => true, 'seconds' => 180, 'attempts_left' => 0];
    }

    if ($exists) {
        $u = $conn->prepare("UPDATE login_attempts SET attempts = ? WHERE email = ?");
        $u->bind_param("is", $count, $email); $u->execute(); $u->close();
    } else {
        $i = $conn->prepare("INSERT INTO login_attempts (email, attempts) VALUES (?, ?)");
        $i->bind_param("si", $email, $count); $i->execute(); $i->close();
    }
    return ['locked' => false, 'seconds' => 0, 'attempts_left' => 5 - $count];
}

function clearLoginAttempts($conn, $email) {
    $d = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
    $d->bind_param("s", $email); $d->execute(); $d->close();
}

// ── RESEND OTP (AJAX) ──
// Reuses the exact same OTP-generation logic as the login handler below,
// against the pending login already stashed in the session — it does not
// re-check the password, since the person already passed that step.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_otp') {
    header('Content-Type: application/json');

    $submitted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security check failed. Please refresh the page.']);
        exit();
    }

    if (!isset($_SESSION['temp_admin_id'], $_SESSION['temp_admin_email'], $_SESSION['temp_admin_name'])) {
        echo json_encode(['success' => false, 'message' => 'Your session expired. Please log in again.']);
        exit();
    }

    $full_name      = $_SESSION['temp_admin_name'];
    $delivery_email = $_SESSION['temp_admin_email'];

    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    $del = $conn->prepare("DELETE FROM otp_codes WHERE email = ?");
    $del->bind_param("s", $delivery_email); $del->execute(); $del->close();
    $ins = $conn->prepare("INSERT INTO otp_codes (email, otp, expires_at) VALUES (?, ?, ?)");
    $ins->bind_param("sss", $delivery_email, $otp, $otp_expires); $ins->execute(); $ins->close();

    $subject  = "CoraVergel Resort — Admin Login Code";
    $bodyHtml = "<p>Hi {$full_name},</p>"
              . "<p>Your one-time admin verification code is:</p>"
              . "<h2 style='letter-spacing:4px;'>{$otp}</h2>"
              . "<p>This code expires in 10 minutes.</p>"
              . "<p>If you did not attempt to log in, please ignore this email.</p>"
              . "<p>— CoraVergel Resort</p>";

    $sent = sendMail($delivery_email, $full_name, $subject, $bodyHtml);
    echo json_encode([
        'success' => $sent,
        'message' => $sent ? 'A new code has been sent.' : 'Could not send the email. Please try again in a moment.',
    ]);
    exit();
}

// ── FORGOT PASSWORD: REQUEST RESET LINK (AJAX) ──
// Always returns the same generic success message whether or not the
// account exists, so this endpoint can't be used to enumerate valid
// usernames/emails. The link is only actually sent when a match is found.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_request') {
    header('Content-Type: application/json');

    $submitted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security check failed. Please refresh the page.']);
        exit();
    }

    if (!empty($_SESSION['fp_last_sent']) && (time() - $_SESSION['fp_last_sent']) < 30) {
        echo json_encode(['success' => false, 'message' => 'Please wait a moment before requesting another link.']);
        exit();
    }

    $identifier = sanitize($_POST['identifier'] ?? '');
    if (empty($identifier)) {
        echo json_encode(['success' => false, 'message' => 'Please enter your username or email.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT admin_id, full_name, email FROM admins WHERE username = ? OR email = ? LIMIT 1");
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $stmt->bind_result($admin_id, $full_name, $email);
    $found = $stmt->fetch();
    $stmt->close();

    $masked = null;
    if ($found) {
        sendPasswordResetLink($conn, $admin_id, $full_name, $email);
        $masked = maskEmail($email);
        // Kept only so the "Resend" button on the confirmation screen knows
        // who to re-send to — it does NOT authorize the password change.
        $_SESSION['temp_reset_admin_id'] = $admin_id;
        $_SESSION['temp_reset_email']    = $email;
    }

    $_SESSION['fp_last_sent'] = time();
    echo json_encode([
        'success' => true,
        'message' => 'If that account exists, a reset link has been sent to its registered email.',
        'masked_email' => $masked,
    ]);
    exit();
}

// ── FORGOT PASSWORD: RESEND RESET LINK (AJAX) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_resend') {
    header('Content-Type: application/json');

    $submitted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Security check failed. Please refresh the page.']);
        exit();
    }
    if (!isset($_SESSION['temp_reset_admin_id'], $_SESSION['temp_reset_email'])) {
        echo json_encode(['success' => false, 'message' => 'Your reset session expired. Please start again.']);
        exit();
    }
    if (!empty($_SESSION['fp_last_sent']) && (time() - $_SESSION['fp_last_sent']) < 30) {
        echo json_encode(['success' => false, 'message' => 'Please wait a moment before requesting another link.']);
        exit();
    }

    $admin_id = $_SESSION['temp_reset_admin_id'];
    $email    = $_SESSION['temp_reset_email'];

    $stmt = $conn->prepare("SELECT full_name FROM admins WHERE admin_id = ?");
    $stmt->bind_param("i", $admin_id); $stmt->execute();
    $stmt->bind_result($full_name); $stmt->fetch(); $stmt->close();

    $sent = sendPasswordResetLink($conn, $admin_id, $full_name, $email);

    $_SESSION['fp_last_sent'] = time();
    echo json_encode(['success' => $sent, 'message' => $sent ? 'A new link has been sent.' : 'Could not send the email. Please try again in a moment.']);
    exit();
}

// ── LOGIN ──
$field_errors = ['username' => '', 'password' => ''];
$lockout_seconds = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    csrfVerify();
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
            $has_otp_column = true;
            try {
                $stmt = $conn->prepare("SELECT admin_id, full_name, email, password, two_factor_enabled FROM admins WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->store_result();
            } catch (mysqli_sql_exception $e) {
                // two_factor_enabled column not migrated yet — fall back so login
                // still works; OTP will simply run every time until it's added.
                $has_otp_column = false;
                $stmt = $conn->prepare("SELECT admin_id, full_name, email, password FROM admins WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->store_result();
            }
            if ($stmt->num_rows === 1) {
                if ($has_otp_column) {
                    $stmt->bind_result($id, $full_name, $email, $hashed_password, $two_factor_enabled);
                } else {
                    $stmt->bind_result($id, $full_name, $email, $hashed_password);
                    $two_factor_enabled = 1;
                }
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

                    // Admin has turned off two-factor authentication for their own account in
                    // Settings — sign them straight in on password alone.
                    if ((int)$two_factor_enabled === 0) {
                        session_regenerate_id(true);
                        $_SESSION['admin_id']   = $id;
                        $_SESSION['admin_name'] = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
                        $_SESSION['admin_role'] = 'admin';
                        logLoginEvent($conn, $id, $username, 'otp_disabled');
                        header("Location: admin_dashboard.php");
                        exit();
                    }

                    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                    $delivery_email = $email;

                    $del = $conn->prepare("DELETE FROM otp_codes WHERE email = ?");
                    $del->bind_param("s", $delivery_email); $del->execute(); $del->close();
                    $ins = $conn->prepare("INSERT INTO otp_codes (email, otp, expires_at) VALUES (?, ?, ?)");
                    $ins->bind_param("sss", $delivery_email, $otp, $otp_expires); $ins->execute(); $ins->close();

                    $_SESSION['temp_admin_id']   = $id;
                    $_SESSION['temp_admin_name'] = $full_name;
                    $_SESSION['temp_admin_email']= $delivery_email;
                    $_SESSION['temp_admin_username'] = $username;
                    $_SESSION['temp_remember']   = !empty($_POST['remember_device']);

                    $subject  = "CoraVergel Resort — Admin Login Code";
                    $bodyHtml = "<p>Hi {$full_name},</p>"
                              . "<p>Your one-time admin verification code is:</p>"
                              . "<h2 style='letter-spacing:4px;'>{$otp}</h2>"
                              . "<p>This code expires in 10 minutes.</p>"
                              . "<p>If you did not attempt to log in, please ignore this email.</p>"
                              . "<p>— CoraVergel Resort</p>";

                    // OTP is always delivered to the authenticated administrator's own account email.
                    sendMail($delivery_email, $full_name, $subject, $bodyHtml);
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
    csrfVerify();
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
        } elseif (hash_equals((string)$db_otp, (string)$otp_input)) {
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
// ── FORGOT PASSWORD: SET NEW PASSWORD (from emailed link) ──
// Authorized purely by the bearer token from the email — no session state
// required, so the link works even in a different browser/device than the
// one the request was made from.
$show_reset_modal = false;
$reset_token_valid = false;
$reset_token = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    csrfVerify();
    $show_reset_modal = true;

    $token      = trim($_POST['token'] ?? '');
    $new_pw     = $_POST['new_password'] ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';
    $token_hash = hash('sha256', $token);

    $stmt = $conn->prepare("SELECT admin_id, expires_at FROM password_resets WHERE token_hash = ? LIMIT 1");
    $stmt->bind_param("s", $token_hash); $stmt->execute();
    $stmt->bind_result($pr_admin_id, $pr_expires);
    $found = $stmt->fetch(); $stmt->close();

    if (!$found) {
        $reset_error = "This reset link is invalid or has already been used.";
    } elseif (strtotime($pr_expires) < time()) {
        $reset_error = "This reset link has expired. Please request a new one.";
        $del = $conn->prepare("DELETE FROM password_resets WHERE token_hash = ?");
        $del->bind_param("s", $token_hash); $del->execute(); $del->close();
    } elseif (strlen($new_pw) < 8) {
        $reset_error = "New password must be at least 8 characters long.";
        $reset_token_valid = true; $reset_token = $token;
    } elseif ($new_pw !== $confirm_pw) {
        $reset_error = "Passwords don't match.";
        $reset_token_valid = true; $reset_token = $token;
    } else {
        $hash = password_hash($new_pw, PASSWORD_DEFAULT);
        $u = $conn->prepare("UPDATE admins SET password = ? WHERE admin_id = ?");
        $u->bind_param("si", $hash, $pr_admin_id); $u->execute(); $u->close();

        $del = $conn->prepare("DELETE FROM password_resets WHERE token_hash = ?");
        $del->bind_param("s", $token_hash); $del->execute(); $del->close();

        // A stolen "remember this device" cookie shouldn't survive a
        // password reset — force every device to log in fresh.
        $delr = $conn->prepare("DELETE FROM remember_tokens WHERE admin_id = ?");
        $delr->bind_param("i", $pr_admin_id); $delr->execute(); $delr->close();

        unset($_SESSION['temp_reset_admin_id'], $_SESSION['temp_reset_email'], $_SESSION['fp_last_sent']);
        $_SESSION['flash_success'] = "Your password has been reset. Please sign in with your new password.";
        header("Location: admin_login.php");
        exit();
    }
} elseif (isset($_GET['token']) && $_GET['token'] !== '') {
    // Landing here fresh from the emailed link.
    $reset_token = trim($_GET['token']);
    $token_hash  = hash('sha256', $reset_token);

    $stmt = $conn->prepare("SELECT expires_at FROM password_resets WHERE token_hash = ? LIMIT 1");
    $stmt->bind_param("s", $token_hash); $stmt->execute();
    $stmt->bind_result($pr_expires);
    $found = $stmt->fetch(); $stmt->close();

    $show_reset_modal = true;
    if ($found && strtotime($pr_expires) >= time()) {
        $reset_token_valid = true;
    } else {
        $reset_error = "This reset link is invalid or has expired. Please request a new one.";
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

--brand-bg:#000;
--brand-gold:#c8a96e;
--brand-gold-soft:#e0c58f;
--brand-gold-line:rgba(200,169,110,.38);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
body{
    font-family:'DM Sans',sans-serif;
    display:flex; min-height:100vh; background:var(--ivory);
}

.brand-panel{
    position:relative;
    flex:0 0 42%;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    background:var(--brand-bg);
    overflow:hidden;
    padding:60px 40px;
}

/* Gold diagonal stripes */
.brand-panel::before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background-image:repeating-linear-gradient(
        115deg,
        transparent 0 38px,
        rgba(200,169,110,.13) 38px 39px
    );
}

/* Soft gold atmosphere */
.brand-panel::after{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:
        radial-gradient(
            circle at 30% 20%,
            rgba(200,169,110,.08),
            transparent 55%
        ),
        radial-gradient(
            circle at 75% 85%,
            rgba(200,169,110,.05),
            transparent 50%
        );
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
    font-size:2rem; color:var(--navy); margin-bottom:30px;
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
.al-alert--success{
    background:#eef8f0; border-color:#cfe8d4; color:#1e7d3a;
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

/* Fires a no-op animation whenever the browser/password-manager autofills
   this field, so JS can detect it even when no real "input" event is sent. */
@keyframes lfAutofillStart{ from{} to{} }
.lf-input-row input:-webkit-autofill{ animation-name:lfAutofillStart; }
.lf-row-between{
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:26px; gap:12px; flex-wrap:wrap;
}
.lf-forgot-link{
    background:none; border:none; padding:0; cursor:pointer;
    font-family:'DM Sans',sans-serif; font-size:.8rem; font-weight:600;
    color:var(--gray); text-decoration:underline; text-underline-offset:2px;
    transition:color .15s;
}
.lf-forgot-link:hover{ color:var(--navy); }
.lf-remember{
    display:flex; align-items:center; gap:9px;
    font-size:.82rem; color:var(--gray); margin-bottom:0; cursor:pointer;
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
    position:relative;
}
.otp-close{
    position:absolute; top:14px; right:14px;
    width:30px; height:30px; border-radius:50%; border:none;
    background:transparent; color:var(--gray); font-size:.95rem;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:all .15s;
}
.otp-close:hover{ background:#efece4; color:var(--navy); }
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
    background:#fff; text-align:center; font-family:'DM Sans',sans-serif;
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
#otpResendBtn, #forgotResendBtn{
    background:none; border:none; cursor:pointer; font-size:.8rem; font-family:'DM Sans',sans-serif;
    font-weight:600; color:var(--navy); text-decoration:underline; text-underline-offset:2px;
}
#otpResendBtn:disabled, #forgotResendBtn:disabled{ color:#bbb; cursor:not-allowed; text-decoration:none; }
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
        <div class="brand-tagline">Where family fun begins</div>
    </div>
</div>

<!-- Sign-in panel -->
<div class="form-panel">
    <div class="form-inner">
        <div class="form-eyebrow">Admin Access</div>
        <div class="form-title">Welcome back</div>

        <?php if ($error && !$show_otp_modal): ?>
        <div class="al-alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        <?php if ($reset_success): ?>
        <div class="al-alert al-alert--success">
            <i class="fa-solid fa-circle-check"></i>
            <?= htmlspecialchars($reset_success) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="admin_login.php" id="loginForm" novalidate>
            <?= csrfField() ?>
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

            <div class="lf-row-between">
                <label class="lf-remember">
                    <input type="checkbox" name="remember_device" value="1">
                    <span>Remember me</span>
                </label>
                <button type="button" class="lf-forgot-link" onclick="openForgot()">Forgot password?</button>
            </div>

            <button type="submit" class="lf-submit" id="loginSubmitBtn" <?= $lockout_seconds > 0 ? 'disabled' : '' ?>>
                <span id="loginSubmitLabel">Login</span>
            </button>
        </form>

        <a href="../user/index.php" class="al-back">
            <i class="fa-solid fa-arrow-left"></i> Back to website
        </a>
    </div>
</div>

<!-- OTP MODAL -->
<div class="otp-overlay" id="otpOverlay">
    <div class="otp-box">
        <button type="button" class="otp-close" onclick="window.location.href='admin_login.php'" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="otp-icon"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="otp-title">Verify It's You</div>
        <p class="otp-sub">We've sent a 6-digit code to the admin inbox.</p>
        <span class="otp-email-label"><?= isset($_SESSION['temp_admin_email']) ? htmlspecialchars($_SESSION['temp_admin_email']) : '' ?></span>

        <?php if ($show_otp_modal && $error): ?>
        <div class="otp-alert"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="admin_login.php" id="otpForm">
            <?= csrfField() ?>
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
            <button type="button" id="otpResendBtn">Resend</button>
            <span id="otpResendTimer"></span>
        </p>
    </div>
</div>

<!-- FORGOT PASSWORD MODAL -->
<div class="otp-overlay" id="forgotOverlay">
    <div class="otp-box">
        <button type="button" class="otp-close" onclick="closeForgot()" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <!-- Step 1: identify the account -->
        <div id="forgotStep1" style="display:<?= $show_reset_modal ? 'none' : 'block' ?>;">
            <div class="otp-icon"><i class="fa-solid fa-key"></i></div>
            <div class="otp-title">Reset Password</div>
            <p class="otp-sub">Enter your admin username or email and we'll send a password reset link to the account's registered email.</p>
            <div id="forgotStep1Alert"></div>

            <div class="lf-group" style="text-align:left;">
                <div class="lf-input-row" id="forgotIdentifierRow">
                    <span class="lf-icon"><i class="fa-regular fa-user"></i></span>
                    <input type="text" id="forgotIdentifier" placeholder="Username or email" autocomplete="username" onkeydown="if(event.key==='Enter'){event.preventDefault(); sendForgotLink();}">
                </div>
            </div>

            <button type="button" class="otp-btn" id="forgotSendBtn" onclick="sendForgotLink()">
                <i class="fa-solid fa-paper-plane"></i> Send Reset Link
            </button>
        </div>

        <!-- Step 2: confirmation that the link was sent -->
        <div id="forgotStepSent" style="display:none;">
            <div class="otp-icon"><i class="fa-solid fa-envelope-circle-check"></i></div>
            <div class="otp-title">Check Your Email</div>
            <p class="otp-sub">We've sent a password reset link to</p>
            <span class="otp-email-label" id="forgotSentEmail"></span>
            <p class="otp-sub" style="margin-top:14px;">Click the link in that email to choose a new password. It expires in 30 minutes.</p>

            <p class="otp-resend">
                Didn't receive it?
                <button type="button" id="forgotResendBtn">Resend</button>
                <span id="forgotResendTimer"></span>
            </p>
        </div>

        <!-- Step 3: reached via the emailed link — set a new password -->
        <div id="forgotStepReset" style="display:<?= $show_reset_modal ? 'block' : 'none' ?>;">
            <?php if ($reset_token_valid): ?>
                <div class="otp-icon"><i class="fa-solid fa-lock-open"></i></div>
                <div class="otp-title">Set New Password</div>
                <p class="otp-sub">Choose a new password for your admin account.</p>

                <?php if ($reset_error): ?>
                <div class="otp-alert"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($reset_error) ?></div>
                <?php endif; ?>

                <form method="POST" action="admin_login.php" id="resetForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($reset_token) ?>">

                    <div class="lf-group" style="text-align:left;">
                        <div class="lf-input-row">
                            <span class="lf-icon"><i class="fa-solid fa-lock"></i></span>
                            <input type="password" name="new_password" id="pw-new" placeholder="New password" required autocomplete="new-password" minlength="8">
                            <button type="button" class="lf-eye" onclick="togglePw('pw-new', this)"><i class="fa-regular fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="lf-group" style="text-align:left;">
                        <div class="lf-input-row">
                            <span class="lf-icon"><i class="fa-solid fa-lock"></i></span>
                            <input type="password" name="confirm_password" id="pw-confirm" placeholder="Confirm new password" required autocomplete="new-password" minlength="8">
                            <button type="button" class="lf-eye" onclick="togglePw('pw-confirm', this)"><i class="fa-regular fa-eye"></i></button>
                        </div>
                    </div>

                    <button type="submit" class="otp-btn">
                        <i class="fa-solid fa-check"></i> Reset Password
                    </button>
                </form>
            <?php else: ?>
                <div class="otp-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div class="otp-title">Link Invalid</div>
                <div class="otp-alert" style="margin-top:6px;text-align:left;">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= htmlspecialchars($reset_error ?: 'This reset link is invalid or has expired.') ?>
                </div>
                <button type="button" class="otp-btn" onclick="backToForgotStep1()" style="margin-top:16px;">
                    <i class="fa-solid fa-rotate-left"></i> Request New Link
                </button>
            <?php endif; ?>
        </div>

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

    const syncEye = () => { eye.style.display = inp.value.length > 0 ? 'flex' : 'none'; };

    syncEye(); // in case the browser already restored/filled a value on load
    inp.addEventListener('input', syncEye);

    // Managed/extension password fills (Chrome's "Manage passwords…" dropdown,
    // 1Password, Bitwarden, etc.) often set .value without dispatching a real
    // "input" event, so we also catch the CSS-driven autofill animation.
    inp.addEventListener('animationstart', (e) => {
        if (e.animationName === 'lfAutofillStart') syncEye();
    });
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
const resendBtn = document.getElementById('otpResendBtn');
const resendTimerEl = document.getElementById('otpResendTimer');
let countdownInterval = null;
let resendCooldownInterval = null;

function openOtp() {
    document.getElementById('otpOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => digits[0]?.focus(), 300);
    startCountdown(10 * 60);
    // Resend starts available right away — the 30s cooldown only kicks in
    // after the user actually uses it once.
}

function startResendCooldown(seconds) {
    clearInterval(resendCooldownInterval);
    resendBtn.disabled = true;
    let remaining = seconds;
    const update = () => {
        if (remaining <= 0) {
            clearInterval(resendCooldownInterval);
            resendBtn.disabled = false;
            resendTimerEl.textContent = '';
            return;
        }
        resendTimerEl.textContent = `(available in ${remaining}s)`;
        remaining--;
    };
    update();
    resendCooldownInterval = setInterval(update, 1000);
}

if (resendBtn) {
    resendBtn.addEventListener('click', () => {
        resendBtn.disabled = true;
        resendTimerEl.textContent = 'Sending…';

        const formData = new URLSearchParams();
        formData.set('action', 'resend_otp');
        formData.set('csrf_token', document.querySelector('#otpForm input[name="csrf_token"]').value);

        fetch('admin_login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString(),
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Clear any previously typed digits and restart both timers —
                // the old code is now invalid.
                digits.forEach(d => { d.value = ''; d.classList.remove('filled'); });
                otpHidden.value = '';
                submitBtn.disabled = true;
                document.querySelector('.otp-timer').innerHTML =
                    'Code expires in <span id="otpCountdown">10:00</span>';
                startCountdown(10 * 60);
                startResendCooldown(30);
                digits[0]?.focus();
            } else {
                resendTimerEl.textContent = data.message || 'Could not resend the code.';
                resendBtn.disabled = false;
            }
        })
        .catch(() => {
            resendTimerEl.textContent = 'Network error — please try again.';
            resendBtn.disabled = false;
        });
    });
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

/* ── Forgot password modal ── */
function openForgot(){
    document.getElementById('forgotOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('forgotIdentifier')?.focus(), 300);
}
function closeForgot(){
    document.getElementById('forgotOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
function backToForgotStep1(){
    document.getElementById('forgotStepReset').style.display = 'none';
    document.getElementById('forgotStepSent').style.display = 'none';
    document.getElementById('forgotStep1').style.display = 'block';
    setTimeout(() => document.getElementById('forgotIdentifier')?.focus(), 100);
}
function sendForgotLink(){
    const identInput = document.getElementById('forgotIdentifier');
    const alertBox   = document.getElementById('forgotStep1Alert');
    const btn        = document.getElementById('forgotSendBtn');
    alertBox.innerHTML = '';

    if (!identInput.value.trim()) {
        alertBox.innerHTML = '<div class="otp-alert"><i class="fa-solid fa-circle-exclamation"></i> Please enter your username or email.</div>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending…';
    const formData = new URLSearchParams();
    formData.set('action', 'forgot_request');
    formData.set('identifier', identInput.value.trim());
    formData.set('csrf_token', document.querySelector('#loginForm input[name="csrf_token"]').value);

    fetch('admin_login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString(),
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Reset Link';
        if (data.success) {
            document.getElementById('forgotSentEmail').textContent = data.masked_email || 'your registered email';
            document.getElementById('forgotStep1').style.display = 'none';
            document.getElementById('forgotStepSent').style.display = 'block';
            // Resend starts available right away — the 30s cooldown only
            // kicks in after the user actually uses it once.
        } else {
            alertBox.innerHTML = '<div class="otp-alert"><i class="fa-solid fa-circle-exclamation"></i> ' + (data.message || 'Something went wrong.') + '</div>';
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Send Reset Link';
        alertBox.innerHTML = '<div class="otp-alert"><i class="fa-solid fa-circle-exclamation"></i> Network error — please try again.</div>';
    });
}

const forgotResendBtn = document.getElementById('forgotResendBtn');
const forgotResendTimerEl = document.getElementById('forgotResendTimer');
let forgotResendCooldownInterval = null;

function startForgotResendCooldown(seconds){
    clearInterval(forgotResendCooldownInterval);
    forgotResendBtn.disabled = true;
    let remaining = seconds;
    const update = () => {
        if (remaining <= 0) {
            clearInterval(forgotResendCooldownInterval);
            forgotResendBtn.disabled = false;
            forgotResendTimerEl.textContent = '';
            return;
        }
        forgotResendTimerEl.textContent = `(available in ${remaining}s)`;
        remaining--;
    };
    update();
    forgotResendCooldownInterval = setInterval(update, 1000);
}

if (forgotResendBtn) {
    forgotResendBtn.addEventListener('click', () => {
        forgotResendBtn.disabled = true;
        forgotResendTimerEl.textContent = 'Sending…';

        const formData = new URLSearchParams();
        formData.set('action', 'forgot_resend');
        formData.set('csrf_token', document.querySelector('#loginForm input[name="csrf_token"]').value);

        fetch('admin_login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString(),
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                startForgotResendCooldown(30);
            } else {
                forgotResendTimerEl.textContent = data.message || 'Could not resend the link.';
                forgotResendBtn.disabled = false;
            }
        })
        .catch(() => {
            forgotResendTimerEl.textContent = 'Network error — please try again.';
            forgotResendBtn.disabled = false;
        });
    });
}

<?php if ($show_reset_modal): ?>
window.addEventListener('load', openForgot);
<?php endif; ?>

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