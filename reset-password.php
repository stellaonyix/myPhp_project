<?php
session_start();
require 'config/database.php';
require 'includes/functions.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$message = '';
$valid = false;

if ($token !== '') {
    $stmt = $pdo->prepare("
        SELECT pr.id, pr.user_id
        FROM password_resets pr
        WHERE pr.token_hash = ?
          AND pr.used_at IS NULL
          AND pr.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', $token)]);
    $record = $stmt->fetch();
    $valid = (bool) $record;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$valid) {
        $message = 'Invalid or expired reset link.';
    } elseif (!isStrongPassword($password)) {
        $message = implode(' ', passwordValidationErrors($password));
    } elseif ($password !== $confirm) {
        $message = 'Passwords do not match.';
    } else {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET password = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $record['user_id']]);
        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")
            ->execute([$record['id']]);
        $pdo->commit();
        $message = 'Password changed successfully. You can now log in.';
        $valid = false;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reset Password</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-container">
    <h2>Reset Password</h2>
    <p class="muted">Choose a strong password with at least 8 characters.</p>
    <?php if ($message): ?><p class="notice"><?= htmlspecialchars($message); ?></p><?php endif; ?>
    <?php if ($valid): ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
        <input
            type="password"
            name="password"
            id="password"
            placeholder="New Password"
            autocomplete="new-password"
            data-strong-password="true"
            pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}"
            title="Use at least 8 characters with capital letter, small letter, number, and special character."
            required
        >
        <div class="password-rules" data-password-rules>
            <strong>Password must contain:</strong>
            <span data-rule="length">At least 8 characters</span>
            <span data-rule="uppercase">One capital letter</span>
            <span data-rule="lowercase">One small letter</span>
            <span data-rule="number">One number</span>
            <span data-rule="special">One special character</span>
        </div>
        <input type="password" name="confirm_password" placeholder="Confirm Password" autocomplete="new-password" required>
        <button type="submit">Reset Password</button>
    </form>
    <?php else: ?>
    <p><a href="login.php">Back to login</a></p>
    <?php endif; ?>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
