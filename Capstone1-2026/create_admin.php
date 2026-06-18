<?php
require "./config/conn.php";
require "./config/security.php";

$full_name = "Admin";
$email     = "admin@coravergel.ph";
$password  = "12345678";
$role      = "admin";

$hashed = password_hash($password, PASSWORD_DEFAULT);

// Check if admin already exists
$check = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo "⚠️ Admin account already exists!";
} else {
    $stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $full_name, $email, $hashed, $role);

    if ($stmt->execute()) {
        echo "✅ Admin account created successfully!<br>";
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