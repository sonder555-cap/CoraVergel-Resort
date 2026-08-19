<?php
// config/csrf.php
// Include AFTER session_start(). Protects POST forms from CSRF attacks —
// without this, a malicious site could submit your login/register/reset
// forms on a logged-in user's behalf just by getting them to visit a page.

/**
 * Get (or create) the CSRF token for this session.
 * Call this when rendering any form, and put the result in a hidden field.
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Outputs a ready-to-use hidden <input> for forms.
 * Usage in HTML: <?= csrfField() ?>
 */
function csrfField(): string {
    $token = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Call at the top of every POST handler block, before doing anything else.
 * Kills the request immediately if the token is missing or wrong.
 */
function csrfVerify(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        die('Security check failed. Please refresh the page and try again.');
    }
}
