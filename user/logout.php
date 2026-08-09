<?php
session_start();
require_once '../config/conn.php';
require_once '../config/remember_me.php';

clearRememberMeCookie($conn);

session_unset();
session_destroy();
header("Location: ../user/login.php");
exit;
?>