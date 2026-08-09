<?php
session_start();
require_once '../config/conn.php';
require_once '../config/security.php';
require_once '../config/facebook_config.php';

/* ── Basic sanity + CSRF state check ── */
if (
    !isset($_GET['code'], $_GET['state']) ||
    !isset($_SESSION['facebook_oauth_state']) ||
    !hash_equals($_SESSION['facebook_oauth_state'], $_GET['state'])
) {
    unset($_SESSION['facebook_oauth_state']);
    header('Location: login.php?facebook_error=1');
    exit();
}
unset($_SESSION['facebook_oauth_state']);

/* ── Step 1: exchange the authorization code for an access token ── */
$tokenParams = [
    'client_id'     => FACEBOOK_APP_ID,
    'client_secret' => FACEBOOK_APP_SECRET,
    'redirect_uri'  => FACEBOOK_REDIRECT_URI,
    'code'          => $_GET['code'],
];

$ch = curl_init('https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query($tokenParams));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$tokenResponse = curl_exec($ch);
$tokenCurlErr  = curl_error($ch);
curl_close($ch);

$tokenData = json_decode($tokenResponse ?: '', true);
if ($tokenCurlErr || empty($tokenData['access_token'])) {
    header('Location: login.php?facebook_error=1');
    exit();
}

/* ── Step 2: use the access token to fetch the user's Facebook profile ── */
$profileParams = [
    'fields'       => 'id,name,email',
    'access_token' => $tokenData['access_token'],
];
$ch = curl_init('https://graph.facebook.com/me?' . http_build_query($profileParams));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$profileResponse = curl_exec($ch);
curl_close($ch);

$profile = json_decode($profileResponse ?: '', true);

/* Some Facebook accounts have no verified email, or the person can
   decline to share it. We need an email to match/create the account,
   so if it's missing we bounce back with a clear message instead of
   silently failing. */
if (empty($profile['email'])) {
    header('Location: login.php?facebook_error=noemail');
    exit();
}

$email     = $profile['email'];
$full_name = !empty($profile['name']) ? $profile['name'] : explode('@', $email)[0];
$full_name = htmlspecialchars(strip_tags($full_name), ENT_QUOTES, 'UTF-8');

/* ── Step 3: find the matching account, or create one on first sign-in ── */
$stmt = $conn->prepare("SELECT user_id, full_name, role FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 1) {
    $stmt->bind_result($uid, $db_name, $role);
    $stmt->fetch();
    $stmt->close();
} else {
    $stmt->close();

    /* Facebook accounts don't set a local password — store a random,
       unusable hash so the column constraint is satisfied. */
    $random_password = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);

    $ins = $conn->prepare("INSERT INTO users (full_name, email, phone, password) VALUES (?, ?, '', ?)");
    $ins->bind_param("sss", $full_name, $email, $random_password);

    if (!$ins->execute()) {
        $ins->close();
        header('Location: login.php?facebook_error=1');
        exit();
    }
    $uid = $ins->insert_id;
    $ins->close();

    $db_name = $full_name;
    $role    = 'user'; // matches the table's default for new sign-ups
}

/* ── Step 4: log them in (skips the OTP step — Facebook already verified the email) ── */
session_regenerate_id(true);
$_SESSION['user_id']   = $uid;
$_SESSION['full_name'] = htmlspecialchars($db_name, ENT_QUOTES, 'UTF-8');
$_SESSION['role']      = $role;

if ($role === 'admin') {
    $_SESSION['admin_id']   = $uid;
    $_SESSION['admin_name'] = htmlspecialchars($db_name, ENT_QUOTES, 'UTF-8');
    $_SESSION['admin_role'] = $role;
    header('Location: ../admin/admin_dashboard.php');
} else {
    header('Location: ../user/dashboard.php');
}
exit();
