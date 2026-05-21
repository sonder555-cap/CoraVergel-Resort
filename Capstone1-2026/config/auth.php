<?php

// function requireLogin() {
//     if (!isset($_SESSION['user_id'])) {
//         header("Location: ../user/login.php");
//         exit();
//     }
// }

function requireAdmin() {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header("Location: ../user/login.php");
        exit();
    }
}

function requireUser() {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
        header("Location: ../user/login.php");
        exit();
    }
}

// function redirectIfLoggedIn() {
//     if (isset($_SESSION['user_id'])) {
//         if ($_SESSION['role'] === 'admin') {
//             header("Location: ../admin/admin_dashboard.php");
//         } else {
//             header("Location: ../user/dashboard.php");
//         }
//         exit();
//     }
// }
// 
$timeout = 600;

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: ../user/login.php");
    exit();
}

// $_SESSION['last_activity'] = time();
?>