<?php

function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function getEncryptionKey() {
    // Store this in an environment variable, NOT in this file.
    // Example: putenv() in a local .env loader, or set it in your
    // hosting control panel / php.ini as an env var.
    $key = getenv('CORAVERGEL_ENC_KEY');

    if (!$key) {
        // Fallback for local dev only — replace with your own random
        // 32-byte key generated once via: bin2hex(random_bytes(32))
        throw new Exception('Encryption key not set. Define CORAVERGEL_ENC_KEY in your environment.');
    }

    // Key must be exactly 32 raw bytes for AES-256
    return hash('sha256', $key, true);
}

function encryptData($data) {
    $key = getEncryptionKey();
    $iv = random_bytes(16); // random IV every time — this is the critical fix
    $ciphertext = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($ciphertext === false) {
        throw new Exception('Encryption failed.');
    }

    // Prepend IV so we can extract it again on decrypt; base64 for safe DB storage
    return base64_encode($iv . $ciphertext);
}

function decryptData($encoded) {
    $key = getEncryptionKey();
    $raw = base64_decode($encoded);

    $iv = substr($raw, 0, 16);
    $ciphertext = substr($raw, 16);

    $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($plaintext === false) {
        throw new Exception('Decryption failed.');
    }

    return $plaintext;
} 