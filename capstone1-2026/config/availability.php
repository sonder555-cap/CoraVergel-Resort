<?php
// config/availability.php
// Shared logic for room inventory + double-booking prevention.
// require this AFTER config/conn.php in any page that books or manages rooms.

/**
 * Get full info for a room type. Returns null if room not found.
 */
function getRoomInfo($conn, $room_name) {
    $stmt = $conn->prepare("SELECT room_id, room_name, price, total_units, description, image FROM rooms WHERE room_name = ?");
    $stmt->bind_param("s", $room_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Get all rooms as an associative array keyed by room_name.
 * Includes description/image now, so pages can pull real content from the DB.
 */
function getAllRooms($conn) {
    $rooms = [];
    $result = $conn->query("SELECT room_id, room_name, price, total_units, description, image FROM rooms ORDER BY room_name");
    while ($row = $result->fetch_assoc()) {
        $rooms[$row['room_name']] = $row;
    }
    return $rooms;
}

/**
 * Same overlap logic as countOverlappingBookings(), but filtered to
 * a single status ('pending' or 'confirmed') instead of both — used
 * to show admin a breakdown instead of one merged number.
 */
function countOverlappingBookingsByStatus($conn, $room_name, $check_in, $check_out, $status, $exclude_booking_id = null) {
    $sql = "SELECT COUNT(*) c FROM bookings
            WHERE room_type = ?
              AND status = ?
              AND check_in < ?
              AND check_out > ?";
    $params = [$room_name, $status, $check_out, $check_in];
    $types  = "ssss";

    if ($exclude_booking_id !== null) {
        $sql .= " AND booking_id != ?";
        $params[] = $exclude_booking_id;
        $types .= "i";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return (int) $count;
}

/**
 * Count how many rooms of $room_name are already booked — CONFIRMED ONLY —
 * for any date that overlaps the requested [$check_in, $check_out) range.
 * Pending bookings are intentionally excluded: they don't hold a unit until
 * an admin confirms them. This means two pending bookings can exist for the
 * same last unit at once — check pending bookings carefully before confirming
 * a nearly-full room to avoid accidentally double-booking it.
 */
function countOverlappingBookings($conn, $room_name, $check_in, $check_out, $exclude_booking_id = null) {
    $sql = "SELECT COUNT(*) c FROM bookings
            WHERE room_type = ?
              AND status = 'confirmed'
              AND check_in < ?
              AND check_out > ?";
    $params  = [$room_name, $check_out, $check_in];
    $types   = "sss";

    if ($exclude_booking_id !== null) {
        $sql .= " AND booking_id != ?";
        $params[] = $exclude_booking_id;
        $types .= "i";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return (int) $count;
}

/**
 * How many units of this room type are still free for the given date range.
 */
function getAvailableUnits($conn, $room_name, $check_in, $check_out, $exclude_booking_id = null) {
    $room = getRoomInfo($conn, $room_name);
    if (!$room) return null;

    $booked = countOverlappingBookings($conn, $room_name, $check_in, $check_out, $exclude_booking_id);
    return max(0, $room['total_units'] - $booked);
}

/**
 * Convenience check used right before inserting a booking.
 */
function isRoomAvailable($conn, $room_name, $check_in, $check_out, $exclude_booking_id = null) {
    $available = getAvailableUnits($conn, $room_name, $check_in, $check_out, $exclude_booking_id);
    if ($available === null) return false;
    return $available > 0;
}