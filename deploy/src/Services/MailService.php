<?php
namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

/**
 * Wrapper um PHPMailer für den E-Mail-Versand mit SMTP-Konfiguration
 * aus den App-Einstellungen.
 */
class MailService
{
    /**
     * Sendet eine HTML-E-Mail mit optionalem Dateianhang.
     *
     * @param array       $cfg         SMTP-Konfiguration (smtp_host, smtp_port, smtp_user,
     *                                 smtp_pass, smtp_from_email, smtp_from_name, smtp_security)
     * @param string      $to          Empfänger-E-Mail-Adresse
     * @param string      $toName      Empfänger-Anzeigename (kann leer sein)
     * @param string      $subject     Betreff
     * @param string      $htmlBody    HTML-Inhalt
     * @param string|null $attachBytes Binärdaten des Anhangs (null = kein Anhang)
     * @param string|null $attachName  Dateiname des Anhangs (z. B. "Protokoll.pdf")
     *
     * @throws \RuntimeException Bei Verbindungs- oder Sendefehlern
     */
    public static function send(
        array   $cfg,
        string  $to,
        string  $toName,
        string  $subject,
        string  $htmlBody,
        ?string $attachBytes = null,
        ?string $attachName  = null,
        string  $attachMime  = 'application/pdf'
    ): void {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host    = $cfg['smtp_host'] ?? '';
            $mail->Port    = (int)($cfg['smtp_port'] ?? 587);
            $mail->CharSet = 'UTF-8';

            $smtpUser = $cfg['smtp_user'] ?? '';
            if ($smtpUser !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = $cfg['smtp_pass'] ?? '';
            }

            $security = strtolower($cfg['smtp_security'] ?? 'tls');
            if ($security === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($security === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $fromEmail = $cfg['smtp_from_email'] ?? '';
            $fromName  = $cfg['smtp_from_name']  ?? '';
            if (!$fromEmail) {
                throw new \RuntimeException('Absender-E-Mail nicht konfiguriert. Bitte SMTP-Einstellungen im Admin-Bereich prüfen.');
            }
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

            if ($attachBytes !== null && $attachName !== null) {
                $mail->addStringAttachment(
                    $attachBytes,
                    $attachName,
                    PHPMailer::ENCODING_BASE64,
                    $attachMime
                );
            }

            $mail->send();
        } catch (MailerException $e) {
            throw new \RuntimeException('E-Mail konnte nicht gesendet werden: ' . $mail->ErrorInfo);
        }
    }
}
