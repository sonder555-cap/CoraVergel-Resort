<?php
/* ══════════════════════════════════════════════════════════════
   AI CHAT WIDGET — server-side endpoint
   Receives { messages: [{role, content}, ...] } from
   assets/js/ai-chat-widget.js and calls the Claude API here, where
   the API key stays server-side. Returns { reply: "..." } on
   success, or a non-200 status with { error: "..." } on failure.
══════════════════════════════════════════════════════════════ */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Read the API key from the environment — never hardcode it here.
// Set CORAVERGEL_ANTHROPIC_API_KEY on your server before enabling this
// widget (e.g. in your hosting panel's environment variables, or in
// php-fpm's pool config — not in a file that gets committed to source
// control).
$apiKey = getenv('CORAVERGEL_ANTHROPIC_API_KEY');
if (!$apiKey) {
    error_log('AI chat error: CORAVERGEL_ANTHROPIC_API_KEY is not set in the environment.');
    http_response_code(500);
    echo json_encode(['error' => 'Chat is not configured yet.']);
    exit();
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload) || !isset($payload['messages']) || !is_array($payload['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Malformed request.']);
    exit();
}

// ── Basic input hygiene ──
// Cap history length and per-message size so a malicious or buggy client
// can't send an unbounded request body or a runaway conversation.
$messages = array_slice($payload['messages'], -20); // keep at most the last 20 turns
$cleanMessages = [];
foreach ($messages as $m) {
    if (!isset($m['role'], $m['content']) || !is_string($m['content'])) continue;
    if (!in_array($m['role'], ['user', 'assistant'], true)) continue;
    $content = mb_substr($m['content'], 0, 4000); // cap message length
    $cleanMessages[] = ['role' => $m['role'], 'content' => $content];
}

if (empty($cleanMessages)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid messages provided.']);
    exit();
}

$systemPrompt = <<<SYS
You are the CoraVergel Resort assistant, embedded as a chat widget on the resort's public website.

Resort facts you can rely on:
- CoraVergel Resort, 21 Barosong, Tigbauan, Iloilo City, Philippines
- Phone: +320 2512, Email: coravergelresort@gmail.com
- Check-in: 2:00 PM, Check-out: 12:00 PM
- Quiet hours: 10:00 PM – 6:00 AM
- No outside food & beverages allowed
- Free swimming included for overnight guests
- Guests submit a valid ID during booking; bookings can be cancelled up to 24 hours before check-in

You do not have live access to current room availability, prices, or promotions — for those, tell the guest to check the Rooms/Deals pages on the site or contact the resort directly, rather than guessing a number. Keep answers short, warm, and specific to CoraVergel Resort. If asked something unrelated to the resort, politely redirect to resort topics.
SYS;

$body = json_encode([
    'model' => 'claude-sonnet-4-5',
    'max_tokens' => 500,
    'system' => $systemPrompt,
    'messages' => $cleanMessages,
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log('AI chat error: cURL failed — ' . $curlError);
    http_response_code(502);
    echo json_encode(['error' => 'Could not reach the chat service.']);
    exit();
}

$decoded = json_decode($response, true);

if ($httpCode !== 200 || !isset($decoded['content'][0]['text'])) {
    error_log('AI chat error: unexpected API response (HTTP ' . $httpCode . '): ' . $response);
    http_response_code(502);
    echo json_encode(['error' => 'The chat service returned an unexpected response.']);
    exit();
}

$reply = $decoded['content'][0]['text'];
echo json_encode(['reply' => $reply]);
