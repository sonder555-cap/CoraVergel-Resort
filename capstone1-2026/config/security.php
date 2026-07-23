<?php

$key = "12345678901234567890123456789012";

function sanitize($data){
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function encryptData($data, $key){
    $iv = substr($key, 0, 16);
    return openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
}

function decryptData($data, $key){
    $iv = substr($key, 0, 16);
    return openssl_decrypt($data, 'AES-256-CBC', $key, 0, $iv);
}
?>