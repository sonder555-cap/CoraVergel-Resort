-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 06, 2026 at 02:53 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `coravergel_resort`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `cv_add_column` (IN `p_table` VARCHAR(64), IN `p_column` VARCHAR(64), IN `p_definition` TEXT)   BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @cv_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE cv_stmt FROM @cv_sql;
        EXECUTE cv_stmt;
        DEALLOCATE PREPARE cv_stmt;
    END IF;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `otp_email` varchar(255) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `otp_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notif_last_read` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`admin_id`, `full_name`, `username`, `email`, `otp_email`, `two_factor_enabled`, `password`, `role`, `created_at`, `otp_enabled`, `notif_last_read`) VALUES
(1, 'Admin', 'admin@coravergel.ph', 'lexnnder15@gmail.com', NULL, 1, '$2y$10$73dzCbvmo1f8VoT0eSkhiOt/eqGCfiEbSfgu.QXquHUl7KqYnz0Z2', 'admin', '2026-08-18 23:21:42', 1, '2026-09-06 20:11:38');

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `booking_id` int(11) NOT NULL,
  `booking_ref` char(4) DEFAULT NULL,
  `room_type` varchar(100) NOT NULL,
  `check_in` date NOT NULL,
  `check_out` date NOT NULL,
  `guests` int(11) NOT NULL DEFAULT 1,
  `total_price` decimal(10,2) DEFAULT 0.00,
  `guest_name` varchar(150) NOT NULL,
  `guest_email` varchar(150) NOT NULL,
  `id_type` varchar(50) NOT NULL,
  `id_photo` varchar(255) DEFAULT NULL,
  `id_number` varchar(100) NOT NULL,
  `contact_number` varchar(30) NOT NULL,
  `payment_method` varchar(20) NOT NULL DEFAULT 'Cash',
  `payment_reference` varchar(100) DEFAULT NULL,
  `payment_receipt` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `confirmed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `adults` int(11) NOT NULL DEFAULT 0,
  `children` int(11) NOT NULL DEFAULT 0,
  `room_count` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`booking_id`, `booking_ref`, `room_type`, `check_in`, `check_out`, `guests`, `total_price`, `guest_name`, `guest_email`, `id_type`, `id_photo`, `id_number`, `contact_number`, `payment_method`, `payment_reference`, `payment_receipt`, `status`, `created_at`, `confirmed_at`, `cancelled_at`, `adults`, `children`, `room_count`) VALUES
(20, NULL, 'Duplex Room', '2026-08-23', '2026-08-24', 1, 3200.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Driver&#039;s License', 'id_6a8a970c1c5268.38312314.png', '', '3456789', 'Cash', NULL, NULL, 'confirmed', '2026-08-23 06:45:32', NULL, NULL, 1, 0, 1),
(21, NULL, 'Family Room', '2026-08-24', '2026-08-27', 1, 19200.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a8ab4c4b98977.43569271.png', '', '0951789023', 'Cash', NULL, NULL, 'confirmed', '2026-08-23 08:52:20', NULL, NULL, 1, 0, 1),
(22, NULL, 'Family Room', '2026-08-24', '2026-08-27', 1, 19200.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a8ab6581c3659.88819102.png', '', '0951789023', 'Cash', NULL, NULL, 'confirmed', '2026-08-23 08:59:04', '2026-08-23 17:07:14', NULL, 1, 0, 1),
(23, NULL, 'Duplex Room', '2026-08-25', '2026-08-28', 1, 9600.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Driver&#039;s License', 'id_6a8b0816533707.94380385.jpeg', '', '34567890', 'Cash', NULL, NULL, 'confirmed', '2026-08-23 14:47:50', '2026-08-23 22:47:58', NULL, 1, 0, 1),
(24, NULL, 'Duplex Room', '2026-08-23', '2026-08-26', 1, 9600.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a8b0896bd3da8.62778333.jpg', '', '095178235981', 'Cash', NULL, NULL, 'confirmed', '2026-08-23 14:49:58', '2026-08-23 22:50:14', NULL, 1, 0, 1),
(25, NULL, 'Family Room', '2026-08-25', '2026-08-28', 1, 19200.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a8b08cd05ea31.42749393.jpg', '', '3456789', 'Cash', NULL, NULL, 'confirmed', '2026-08-23 14:50:53', '2026-08-23 22:51:09', NULL, 1, 0, 1),
(26, NULL, 'Duplex Room', '2026-08-28', '2026-08-31', 1, 9600.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a8b27b61a4fb5.26481015.png', '', '095178235123', 'Cash', '', NULL, 'cancelled', '2026-08-23 17:02:46', NULL, NULL, 1, 0, 1),
(27, NULL, 'Duplex Room', '2026-08-28', '2026-08-31', 1, 9600.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a8b2b64a70104.69367636.png', '', '095178235123', 'Cash', '', NULL, 'cancelled', '2026-08-23 17:18:28', NULL, NULL, 1, 0, 1),
(28, NULL, 'Duplex Room', '2026-08-25', '2026-08-28', 3, 9600.00, '1231231231', '123123123@gmail.com', 'Government ID', 'id_6a8b2b949a4197.99631270.png', '', '123123123', 'Cash', '', NULL, 'pending', '2026-08-23 17:19:16', NULL, NULL, 2, 1, 1),
(29, NULL, 'Duplex Room', '2026-08-25', '2026-08-28', 3, 9600.00, '1231231231', '123123123@gmail.com', 'Government ID', 'id_6a8b2bc625db11.88846625.png', '', '123123123', 'Cash', '', NULL, 'pending', '2026-08-23 17:20:06', NULL, NULL, 2, 1, 1),
(30, NULL, 'Duplex Room', '2026-08-25', '2026-08-28', 5, 9600.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Driver&#039;s License', 'id_6a8b2bd625b685.33065894.jpg', '', '0951789023', 'Cash', '', NULL, 'confirmed', '2026-08-23 17:20:22', '2026-08-24 06:45:12', NULL, 3, 2, 1),
(31, NULL, 'Duplex Room', '2026-08-25', '2026-08-28', 5, 9600.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Driver&#039;s License', 'id_6a8b77cd869520.38149719.jpg', '', '0951789023', 'Cash', '', NULL, 'pending', '2026-08-23 22:44:29', NULL, NULL, 3, 2, 1),
(32, NULL, 'Duplex Room', '2026-08-25', '2026-08-28', 5, 9600.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Driver&#039;s License', 'id_6a8b78e02e1632.21309762.jpg', '', '0951789023', 'Cash', '', NULL, 'confirmed', '2026-08-23 22:49:04', '2026-08-25 13:22:53', NULL, 3, 2, 1),
(33, NULL, 'Duplex Room', '2026-08-25', '2026-08-28', 5, 9600.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Driver&#039;s License', 'id_6a8b78f6d12ff8.83115003.jpg', '', '0951789023', 'Cash', '', NULL, 'confirmed', '2026-08-23 22:49:26', '2026-08-25 13:22:48', NULL, 3, 2, 1),
(34, NULL, 'Family Room', '2026-08-25', '2026-08-28', 5, 19200.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a8d513b727a83.48938239.jpg', '', '0951789023', 'E-wallet', '', 'receipt_6a8d513b740ad9.57575781.jpeg', 'pending', '2026-08-25 08:24:27', NULL, NULL, 3, 2, 1),
(35, '5323', 'Duplex Room', '2026-08-26', '2026-08-28', 1, 6400.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'School ID', 'id_6a8e30dae78925.59809813.jpg', '', '0951789023', 'E-wallet', '', 'receipt_6a8e30dae86bb5.51557178.jpg', 'cancelled', '2026-08-26 00:18:34', NULL, NULL, 1, 0, 1),
(36, '6313', 'Duplex Room', '2026-08-26', '2026-08-28', 1, 6400.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'School ID', 'id_6a8e357c17ea36.86871081.jpg', '', '0951789023', 'E-wallet', '', 'receipt_6a8e357c19c8f2.02567519.jpg', 'confirmed', '2026-08-26 00:38:20', '2026-08-26 08:55:41', NULL, 1, 0, 1),
(37, '2005', 'Duplex Room', '2026-08-26', '2026-08-29', 1, 9600.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Driver&#039;s License', 'id_6a8e3984490770.51258226.jpg', '', '0951789023', 'E-wallet', '', 'receipt_6a8e39844a0541.42824332.jpg', 'pending', '2026-08-26 00:55:32', NULL, NULL, 1, 0, 1),
(38, '9601', 'Duplex Room', '2026-08-30', '2026-08-31', 2, 3200.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a93ad62acc125.17373942.jpg', '', '09517823598', 'E-wallet', '', 'receipt_6a93ad62ada9c5.19756834.jpg', 'confirmed', '2026-08-30 04:11:14', '2026-08-30 15:16:49', NULL, 1, 1, 1),
(39, '9196', 'Large Bahay Kubo', '2026-09-05', '2026-09-12', 3, 44800.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a9aabb00af087.81508787.png', '', '12312312312', 'E-wallet', '', 'receipt_6a9aabb00b99a8.32959865.png', 'cancelled', '2026-09-04 11:29:52', NULL, NULL, 2, 1, 2),
(40, '8697', 'Large Bahay Kubo', '2026-09-05', '2026-09-12', 3, 44800.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a9aada085daa2.23949443.png', '', '12312312312', 'E-wallet', '', 'receipt_6a9aada0866c59.83024334.png', 'cancelled', '2026-09-04 11:38:08', NULL, NULL, 2, 1, 2),
(41, '7491', 'Large Bahay Kubo', '2026-09-05', '2026-09-12', 3, 44800.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a9aada3812724.61317383.png', '', '12312312312', 'E-wallet', '', 'receipt_6a9aada3821e34.85857722.png', 'cancelled', '2026-09-04 11:38:11', NULL, NULL, 2, 1, 2),
(42, '4108', 'Large Bahay Kubo', '2026-09-05', '2026-09-12', 3, 44800.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a9aadd6f1c104.51007021.png', '', '12312312312', 'E-wallet', '', 'receipt_6a9aadd6f274a1.22404324.png', 'cancelled', '2026-09-04 11:39:02', NULL, NULL, 2, 1, 2),
(43, '8429', 'Large Bahay Kubo', '2026-09-05', '2026-09-12', 3, 44800.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a9ab020bf1dc1.79372107.png', '', '12312312312', 'E-wallet', '', 'receipt_6a9ab020bf9b51.72758302.png', 'cancelled', '2026-09-04 11:48:48', NULL, NULL, 2, 1, 2),
(44, '2455', 'Duplex Room', '2026-09-23', '2026-09-24', 1, 3200.00, 'Alexander Paller', 'lexnnder15@gmail.com', 'Government ID', 'id_6a9acf5b5f7024.30582665.png', '', '09517823598', 'E-wallet', '', 'receipt_6a9acf5b604355.96098430.png', 'cancelled', '2026-09-04 14:02:03', '2026-09-05 11:12:51', '2026-09-06 19:24:24', 1, 0, 1);

-- --------------------------------------------------------

--
-- Table structure for table `email_settings`
--

CREATE TABLE `email_settings` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `confirmation_emails` tinyint(1) NOT NULL DEFAULT 1,
  `cancellation_emails` tinyint(1) NOT NULL DEFAULT 1,
  `admin_alerts` tinyint(1) NOT NULL DEFAULT 1,
  `sender_name` varchar(100) NOT NULL DEFAULT 'CoraVergel Resort',
  `sender_email` varchar(150) NOT NULL DEFAULT 'coravergelresort@gmail.com'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `email_settings`
--

INSERT INTO `email_settings` (`id`, `confirmation_emails`, `cancellation_emails`, `admin_alerts`, `sender_name`, `sender_email`) VALUES
(1, 1, 1, 1, 'CoraVergel Resort', 'coravergelresort@gmail.com');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `attempt_count` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_attempt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `email`, `attempt_count`, `locked_until`, `last_attempt`, `ip_address`, `attempts`) VALUES
(6, 'lexnnder15@gmail.com', 0, NULL, '2026-09-04 11:34:52', NULL, 3);

-- --------------------------------------------------------

--
-- Table structure for table `login_history`
--

CREATE TABLE `login_history` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `login_method` varchar(20) NOT NULL,
  `logged_in_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_history`
--

INSERT INTO `login_history` (`id`, `admin_id`, `username`, `ip_address`, `user_agent`, `login_method`, `logged_in_at`) VALUES
(1, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'otp', '2026-08-19 03:22:18'),
(2, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-19 03:27:09'),
(3, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-19 08:11:06'),
(4, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-19 08:11:32'),
(5, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-19 08:40:42'),
(6, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-19 08:40:42'),
(7, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-20 00:47:03'),
(8, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-20 00:50:31'),
(9, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-20 04:13:38'),
(10, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-20 04:13:38'),
(11, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-25 05:21:39'),
(12, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-26 01:29:11'),
(13, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'otp', '2026-08-30 01:15:35'),
(14, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-30 01:23:51'),
(15, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-30 01:26:33'),
(16, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-30 01:27:22'),
(17, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-30 01:27:31'),
(18, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-30 01:27:31'),
(19, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-30 01:27:31'),
(20, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-30 01:27:50'),
(21, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-30 11:07:52'),
(22, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36', 'remembered', '2026-08-30 12:14:47'),
(23, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'remembered', '2026-09-03 10:59:39'),
(24, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'remembered', '2026-09-04 01:45:05'),
(25, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'remembered', '2026-09-04 01:46:00'),
(26, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'remembered', '2026-09-04 05:44:12'),
(27, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'otp', '2026-09-04 07:49:10'),
(28, 1, 'admin@coravergel.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'otp', '2026-09-04 11:35:43'),
(29, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'otp', '2026-09-06 03:49:39'),
(30, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'remembered', '2026-09-06 04:15:19'),
(31, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'remembered', '2026-09-06 04:35:55'),
(32, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'remembered', '2026-09-06 04:51:24'),
(33, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'remembered', '2026-09-06 04:51:43'),
(34, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'remembered', '2026-09-06 10:35:41'),
(35, 1, 'admin@coravergel.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'remembered', '2026-09-06 10:42:53');

-- --------------------------------------------------------

--
-- Table structure for table `otp_codes`
--

CREATE TABLE `otp_codes` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `otp` varchar(10) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `admin_id`, `email`, `token_hash`, `expires_at`, `created_at`) VALUES
(6, 1, 'lexnnder15@gmail.com', '025b8e2456396706116aabfe830ad2d882a8e2b549c4dd3f825a786417649114', '2026-09-06 07:02:38', '2026-09-06 04:32:38');

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `selector` varchar(18) NOT NULL,
  `validator_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `remember_tokens`
