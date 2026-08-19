<?php
// config/rate_limit.php

function clientIp(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Hashes the identifier (email, IP, etc.) so raw values never sit in the
 * rate_limits table. Same input always produces the same hash, so rate
 * limiting still works correctly — it just can't be read back to who it was.
 */
function rateLimitHashIdentifier(string $identifier): string {
    return hash('sha256', strtolower(trim($identifier)));
}

function rateLimitCheck(mysqli $conn, string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool {
    $bucketKey = $action . ':' . rateLimitHashIdentifier($identifier);

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM rate_limits WHERE bucket_key = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)");
    $stmt->bind_param("si", $bucketKey, $windowSeconds);
    $stmt->execute();
    $count = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return $count < $maxAttempts;
}

function rateLimitHit(mysqli $conn, string $action, string $identifier): void {
    $bucketKey = $action . ':' . rateLimitHashIdentifier($identifier);
    $stmt = $conn->prepare("INSERT INTO rate_limits (bucket_key) VALUES (?)");
    $stmt->bind_param("s", $bucketKey);
    $stmt->execute();
    $stmt->close();
}

function rateLimitCleanup(mysqli $conn): void {
    if (random_int(1, 100) === 1) {
        $conn->query("DELETE FROM rate_limits WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
    }
}   