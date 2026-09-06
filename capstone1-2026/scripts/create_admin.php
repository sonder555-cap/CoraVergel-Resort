<?php
// One-time setup script for creating the first admin account.
//
// Safety rails added:
//   1. Refuses to run unless CORAVERGEL_ALLOW_ADMIN_SETUP=1 is set in the
//      environment, so it can't be triggered by an accidental request to
//      this URL on a live server.
//   2. Never runs over HTTP — command line (php scripts/create_admin.php)
//      only, so the generated password never touches a browser, a web
//      server access log, or a reverse-proxy log.
//   3. Generates a random password instead of using a hardcoded one, and
//      prints it once so you can copy it — it is not saved anywhere.
//   4. Username/email/full name come from the environment (or CLI args) so
//      no real account info ever lives in source control either.
//
// Usage (from the project root):
//   CORAVERGEL_ALLOW_ADMIN_SETUP=1 php scripts/create_admin.php "Admin Name" admin_username admin@example.com
//
// Delete this file (or at least move it outside the web root) once you've
// created your admin account — it should never be reachable at a URL.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("This script can only be run from the command line, not through a browser.\n");
}

if (getenv('CORAVERGEL_ALLOW_ADMIN_SETUP') !== '1') {
    die("Refusing to run: set CORAVERGEL_ALLOW_ADMIN_SETUP=1 to confirm you intend to create an admin account.\n");
}

require __DIR__ . "/../config/conn.php";
require __DIR__ . "/../config/security.php";

$full_name = $argv[1] ?? getenv('CORAVERGEL_ADMIN_NAME') ?: null;
$username  = $argv[2] ?? getenv('CORAVERGEL_ADMIN_USERNAME') ?: null;
$email     = $argv[3] ?? getenv('CORAVERGEL_ADMIN_EMAIL') ?: null;

if (!$full_name || !$username || !$email) {
    die("Usage: php scripts/create_admin.php \"Full Name\" username email@example.com\n" .
        "       (or set CORAVERGEL_ADMIN_NAME / CORAVERGEL_ADMIN_USERNAME / CORAVERGEL_ADMIN_EMAIL)\n");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Error: \"$email\" is not a valid email address.\n");
}

// Random, printed once — never hardcoded, never stored anywhere else.
$password = bin2hex(random_bytes(9)); // 18-char random password
$hashed = password_hash($password, PASSWORD_DEFAULT);

$check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? OR email = ?");
$check->bind_param("ss", $username, $email);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    fwrite(STDERR, "Admin account with that username or email already exists.\n");
    $check->close();
    $conn->close();
    exit(1);
}
$check->close();

$stmt = $conn->prepare("INSERT INTO admins (full_name, username, email, password) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $full_name, $username, $email, $hashed);

if ($stmt->execute()) {
    echo "Admin account created.\n";
    echo "  Username: $username\n";
    echo "  Email:    $email\n";
    echo "  Password: $password   (shown once — save it in a password manager now)\n";
    echo "\nRemember to delete or move this script outside the web root now that it's done its job.\n";
} else {
    fwrite(STDERR, "Error creating admin: " . $stmt->error . "\n");
    exit(1);
}
$stmt->close();
$conn->close();
