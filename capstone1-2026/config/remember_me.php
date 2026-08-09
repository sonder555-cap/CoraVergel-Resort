<?php
/* ══════════════════════════════════════════════════════════════
   REMEMBER ME — persistent login tokens
   ══════════════════════════════════════════════════════════════
   Requires this table (run once in phpMyAdmin / your DB tool):

   CREATE TABLE remember_tokens (
       id             INT AUTO_INCREMENT PRIMARY KEY,
       user_id        INT NOT NULL,
       selector       VARCHAR(24) NOT NULL,
       validator_hash VARCHAR(64) NOT NULL,
       expires_at     DATETIME NOT NULL,
       created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
       UNIQUE KEY (selector),
       FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
   );

   How it works: the cookie holds "selector:validator". The selector
   is a lookup key stored in the DB as plain text (fast, safe to
   look up by). The validator is never stored directly — only its
   SHA-256 hash is — so a stolen database dump alone can't be used
   to forge a working cookie.
   ══════════════════════════════════════════════════════════════ */

/**
 * Call after a successful login (once OTP is verified) if the
 * "Remember me" checkbox was checked. Issues a 30-day cookie.
 */
function issueRememberMeToken(mysqli $conn, int $uid): void {
    $selector       = bin2hex(random_bytes(9));   // 18 hex chars
    $validator      = bin2hex(random_bytes(32));  // 64 hex chars
    $validator_hash = hash('sha256', $validator);
    $expires        = date('Y-m-d H:i:s', strtotime('+30 days'));

    $stmt = $conn->prepare("INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $uid, $selector, $validator_hash, $expires);
    $stmt->execute();
    $stmt->close();

    setcookie('remember_me', $selector . ':' . $validator, [
        'expires'  => strtotime('+30 days'),
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // 'secure' => true, // uncomment once the site is served over HTTPS
    ]);
}

/**
 * Call on login.php when there's no active session, to see if a
 * valid remember-me cookie can log the person back in automatically.
 * Returns ['uid' => ..., 'full_name' => ..., 'role' => ...] or null.
 */
function tryRememberMeLogin(mysqli $conn): ?array {
    if (empty($_COOKIE['remember_me']) || strpos($_COOKIE['remember_me'], ':') === false) {
        return null;
    }
    [$selector, $validator] = explode(':', $_COOKIE['remember_me'], 2);

    $stmt = $conn->prepare("SELECT user_id, validator_hash, expires_at FROM remember_tokens WHERE selector = ?");
    $stmt->bind_param("s", $selector);
    $stmt->execute();
    $stmt->bind_result($row_uid, $row_hash, $row_expires);
    $found = $stmt->fetch();
    $stmt->close();

    $valid = $found && strtotime($row_expires) >= time() && hash_equals($row_hash, hash('sha256', $validator));

    if (!$valid) {
        clearRememberMeCookie($conn, true);
        return null;
    }

    $ustmt = $conn->prepare("SELECT full_name, role FROM users WHERE user_id = ?");
    $ustmt->bind_param("i", $row_uid);
    $ustmt->execute();
    $ustmt->bind_result($full_name, $role);
    $ustmt->fetch();
    $ustmt->close();

    return ['uid' => $row_uid, 'full_name' => $full_name, 'role' => $role];
}

/**
 * Call on logout to revoke the token and clear the cookie.
 * Pass $deleteBySelectorOnly = true when you just want to clean up
 * an invalid/expired cookie without needing a valid session.
 */
function clearRememberMeCookie(mysqli $conn, bool $deleteBySelectorOnly = false): void {
    if (!empty($_COOKIE['remember_me']) && strpos($_COOKIE['remember_me'], ':') !== false) {
        [$selector] = explode(':', $_COOKIE['remember_me'], 2);
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE selector = ?");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
        $stmt->close();
    }
    setcookie('remember_me', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}