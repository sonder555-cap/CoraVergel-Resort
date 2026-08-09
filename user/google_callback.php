<?php
// user/google_callback.php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';
require_once '../config/google_config.php';

function fail($msg) {
    // Send them back to the login page with a friendly error.
    header('Location: login.php?google_error=' . urlencode($msg));
    exit;
}

// ── Basic checks ──
if (isset($_GET['error'])) {
    fail('Google sign-in was cancelled.');
}
if (empty($_GET['code']) || empty($_GET['state'])) {
    fail('Invalid Google sign-in request.');
}
if (!isset($_SESSION['google_oauth_state']) || $_GET['state'] !== $_SESSION['google_oauth_state']) {
    fail('Sign-in session expired. Please try again.');
}
unset($_SESSION['google_oauth_state']);

// ── Step 1: exchange the authorization code for an access token ──
$token_response = null;
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $_GET['code'],
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]),
]);
$token_response = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($token_response, true);
if (empty($token_data['access_token'])) {
    fail('Could not verify your Google account. Please try again.');
}

// ── Step 2: use the access token to fetch the user's profile ──
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token_data['access_token']],
]);
$profile_response = curl_exec($ch);
curl_close($ch);

$profile = json_decode($profile_response, true);
if (empty($profile['email'])) {
    fail('Could not retrieve your Google account details.');
}

$google_email = filter_var($profile['email'], FILTER_SANITIZE_EMAIL);
$google_name  = htmlspecialchars($profile['name'] ?? explode('@', $google_email)[0], ENT_QUOTES, 'UTF-8');

if (empty($profile['email_verified'])) {
    fail('Your Google email is not verified. Please verify it with Google first.');
}

// ── Step 3: find or create the matching account ──
$stmt = $conn->prepare("SELECT user_id, full_name, role FROM users WHERE email = ?");
$stmt->bind_param("s", $google_email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 1) {
    // Existing account — log them straight in (Google already verified this email, no OTP needed)
    $stmt->bind_result($uid, $full_name, $role);
    $stmt->fetch();
    $stmt->close();
} else {
    $stmt->close();
    // New account — auto-register. Password column still requires a value,
    // so store a random unusable hash; they can set a real password later via "Forgot password".
    $random_password_hash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
    $ins = $conn->prepare("INSERT INTO users (full_name, email, phone, password) VALUES (?, ?, '', ?)");
    $ins->bind_param("sss", $google_name, $google_email, $random_password_hash);
    $ins->execute();
    $uid = $ins->insert_id;
    $ins->close();
    $full_name = $google_name;
    $role = 'user';
}

// ── Step 4: log them in ──
session_regenerate_id(true);
$_SESSION['user_id']   = $uid;
$_SESSION['full_name'] = $full_name;
$_SESSION['role']      = $role;

if ($role === 'admin') {
    $_SESSION['admin_id']   = $uid;
    $_SESSION['admin_name'] = $full_name;
    $_SESSION['admin_role'] = $role;
    header("Location: ../admin/admin_dashboard.php");
} else {
    header("Location: ../user/dashboard.php");
}
exit;