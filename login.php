<?php
session_start();
require 'config/database.php';
require 'config/mail.php';
require 'includes/functions.php';

$message = '';
$verificationLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare(
        "SELECT * FROM users WHERE email = ?"
    );

    $stmt->execute([$email]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $message = "Too many failed attempts. Try again later.";
    } elseif ($user && password_verify($password, $user['password'])) {

        if (empty($user['email_verified_at'])) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            $pdo->prepare("
                INSERT INTO email_verification_tokens(user_id, token_hash, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
            ")->execute([$user['id'], $tokenHash]);

            $verifyUrl = appUrl('verify-email.php?token=' . urlencode($token));
            $mailSent = sendAppMail(
                $user['email'],
                'Verify your Exam Shield account',
                '<p>Hello ' . htmlspecialchars($user['fullname']) . ',</p>
                 <p>Click the link below to verify your email before logging in:</p>
                 <p><a href="' . htmlspecialchars($verifyUrl) . '">Verify Email</a></p>
                 <p>This link expires in 24 hours.</p>',
                "Verify your Exam Shield account: $verifyUrl"
            );

            if ($mailSent) {
                $message = "Please verify your email before logging in. We sent a fresh verification link to your email.";
            } else {
                $message = "Please verify your email before logging in. The verification email could not be sent automatically, so use the link below instead.";
                $verificationLink = '<p class="muted"><a href="' . htmlspecialchars($verifyUrl) . '">Verify your email now</a></p>';
            }
        } else {
            $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")
                ->execute([$user['id']]);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];

            if ($user['role'] === 'admin') {

                header("Location: admin/dashboard.php");

            } else {

                header("Location: instructions.php");

            }

            exit();
        }

    } else {
        if ($user) {
            $attempts = ((int) $user['failed_login_attempts']) + 1;
            $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
            $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?")
                ->execute([$attempts, $lockedUntil, $user['id']]);
        }

        $message = "Invalid email or password.";

    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">

<div class="auth-container">

    <h2>Welcome back</h2>
    <p class="muted">Sign in to continue to your secure exam workspace.</p>

    <?php if($message): ?>
        <p class="notice"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>
    <?php if (!empty($verificationLink)): ?>
        <?= $verificationLink ?>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">

        <input
            type="email"
            name="email"
            placeholder="Email"
            required
        >

        <input
            type="password"
            name="password"
            placeholder="Password"
            required
        >

        <label class="password-check">
            <input type="checkbox" class="show-password-toggle">
            <span>Show password</span>
        </label>

        <button type="submit">
            Login
        </button>

    </form>

    <p class="muted">
        Don't have an account?
        <a href="register.php">Register</a>
    </p>

    <p>
        <a href="forgot-password.php">Forgot password?</a>
    </p>

</div>

<script src="assets/js/app.js"></script>

</body>
</html>
