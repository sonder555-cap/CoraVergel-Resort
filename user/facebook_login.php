<?php
session_start();
require_once '../config/facebook_config.php';

/* CSRF protection: random token we check for on the way back in facebook_callback.php */
$state = bin2hex(random_bytes(16));
$_SESSION['facebook_oauth_state'] = $state;

$params = [
    'client_id'     => FACEBOOK_APP_ID,
    'redirect_uri'  => FACEBOOK_REDIRECT_URI,
    'state'         => $state,
    'scope'         => 'public_profile,email',
    'response_type' => 'code',
];

header('Location: https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query($params));
exit();
