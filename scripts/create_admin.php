<?php
require "../config/conn.php";
require "../config/security.php";

$full_name = "Admin";
$username  = "admin@coravergel.ph";
$email     = "lexnnder15@gmail.com";
$password  = "12345678";

$hashed = password_hash($password, PASSWORD_DEFAULT);

// Check if this admin already exists (by username OR email)
$check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? OR email = ?");
$check->bind_param("ss", $username, $email);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo "⚠️ Admin account already exists!";
} else {
    $stmt = $conn->prepare("INSERT INTO admins (full_name, username, email, password) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $full_name, $username, $email, $hashed);

    if ($stmt->execute()) {
        echo "✅ Admin account created successfully!<br>";
        echo "👤 Username: " . $username . "<br>";
        echo "📧 Email: " . $email . "<br>";
        echo "🔑 Password: " . $password . "<br>";
        echo "<br><strong style='color:red;'>⚠️ DELETE this file immediately after use!</strong>";
    } else {
        echo "❌ Error: " . $stmt->error;
    }
    $stmt->close();
}
$check->close();
$conn->close();
?>