--

INSERT INTO `remember_tokens` (`id`, `admin_id`, `selector`, `validator_hash`, `expires_at`, `created_at`) VALUES
(4, 1, '2afcc68c575417a4ef', '6873b2e3dadc261af3361eb29377a21d9513138797d3f0187dbae9e7c0d1e7e5', '2026-10-06 05:49:39', '2026-09-06 03:49:39');

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `room_id` int(11) NOT NULL,
  `capacity` int(11) NOT NULL DEFAULT 1,
  `badge` varchar(50) DEFAULT NULL,
  `badge_updated_at` datetime DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `room_name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `total_units` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `gallery` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`room_id`, `capacity`, `badge`, `badge_updated_at`, `tags`, `room_name`, `price`, `sale_price`, `description`, `image`, `total_units`, `created_at`, `gallery`) VALUES
(1, 4, 'Maintenance', NULL, 'Free Entrance, Air-condition room, 2 Towels, Free Breakfast for 2', 'Duplex Room', 3200.00, NULL, '', 'room_6a9d534e1e6ec.jpg', 5, '2026-08-19 05:09:20', ''),
(2, 10, 'Available', NULL, 'Aircon room with own CR, Mini refrigerator, 7 towel', 'Family Room', 6400.00, NULL, '', 'room_6a9d535492e11.jpg', 4, '2026-08-19 11:28:58', ''),
(3, 6, 'Available', NULL, 'Fan Room, Common CR', 'Small Bahay Kubo', 2100.00, NULL, '', 'room_6a9d53735fec0.jpg', 4, '2026-08-19 11:36:47', ''),
(4, 10, 'Available', NULL, 'Fan Room, Common CR', 'Large Bahay Kubo', 3200.00, NULL, '', 'room_6a9d5367a0da2.jpg', 2, '2026-08-19 11:38:05', '');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD UNIQUE KEY `booking_ref` (`booking_ref`),
  ADD UNIQUE KEY `uq_booking_ref` (`booking_ref`);

--
-- Indexes for table `email_settings`
--
ALTER TABLE `email_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_email` (`email`);

--
-- Indexes for table `login_history`
--
ALTER TABLE `login_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `otp_codes`
--
ALTER TABLE `otp_codes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_selector` (`selector`),
  ADD KEY `idx_admin_id` (`admin_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`room_id`),
  ADD UNIQUE KEY `room_name` (`room_name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `booking_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `login_history`
--
ALTER TABLE `login_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `otp_codes`
--
ALTER TABLE `otp_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `room_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `login_history`
--
ALTER TABLE `login_history`
  ADD CONSTRAINT `login_history_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE;

--
-- Constraints for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD CONSTRAINT `fk_remember_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
