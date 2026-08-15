
CREATE DATABASE IF NOT EXISTS coravergel_resort;
USE coravergel_resort;

-- ══════════════════════════════════════════════
-- USERS
-- ══════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS users (
    user_id    INT AUTO_INCREMENT PRIMARY KEY,
    full_name  VARCHAR(100) NOT NULL,
    email      VARCHAR(100) UNIQUE NOT NULL,
    password   VARCHAR(255) NOT NULL,
    phone      VARCHAR(20)  DEFAULT NULL,
    role       VARCHAR(20)  NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ══════════════════════════════════════════════
-- ROOMS
-- ══════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS rooms (
    room_id     INT AUTO_INCREMENT PRIMARY KEY,
    room_name   VARCHAR(100) NOT NULL UNIQUE,
    price       DECIMAL(10,2) NOT NULL,
    sale_price  DECIMAL(10,2) NULL,
    description TEXT NULL,
    image       VARCHAR(255) NULL,
    total_units INT NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ══════════════════════════════════════════════
-- BOOKINGS
-- ══════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS bookings (
    booking_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    room_type   VARCHAR(50) NOT NULL,
    check_in    DATE NOT NULL,
    check_out   DATE NOT NULL,
    guests      INT NOT NULL,
    status      VARCHAR(20) NOT NULL DEFAULT 'pending',
    total_price DECIMAL(10,2) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_room_dates (room_type, check_in, check_out)
);

-- ══════════════════════════════════════════════
-- OTP CODES (login verification)
-- ══════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS otp_codes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(255) NOT NULL,
    otp        VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ══════════════════════════════════════════════
-- PASSWORD RESETS
-- ══════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT          NOT NULL,
    token      VARCHAR(64)  NOT NULL,
    expires_at DATETIME     NOT NULL,
    used       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token),
    KEY idx_user (user_id),
    KEY idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ══════════════════════════════════════════════
-- SEED DATA — run once, only if rooms is empty
-- ══════════════════════════════════════════════
INSERT INTO rooms (room_name, price, total_units) VALUES
('Duplex Room',      3200, 5),
('Family Room',      6000, 3),
('Small Bahay Kubo', 2100, 4),
('Large Bahay Kubo', 3200, 3)
ON DUPLICATE KEY UPDATE room_name = room_name;

CREATE TABLE rate_limits (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    bucket_key  VARCHAR(191) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (bucket_key, attempted_at)
);