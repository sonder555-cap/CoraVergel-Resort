<?php
session_start();
require "../config/conn.php";
require "../config/security.php";
require "../config/mailer.php";

$error = "";
$show_otp_modal = false;

// ── LOGIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $email    = filter_var(sanitize($_POST['email']), FILTER_SANITIZE_EMAIL);
    $password = trim($_POST['password']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (empty($password)) {
        $error = "Password cannot be empty.";
    } else {
        $stmt = $conn->prepare("SELECT user_id, full_name, password, role FROM users WHERE email = ? AND role = 'admin'");
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

                $_SESSION['temp_admin_id']   = $id;
                $_SESSION['temp_admin_name'] = $full_name;
                $_SESSION['temp_admin_email']= $email;

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
                $error = "Incorrect password.";
            }
        } else {
            $error = "No admin account found with that email.";
        }
        $stmt->close();
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

            session_regenerate_id(true);
            $_SESSION['admin_id']   = $uid;
            $_SESSION['admin_name'] = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
            $_SESSION['admin_role'] = 'admin';
            unset($_SESSION['temp_admin_id'], $_SESSION['temp_admin_name'], $_SESSION['temp_admin_email']);

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
    <title>Login</title>
    <link rel="icon" href="../assets/images/logo/cv_logo.png" sizes="any">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
    --navy:#1a1a2e; --navy-deep:#0f0f1e; --gold:#c8a96e; --gold-dark:#a07840;
    --gray:#6b7280; --border:#e0d5c8;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{
    font-family:'DM Sans',sans-serif;
    min-height:100vh;
    display:flex; align-items:center; justify-content:center;
    background:
        radial-gradient(circle at 20% 20%, rgba(200,169,110,.08), transparent 45%),
        radial-gradient(circle at 80% 80%, rgba(200,169,110,.06), transparent 45%),
        var(--navy-deep);
    padding:20px;
}
.admin-login-card{
    width:100%; max-width:400px;
    background:#fff; border-radius:16px;
    padding:40px 36px 32px;
    box-shadow:0 24px 70px rgba(0,0,0,.4);
}
.al-badge{
    width:52px;height:52px;border-radius:14px;
    background:var(--navy); color:var(--gold);
    display:flex;align-items:center;justify-content:center;
    font-size:1.3rem; margin:0 auto 16px;
}
.al-title{
    font-family:'Cormorant Garamond',serif;
    font-size:1.5rem; font-weight:600; color:var(--navy);
    text-align:center; margin-bottom:4px;
}
.al-sub{
    text-align:center; font-size:.82rem; color:var(--gray);
    margin-bottom:26px;
}
.al-alert{
    display:flex; align-items:center; gap:8px;
    padding:10px 14px; border-radius:8px;
    font-size:.83rem; margin-bottom:16px;
    background:#fff5f5; border:1px solid #fed7d7; color:#c53030;
}
.lf-group{
    position:relative; display:flex; align-items:center;
    margin-bottom:18px; border:none; border-bottom:1.5px solid #e0e0e0;
    transition:border-color .2s;
}
.lf-group:focus-within{ border-color:var(--navy); }
.lf-icon{
    width:36px; flex-shrink:0; color:#000; font-size:.85rem;
    display:flex; align-items:center; justify-content:center;
}
.lf-group input{
    flex:1; padding:11px 8px 11px 0; border:none; background:transparent;
    font-family:'DM Sans',sans-serif; font-size:.9rem; color:var(--navy); outline:none;
}
.lf-group input::placeholder{ color:#bbb; }
.lf-eye{
    background:none; border:none; padding:0; margin:0; cursor:pointer;
    color:#aaa; display:none; align-items:center; justify-content:center;
    font-size:15px; line-height:1;
}
.lf-eye:hover{ color:var(--navy); }
.lf-submit{
    width:100%; padding:13px; background:var(--navy); color:#fff;
    border:none; border-radius:8px; font-family:'DM Sans',sans-serif;
    font-size:.92rem; font-weight:600; letter-spacing:.04em; cursor:pointer;
    display:flex; align-items:center; justify-content:center; gap:8px;
    transition:all .2s; margin-top:8px;
}
.lf-submit:hover{ background:#16213e; transform:translateY(-1px); box-shadow:0 4px 14px rgba(0,0,0,.2); }
.al-back{
    display:block; text-align:center; margin-top:22px;
    font-size:.81rem; color:#aaa; text-decoration:none;
}
.al-back:hover{ color:var(--navy); }

/* OTP modal — same pattern as the old shared login page */
.otp-overlay{
    display:none; position:fixed; inset:0; background:rgba(10,10,20,.6);
    z-index:500; align-items:center; justify-content:center; padding:20px;
}
.otp-overlay.open{ display:flex; }
.otp-box{
    background:#fff; border-radius:16px; width:100%; max-width:400px;
    padding:36px 32px 28px; text-align:center;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
}
.otp-icon{
    width:58px;height:58px;border-radius:50%;background:var(--navy);
    display:flex;align-items:center;justify-content:center;font-size:1.4rem;
    color:#fff; margin:0 auto 18px;
}
.otp-title{ font-family:'Cormorant Garamond',serif; font-size:1.5rem; font-weight:600; color:var(--navy); margin-bottom:6px; }
.otp-sub{ font-size:.84rem; color:#888; margin-bottom:8px; line-height:1.5; }
.otp-email-label{ display:inline-block; font-size:.84rem; font-weight:600; color:var(--navy); margin-bottom:22px; }
.otp-alert{
    display:flex; align-items:center; gap:8px; padding:10px 14px; border-radius:8px;
    background:#fff5f5; border:1px solid #fed7d7; color:#c53030; font-size:.82rem;
    margin-bottom:16px; text-align:left;
}
.otp-inputs{ display:flex; gap:8px; justify-content:center; margin-bottom:16px; }
.otp-digit{
    width:44px; height:52px; border-radius:10px; border:1.5px solid #e0e0e0;
    background:#f9f9f9; text-align:center; font-family:'Cormorant Garamond',serif;
    font-size:1.5rem; font-weight:600; color:var(--navy); outline:none; transition:all .18s;
}
.otp-digit:focus{ border-color:var(--navy); background:#fff; box-shadow:0 0 0 3px rgba(26,26,46,.07); }
.otp-digit.filled{ border-color:var(--navy); background:#f0f0f4; }
.otp-digit.shake{ animation:shake .4s ease; }
@keyframes shake{ 0%,100%{transform:translateX(0);} 20%{transform:translateX(-5px);} 40%{transform:translateX(5px);} 60%{transform:translateX(-4px);} 80%{transform:translateX(3px);} }
.otp-timer{ font-size:.82rem; color:#aaa; margin-bottom:18px; }
#otpCountdown{ font-weight:700; color:var(--navy); }
.otp-btn{
    width:100%; padding:13px; background:var(--navy); color:#fff; border:none;
    border-radius:10px; font-family:'DM Sans',sans-serif; font-size:.92rem; font-weight:600;
    letter-spacing:.04em; cursor:pointer; display:flex; align-items:center; justify-content:center;
    gap:8px; transition:all .2s; box-shadow:0 4px 14px rgba(26,26,46,.2); margin-bottom:14px;
}
.otp-btn:hover:not(:disabled){ background:#16213e; transform:translateY(-1px); }
.otp-btn:disabled{ opacity:.4; cursor:not-allowed; transform:none; }
.otp-resend{ font-size:.82rem; color:#aaa; margin-bottom:12px; }
#otpResendBtn{
    background:none; border:none; cursor:pointer; font-size:.82rem; font-family:'DM Sans',sans-serif;
    font-weight:600; color:var(--navy); text-decoration:underline; text-underline-offset:2px;
}
#otpResendBtn:disabled{ color:#bbb; cursor:not-allowed; text-decoration:none; }
.otp-back{
    background:none; border:none; cursor:pointer; font-size:.81rem; font-family:'DM Sans',sans-serif;
    color:#aaa; display:inline-flex; align-items:center; gap:5px;
}
.otp-back:hover{ color:var(--navy); }
</style>
</head>
<body>

<div class="admin-login-card">
    <div class="al-badge"><i class="fa-solid fa-user-shield"></i></div>
    <div class="al-title">Admin Login</div>
    <div class="al-sub">CoraVergel Resort — Staff Access</div>

    <?php if ($error && !$show_otp_modal): ?>
    <div class="al-alert"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="admin_login.php">
        <input type="hidden" name="action" value="login">
        <div class="lf-group">
            <span class="lf-icon"><i class="fa-regular fa-envelope"></i></span>
            <input type="email" name="email" placeholder="Admin Email" required autocomplete="email">
        </div>
        <div class="lf-group">
            <span class="lf-icon"><i class="fa-solid fa-lock"></i></span>
            <input type="password" name="password" id="pw-admin" placeholder="Password" required autocomplete="current-password">
            <button type="button" class="lf-eye" onclick="togglePw('pw-admin', this)" tabindex="-1">
                <i class="fa-regular fa-eye"></i>
            </button>
        </div>
        <button type="submit" class="lf-submit">
            <i class="fa-solid fa-right-to-bracket"></i> Sign In
        </button>
    </form>

    <a href="../frontend/index.php" class="al-back">
        <i class="fa-solid fa-arrow-left"></i> Back to website
    </a>
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
        <button class="otp-back" onclick="location.reload()">
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
</script>
</body>
</html>