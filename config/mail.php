<?php

function appUrl(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $base = preg_replace('#/(admin|students)$#', '', $base);

    return $scheme . '://' . $host . $base . '/' . ltrim($path, '/');
}

function sendAppMail(string $to, string $subject, string $htmlBody, string $plainBody = ''): bool
{
    $plainBody = $plainBody ?: strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

    $smtp = [];
    if (file_exists(__DIR__ . '/smtp.php')) {
        $smtp = require __DIR__ . '/smtp.php';
    }

    $smtpHost = $smtp['host'] ?? getenv('SMTP_HOST') ?: '';
    $smtpPort = (int) ($smtp['port'] ?? getenv('SMTP_PORT') ?: 587);
    $smtpUsername = trim((string) ($smtp['username'] ?? getenv('SMTP_USERNAME') ?: ''));
    $smtpPassword = preg_replace('/\s+/', '', (string) ($smtp['password'] ?? getenv('SMTP_PASSWORD') ?: ''));
    $smtpFrom = trim((string) ($smtp['from'] ?? getenv('SMTP_FROM') ?: $smtpUsername));
    $smtpFromName = $smtp['from_name'] ?? getenv('SMTP_FROM_NAME') ?: 'Exam Shield';
    $smtpEncryption = strtolower($smtp['encryption'] ?? getenv('SMTP_ENCRYPTION') ?: 'tls');

    $phpMailerPaths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../PHPMailer/src/PHPMailer.php',
    ];

    if (file_exists($phpMailerPaths[0])) {
        require_once $phpMailerPaths[0];
    } elseif (file_exists($phpMailerPaths[1])) {
        require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
        require_once __DIR__ . '/../PHPMailer/src/Exception.php';
    }

    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            if ($smtpUsername !== '' && $smtpPassword !== '') {
                $mail->isSMTP();
                $mail->Host = $smtpHost !== '' ? $smtpHost : 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUsername;
                $mail->Password = $smtpPassword;
                $mail->Port = $smtpPort;

                if ($smtpEncryption === 'ssl') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } else {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                }

                $mail->setFrom($smtpFrom !== '' ? $smtpFrom : $smtpUsername, $smtpFromName);
                $mail->addAddress($to);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $htmlBody;
                $mail->AltBody = $plainBody;
                $mail->send();
                return true;
            }

            error_log('SMTP credentials are not configured. Update config/smtp.php before sending verification emails.');
        } catch (Throwable $e) {
            error_log('PHPMailer error: ' . $e->getMessage());
        }
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Exam Shield <no-reply@localhost>',
    ];

    if (@mail($to, $subject, $htmlBody, implode("\r\n", $headers))) {
        return true;
    }

    $logDir = __DIR__ . '/../uploads/mail-log';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    file_put_contents(
        $logDir . '/mail-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.html',
        "<h3>To: " . htmlspecialchars($to) . "</h3><h4>" . htmlspecialchars($subject) . "</h4>" . $htmlBody
    );

    return false;
}
