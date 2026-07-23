<?php
session_start();
require "../config/conn.php";
require "../config/security.php";
require "../config/mailer.php";

$error = "";
$success = "";
$active_tab = "signin";
$show_otp_modal = false;

// ── LOGIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $email    = filter_var(sanitize($_POST['email']), FILTER_SANITIZE_EMAIL);
    $password = trim($_POST['password']);

    if (!filter_var($email, FILTER_SANITIZE_EMAIL)) {
        $error = "Please enter a valid email address."; $active_tab = "signin";
    } elseif (empty($password)) {
        $error = "Password cannot be empty."; $active_tab = "signin";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters."; $active_tab = "signin";
    } else {
        $stmt = $conn->prepare("SELECT user_id, full_name, password, role FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 1) {
            $stmt->bind_result($id, $full_name, $hashed_password, $role);
            $stmt->fetch();
            if (password_verify($password, $hashed_password)) {
                $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $otp_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                $del = $conn->prepare("DELETE FROM otp_codes WHERE email = ?");
                $del->bind_param("s", $email); $del->execute(); $del->close();
                $ins = $conn->prepare("INSERT INTO otp_codes (email, otp, expires_at) VALUES (?, ?, ?)");
                $ins->bind_param("sss", $email, $otp, $otp_expires); $ins->execute(); $ins->close();
                $_SESSION['temp_user_id'] = $id;
                $_SESSION['temp_name']    = $full_name;
                $_SESSION['temp_role']    = $role;
                $_SESSION['temp_email']   = $email;
                $subject  = "CoraVergel Resort — Your OTP Code";
                $bodyHtml = "<p>Hi {$full_name},</p>"
                          . "<p>Your one-time verification code is:</p>"
                          . "<h2 style='letter-spacing:4px;'>{$otp}</h2>"
                          . "<p>This code expires in 10 minutes.</p>"
                          . "<p>If you did not attempt to log in, please ignore this email.</p>"
                          . "<p>— CoraVergel Resort</p>";

                // Admin OTPs always go to the hardcoded CEO/admin inbox,
                // regardless of what email is stored in the users table.
                $otp_recipient = ($role === 'admin') ? "lexnnder15@gmail.com" : $email;

                sendMail($otp_recipient, $full_name, $subject, $bodyHtml);
                $show_otp_modal = true; $active_tab = "signin";
            } else {
                $error = "Incorrect password."; $active_tab = "signin";
            }
        } else {
            $error = "No account found with that email."; $active_tab = "signin";
        }
        $stmt->close();
    }
}

// ── VERIFY OTP ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    if (!isset($_SESSION['temp_email'])) {
        $error = "Session expired. Please login again."; $active_tab = "signin";
    } else {
        $otp_input = trim($_POST['otp']);
        $email     = $_SESSION['temp_email'];
        $stmt = $conn->prepare("SELECT otp, expires_at FROM otp_codes WHERE email = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("s", $email); $stmt->execute();
        $stmt->bind_result($db_otp, $db_expires); $stmt->fetch(); $stmt->close();
        if (empty($db_otp)) {
            $error = "OTP not found. Please try logging in again."; $active_tab = "signin";
        } elseif (strtotime($db_expires) < time()) {
            $error = "OTP has expired. Please login again."; $active_tab = "signin";
            unset($_SESSION['temp_user_id'], $_SESSION['temp_name'], $_SESSION['temp_role'], $_SESSION['temp_email']);
        } elseif ($otp_input == $db_otp) {
            $role = $_SESSION['temp_role']; $full_name = $_SESSION['temp_name']; $uid = $_SESSION['temp_user_id'];
            session_regenerate_id(true);
            $_SESSION['user_id']   = $uid;
            $_SESSION['full_name'] = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
            $_SESSION['role']      = $role;
            unset($_SESSION['temp_user_id'], $_SESSION['temp_name'], $_SESSION['temp_role'], $_SESSION['temp_email']);
            $del = $conn->prepare("DELETE FROM otp_codes WHERE email = ?");
            $del->bind_param("s", $email); $del->execute(); $del->close();
            if ($role === 'admin') {
                $_SESSION['admin_id']   = $uid;
                $_SESSION['admin_name'] = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
                $_SESSION['admin_role'] = $role;
                header("Location: ../admin/admin_dashboard.php");
            } else {
                header("Location: ../user/dashboard.php");
            }
            exit();
        } else {
            $error = "Invalid OTP. Please try again."; $show_otp_modal = true; $active_tab = "signin";
        }
    }
}

