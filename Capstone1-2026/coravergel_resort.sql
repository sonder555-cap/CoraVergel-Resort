-- schema.sql
CREATE DATABASE IF NOT EXISTS coravergel_resort;
USE coravergel_resort;

-- Users Table
    CREATE TABLE IF NOT EXISTS users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    -- Bookings Table
    CREATE TABLE IF NOT EXISTS bookings (
        booking_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        room_type VARCHAR(50) NOT NULL,
        check_in DATE NOT NULL,
        check_out DATE NOT NULL,
        guests INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id)
    );
    ---OTP
    CREATE TABLE IF NOT EXIST otp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    otp VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

    ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'user' NOT NULL;

    ALTER TABLE bookings ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER guests;

