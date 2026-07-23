<?php
// config/mailer.php
// Manual PHPMailer install (files placed in config/PHPMailer/)
require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email via SMTP using PHPMailer.
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $subject
 * @param string $bodyHtml   HTML body
 * @param string $bodyPlain  Plain-text fallback (optional)
 * @return bool  true on success, false on failure
 */
function sendMail($toEmail, $toName, $subject, $bodyHtml, $bodyPlain = '') {
    $mail = new PHPMailer(true);

    try {
        // ── SMTP SETTINGS ──
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';        // change if using a different provider
        $mail->SMTPAuth   = true;
        $mail->Username   = 'lexnnder15@gmail.com';  // TODO: your sending email
        $mail->Password   = 'mmeicdtaaxpbpghx';  // TODO: Gmail App Password (not your login password)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // ── SENDER / RECIPIENT ──
        $mail->setFrom('no-reply@coravergel.com', 'CoraVergel Resort');
        $mail->addAddress($toEmail, $toName);

        // ── CONTENT ──
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = $bodyPlain !== '' ? $bodyPlain : strip_tags($bodyHtml);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}