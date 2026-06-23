<?php
session_start();
require 'config/database.php';
require 'config/mail.php';
require 'includes/functions.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT id, fullname FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("
                INSERT INTO password_resets(user_id, token_hash, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ")->execute([$user['id'], hash('sha256', $token)]);

            $resetUrl = appUrl('reset-password.php?token=' . urlencode($token));
            sendAppMail(
                $email,
                'Reset your Exam Shield password',
                '<p>Hello ' . htmlspecialchars($user['fullname']) . ',</p>
                 <p>Use this link to reset your password:</p>
                 <p><a href="' . htmlspecialchars($resetUrl) . '">Reset Password</a></p>
                 <p>This link expires in 1 hour.</p>',
                "Reset your Exam Shield password: $resetUrl"
            );
        }

        $message = 'If that email exists, a password reset link has been sent.';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Forgot Password</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-container">
    <h2>Forgot Password</h2>
    <p class="muted">Enter your account email and we will send a secure reset link.</p>
    <?php if ($message): ?><p class="notice"><?= htmlspecialchars($message); ?></p><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">
        <input type="email" name="email" placeholder="Email" required>
        <button type="submit">Send Reset Link</button>
    </form>
    <p><a href="login.php">Back to login</a></p>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