// ── REGISTER ──
// ── REGISTER ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $full_name        = htmlspecialchars(strip_tags(trim($_POST['full_name'])), ENT_QUOTES, 'UTF-8');
    $email            = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $phone            = preg_replace('/[^0-9+\-\s()]/', '', trim($_POST['phone']));
    $password         = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);

    if (empty($full_name) || strlen($full_name) < 2) {
        $error = "Please enter a valid full name."; $active_tab = "signup";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address."; $active_tab = "signup";
    } elseif (empty($phone) || strlen($phone) < 7) {
        $error = "Please enter a valid phone number."; $active_tab = "signup";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters."; $active_tab = "signup";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match."; $active_tab = "signup";
    } else {
        $check = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $check->bind_param("s", $email); $check->execute(); $check->store_result();
        if ($check->num_rows > 0) {
            $error = "An account with that email already exists."; $active_tab = "signup";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (full_name, email, phone, password) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $full_name, $email, $phone, $hashed);
            if ($stmt->execute()) { $success = "Account created! You can now sign in."; $active_tab = "signin"; }
            else { $error = "Error creating account. Please try again."; $active_tab = "signup"; }
            $stmt->close();
        }
        $check->close();
    }
}

// ── FORGOT PASSWORD ──
// ── FORGOT PASSWORD ──
$fp_message = ''; $fp_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    $fp_email = filter_var(trim($_POST['fp_email']), FILTER_SANITIZE_EMAIL);
    if (!filter_var($fp_email, FILTER_VALIDATE_EMAIL)) {
        $fp_error = "Please enter a valid email address.";
    } else {
        $chk = $conn->prepare("SELECT user_id, full_name FROM users WHERE email = ?");
        $chk->bind_param("s", $fp_email); $chk->execute(); $chk->store_result();
        if ($chk->num_rows === 1) {
            $chk->bind_result($fp_uid, $fp_name); $chk->fetch();

            /* Delete old tokens first */
            $del_old = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $del_old->bind_param("i", $fp_uid); $del_old->execute(); $del_old->close();

            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $ins = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $ins->bind_param("iss", $fp_uid, $token, $expires); $ins->execute(); $ins->close();

            $reset_link = "https://yourdomain.com/user/reset_password.php?token=" . $token;
            $subject    = "CoraVergel Resort — Password Reset";
            $bodyHtml   = "<p>Hi {$fp_name},</p>"
                        . "<p>Click the link below to reset your password (valid for 1 hour):</p>"
                        . "<p><a href='{$reset_link}'>{$reset_link}</a></p>"
                        . "<p>If you didn't request this, ignore this email.</p>"
                        . "<p>— CoraVergel Resort</p>";
            sendMail($fp_email, $fp_name, $subject, $bodyHtml);
        }
        $fp_message = "If that email is registered, a reset link has been sent. Check your inbox.";
        $chk->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="icon" href="../assets/images/logo/cv_logo.png" sizes="any">
    <link rel="stylesheet" href="../assets/css/user.css">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

<!-- ══════════ BACKGROUND ══════════ -->
<div class="login-bg">
    <img src="../assets/images/background.jpg" alt="" class="login-bg-img">
    <div class="login-bg-overlay"></div>
</div>

<!-- ══════════ TOPBAR ══════════ -->
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
            <i class="fa-solid fa-location-dot"></i>
        </a>
        <span class="topbar-divider">|</span>
        <a href="mailto:coravergelresort@gmail.com" class="topbar-link">
            <i class="fa-regular fa-envelope"></i>
        </a>
    </div>
</div>

<!-- ══════════ NAVBAR ══════════ -->
<nav class="navbar">
    <div class="nav-links">
        <a href="../frontend/about.php">ABOUT</a>
        <a href="../frontend/rooms.php">ROOMS &amp; RATES</a>
        <a href="../frontend/gallery.php">GALLERY</a>
        <a href="../frontend/deals.php">DEALS</a>
        <a href="../frontend/index.php#contact">CONTACT</a>
    </div>
    <a href="../frontend/index.php" class="navbar-brand">
        <div class="custom-logo">
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort" class="custom-logo-img">
        </div>
    </a>
</nav>

<!-- ══════════ CARD ══════════ -->
<main class="login-main">
    <div class="login-card">

        <!-- Logo -->
        <h1 class="login-brand">CoraVergel Resort</h1>
        <p class="login-tagline">Your paradise destination awaits</p>

        <!-- Alerts -->
        <?php if ($error && !$show_otp_modal): ?>
            <div class="login-alert login-alert--error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="login-alert login-alert--success">
                <i class="fa-solid fa-circle-check"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="login-tabs">
            <button class="login-tab <?= $active_tab === 'signin' ? 'active' : '' ?>" id="tab-signin">Sign In</button>
            <button class="login-tab <?= $active_tab === 'signup' ? 'active' : '' ?>" id="tab-signup">Sign Up</button>
        </div>

        <!-- ── SIGN IN FORM ── -->
        <div class="login-panel <?= $active_tab === 'signin' ? 'active' : '' ?>" id="panel-signin">
            <form method="POST" action="login.php">
                <input type="hidden" name="action" value="login">

                <div class="lf-group">
                    <span class="lf-icon"><i class="fa-regular fa-envelope"></i></span>
                    <input type="email" name="email" placeholder="Email Address" required autocomplete="email">
                </div>
                <div class="lf-group">
                    <span class="lf-icon"><i class="fa-solid fa-lock"></i></span>
                    <input type="password" name="password" id="pw-signin" placeholder="Password" required autocomplete="current-password">
                    <button type="button" class="lf-eye" onclick="togglePw('pw-signin', this)" tabindex="-1">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>

                                <!-- Remember me + Forgot -->
                <div class="lf-row">
                    <label class="lf-remember">
                        <input type="checkbox" name="remember" id="remember">
                        <span class="lf-check"></span>
                        Remember me
                    </label>
                    <button type="button" class="lf-forgot" onclick="openForgot()">Forgot password?</button>
                </div>

                <button type="submit" class="lf-submit">
                    <i class="fa-solid fa-right-to-bracket"></i> Sign In
                </button>
            </form>
        </div>

        <!-- ── SIGN UP FORM ── -->
<!-- ── SIGN UP FORM ── -->
<div class="login-panel <?= $active_tab === 'signup' ? 'active' : '' ?>" id="panel-signup">
    <form method="POST" action="login.php">
        <input type="hidden" name="action" value="register">

        <div class="lf-group">
            <span class="lf-icon"><i class="fa-regular fa-user"></i></span>
            <input type="text" name="full_name" placeholder="Full Name" required autocomplete="name"
                value="<?= isset($_POST['full_name']) && $active_tab === 'signup' ? htmlspecialchars($_POST['full_name']) : '' ?>">
        </div>

        <div class="lf-group">
            <span class="lf-icon"><i class="fa-regular fa-envelope"></i></span>
            <input type="email" name="email" placeholder="Email Address" required autocomplete="email"
                value="<?= isset($_POST['email']) && $active_tab === 'signup' ? htmlspecialchars($_POST['email']) : '' ?>">
        </div>

        <!-- Phone number field -->
        <div class="lf-group">
            <span class="lf-icon"><i class="fa-solid fa-phone"></i></span>
            <input type="tel" name="phone" placeholder="Phone Number (e.g. +63 912 345 6789)" required autocomplete="tel"
                value="<?= isset($_POST['phone']) && $active_tab === 'signup' ? htmlspecialchars($_POST['phone']) : '' ?>"
                inputmode="numeric"
                pattern="[0-9+\-\s()]+"
                oninput="this.value = this.value.replace(/[^0-9+\-\s()]/g, '')">
        </div>
        <div class="lf-group">
            <span class="lf-icon"><i class="fa-solid fa-lock"></i></span>
            <input type="password" name="password" id="pw-signup" placeholder="Password" required minlength="6">
            <button type="button" class="lf-eye" onclick="togglePw('pw-signup', this)" tabindex="-1">
                <i class="fa-regular fa-eye"></i>
            </button>
        </div>

        <div class="lf-group">
            <span class="lf-icon"><i class="fa-solid fa-lock"></i></span>
            <input type="password" name="confirm_password" id="pw-confirm" placeholder="Confirm Password" required minlength="6">
            <button type="button" class="lf-eye" onclick="togglePw('pw-confirm', this)" tabindex="-1">
                <i class="fa-regular fa-eye"></i>
            </button>
        </div>

        <button type="submit" class="lf-submit">
            <i class="fa-solid fa-user-plus"></i> Create Account
        </button>
    </form>
</div>
    </div>
</main>

<!-- ══════════ OTP MODAL ══════════ -->
<div class="otp-overlay" id="otpOverlay">
    <div class="otp-box">
        <button class="otp-close" onclick="closeOtp()">&times;</button>

        <div class="otp-icon" id="otpIcon">
            <i class="fa-solid fa-shield-halved"></i>
        </div>

        <div class="otp-title">Verify It's You</div>
        <p class="otp-sub">We've sent a 6-digit code to your email.</p>
        <span class="otp-email-label" id="otpEmailLabel">
            <?= isset($_SESSION['temp_email']) ? htmlspecialchars($_SESSION['temp_email']) : '' ?>
        </span>

        <?php if ($show_otp_modal && $error): ?>
            <div class="otp-alert"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" id="otpForm">
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
            <button type="button" id="otpResendBtn" onclick="resendOtp()" disabled>Resend</button>
            <span id="otpResendTimer"></span>
        </p>
        <button class="otp-back" onclick="closeOtp()">
            <i class="fa-solid fa-arrow-left"></i> Back to Sign In
        </button>
    </div>
</div>

<!-- ══════════ FORGOT PASSWORD MODAL ══════════ -->
<div class="fp-overlay" id="fpOverlay" onclick="closeForgotOutside(event)">
    <div class="fp-box">
        <button class="fp-close" onclick="closeForgot()">&times;</button>
        <div class="fp-icon"><i class="fa-solid fa-key"></i></div>
        <div class="fp-title">Reset Password</div>
        <p class="fp-sub">Enter your email and we'll send you a reset link.</p>

        <?php if ($fp_message): ?>
            <div class="fp-alert success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($fp_message) ?></div>
        <?php endif; ?>
        <?php if ($fp_error): ?>
            <div class="fp-alert error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($fp_error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php#fp">
            <input type="hidden" name="action" value="forgot_password">
            <div class="lf-group" style="margin-bottom:16px;">
                <span class="lf-icon" style="color:#a07840;"><i class="fa-regular fa-envelope"></i></span>
                <input type="email" name="fp_email" placeholder="your@email.com" required
                    value="<?= isset($_POST['fp_email']) ? htmlspecialchars($_POST['fp_email']) : '' ?>"
                    style="background:#fff;border-color:#e0d5c8;color:#1a1a2e;">
            </div>
            <button type="submit" class="fp-btn">
                <i class="fa-solid fa-paper-plane"></i> Send Reset Link
            </button>
        </form>
        <button class="fp-back" onclick="closeForgot()">
            <i class="fa-solid fa-arrow-left"></i> Back to Sign In
        </button>
    </div>
</div>

<!-- ══════════ JAVASCRIPT ══════════ -->
<script>
/* ── Eye button show/hide ── */
document.querySelectorAll('.lf-group').forEach(group => {
    const inp = group.querySelector('input[type="password"], input[type="text"]');
    const eye = group.querySelector('.lf-eye');
    if (!inp || !eye) return;
    eye.style.display = 'none';
    inp.addEventListener('input', () => {
        eye.style.display = inp.value.length > 0 ? 'flex' : 'none';
    });
});
/* ── Tabs ── */
document.getElementById('tab-signin').addEventListener('click', () => switchTab('signin'));
document.getElementById('tab-signup').addEventListener('click', () => switchTab('signup'));

function switchTab(tab) {
    document.querySelectorAll('.login-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.login-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');

    // Clear all inputs in the panel we're switching AWAY from
    const other = tab === 'signin' ? 'signup' : 'signin';
    document.querySelectorAll('#panel-' + other + ' input').forEach(inp => {
        inp.value = '';
        // Hide eye buttons
        const eye = inp.closest('.lf-group')?.querySelector('.lf-eye');
        if (eye) eye.style.display = 'none';
    });
}
/* ── Password toggle ── */
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
    btn.style.display = 'flex'; // keep eye visible after toggle
}
/* ── Forgot Password modal ── */
function openForgot() {
    document.getElementById('fpOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeForgot() {
    document.getElementById('fpOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
function closeForgotOutside(e) {
    if (e.target === document.getElementById('fpOverlay')) closeForgot();
}
<?php if (!empty($fp_message) || !empty($fp_error)): ?>
window.addEventListener('load', () => openForgot());
<?php endif; ?>
/* ── OTP Modal ── */
const digits    = document.querySelectorAll('.otp-digit');
const submitBtn = document.getElementById('otpSubmitBtn');
const otpHidden = document.getElementById('otp-hidden');
let   countdownInterval = null, resendTimeout = null;

function openOtp() {
    document.getElementById('otpOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    const icon = document.getElementById('otpIcon');
    icon.classList.add('pulse');
    setTimeout(() => icon.classList.remove('pulse'), 2200);
    setTimeout(() => digits[0].focus(), 350);
    startCountdown(10 * 60);
    startResendCooldown(30);
}
function closeOtp() {
    document.getElementById('otpOverlay').classList.remove('open');
    document.body.style.overflow = '';
    clearInterval(countdownInterval); clearTimeout(resendTimeout);
    resetDigits();
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
function resetDigits() {
    digits.forEach(d => { d.value = ''; d.classList.remove('filled', 'shake'); });
    submitBtn.disabled = true; otpHidden.value = '';
}
function shakeDigits() {
    digits.forEach(d => { d.classList.remove('shake'); void d.offsetWidth; d.classList.add('shake'); });
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
function startResendCooldown(seconds) {
    const btn = document.getElementById('otpResendBtn'), timer = document.getElementById('otpResendTimer');
    btn.disabled = true; let remaining = seconds;
    const update = () => {
        timer.textContent = ` (${remaining}s)`;
        if (remaining <= 0) { btn.disabled = false; timer.textContent = ''; }
        remaining--;
    };
    update(); resendTimeout = setInterval(() => { if (remaining < 0) { clearInterval(resendTimeout); return; } update(); }, 1000);
}
function resendOtp() {
    const btn = document.getElementById('otpResendBtn');
    btn.disabled = true; document.getElementById('otpResendTimer').textContent = '';
    fetch('resend_otp.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.success) { resetDigits(); startCountdown(10 * 60); startResendCooldown(30); }
            else { alert(data.message || 'Could not resend OTP.'); btn.disabled = false; }
        }).catch(() => { alert('Network error.'); btn.disabled = false; });
}

<?php if ($show_otp_modal && $error): ?>
window.addEventListener('load', () => { openOtp(); shakeDigits(); });
<?php elseif ($show_otp_modal): ?>
window.addEventListener('load', () => openOtp());
<?php endif; ?>

/* ── Utils ── */
function changeLanguage(lang) { console.log('Language:', lang); }

/* ── Auto-hide alerts ── */
document.querySelectorAll('.login-alert').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity .5s';
        el.style.opacity = '0';
        setTimeout(() => el.style.display = 'none', 500);
    }, 4000);
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
</script>
</body>
</html>

<!-- TOPBAR -->
<!-- ══════════ TOPBAR ══════════ -->
            <div class="topbar">
                <div class="topbar-left">
                    <div class="topbar-lang">
                        <i class="fa-solid fa-globe"></i>
                        <select aria-label="Language">
                            <option value="en" selected>English</option>
                            <option value="fil">Filipino</option>
                        </select>
                    </div>
                </div>
                <div class="topbar-right">
                    <a href="https://www.google.com/maps/@10.714106,122.396162,16z" target="_blank" rel="noopener noreferrer" class="topbar-link">
                        <i class="fa-solid fa-location-dot"></i>
                    </a>
                    <span class="topbar-divider">|</span>
                    <a href="mailto:coravergelresort@gmail.com" class="topbar-link">
                        <i class="fa-regular fa-envelope"></i>
                    </a>
                </div>
            </div>
<!-- ══════════ NAVBAR ══════════ -->
<nav class="navbar">
 
    <!-- LEFT: hamburger (mobile) / nav links (desktop) -->
<div style="display:flex;align-items:center;padding:0;margin:0;background:transparent;overflow:hidden;">        <!-- Hamburger — only visible on mobile via CSS -->
        <button class="nav-hamburger" id="navHamburger" onclick="openDrawer()" aria-label="Open menu">
            <span></span><span></span><span></span>
        </button>
        <!-- Desktop links -->
        <div class="nav-links">
            <a href="about.php">ABOUT</a>
            <a href="rooms.php">ROOMS &amp; RATES</a>
            <a href="gallery.php">GALLERY</a>
            <a href="deals.php">DEALS</a>
            <a href="index.php#contact">CONTACT</a>
        </div>
    </div>
 
    <a href="../frontend/index.php" class="navbar-brand">
        <div class="custom-logo">
            <!-- swap src to your actual logo -->
            <img src="../assets/images/logo/cv_logo.png" alt="CoraVergel Resort">
        </div>
    </a>
 
    <div class="nav-login">
        <a href="../user/login.php" class="login-btn">
            <i class="fa-regular fa-user"></i>
        </a>
    </div>
 
</nav>
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

 
<!-- Slide Drawer -->
<div class="nav-drawer" id="navDrawer">

    <!-- Nav links -->
    <nav class="drawer-nav-links">
        <a href="about.php">About <i class="fa-solid fa-chevron-right"></i></a>
        <a href="rooms.php">Rooms &amp; Rates <i class="fa-solid fa-chevron-right"></i></a>
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