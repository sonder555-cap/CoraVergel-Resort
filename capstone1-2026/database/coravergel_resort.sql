
CREATE DATABASE IF NOT EXISTS coravergel_resort;
USE coravergel_resort;

-- ══════════════════════════════════════════════
-- USERS
-- ══════════════════════════════════════════════

-- ══════════════════════════════════════════════
-- ROOMS
-- ══════════════════════════════════════════════
CREATE TABLE rooms (
    room_id     INT AUTO_INCREMENT PRIMARY KEY,
    room_name   VARCHAR(100) NOT NULL UNIQUE,
    price       DECIMAL(10,2) NOT NULL,
    sale_price  DECIMAL(10,2) NULL,
    description TEXT NULL,
    image       VARCHAR(255) NULL,
    total_units INT NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
    ALTER TABLE rooms ADD COLUMN capacity INT NOT NULL DEFAULT 1 AFTER room_id;


-- ══════════════════════════════════════════════
-- BOOKINGS
-- ══════════════════════════════════════════════
CREATE TABLE bookings (
    booking_id      INT AUTO_INCREMENT PRIMARY KEY,
    room_type       VARCHAR(100) NOT NULL,
    check_in        DATE NOT NULL,
    check_out       DATE NOT NULL,
    guests          INT NOT NULL DEFAULT 1,
    total_price     DECIMAL(10,2) DEFAULT 0,
    guest_name      VARCHAR(150) NOT NULL,
    guest_email     VARCHAR(150) NOT NULL,
    id_photo VARCHAR(255) NULL
    id_type         VARCHAR(50)  NOT NULL,
    id_number       VARCHAR(100) NOT NULL,
    contact_number  VARCHAR(30)  NOT NULL,
    status          ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    selector VARCHAR(18) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_selector (selector),
    KEY idx_admin_id (admin_id),
    CONSTRAINT fk_remember_admin FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE admins (
    admin_id   INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    admin_name VARCHAR(100) NOT NULL,
    email      VARCHAR(100) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    role       VARCHAR(20) NOT NULL DEFAULT 'admin',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(100) NOT NULL,
    attempts     INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ip_address   VARCHAR(45) NULL,
    UNIQUE KEY uniq_email (email)
);
CREATE TABLE login_history (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    admin_id     INT NOT NULL,
    username     VARCHAR(50) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    user_agent   VARCHAR(255) NULL,
    login_method VARCHAR(20) NOT NULL,
    logged_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE
);
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

-- ══════════════════════════════════════════════
-- SEED DATA — run once, only if rooms is empty
-- ══════════════════════════════════════════════
INSERT INTO rooms (room_name, price, total_units) VALUES
('Duplex Room',      3200, 5),
('Family Room',      6000, 3),
('Small Bahay Kubo', 2100, 4),
('Large Bahay Kubo', 3200, 3)
ON DUPLICATE KEY UPDATE room_name = room_name;