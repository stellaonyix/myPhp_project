<?php
require 'config/database.php';

$message = 'Invalid or expired verification link.';
$token = $_GET['token'] ?? '';

if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT evt.id, evt.user_id
        FROM email_verification_tokens evt
        JOIN users u ON u.id = evt.user_id
        WHERE evt.token_hash = ?
          AND evt.used_at IS NULL
          AND evt.expires_at > NOW()
          AND u.email_verified_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $record = $stmt->fetch();

    if ($record) {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?")
            ->execute([$record['user_id']]);
        $pdo->prepare("UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ?")
            ->execute([$record['id']]);
        $pdo->commit();
        $message = 'Email verified successfully. You can now log in.';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Email Verification</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-container">
    <h2>Email Verification</h2>
    <p class="notice"><?= htmlspecialchars($message); ?></p>
    <a href="login.php">Go to Login</a>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
