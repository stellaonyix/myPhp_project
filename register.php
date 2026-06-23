<?php
session_start();
require 'config/database.php';
require 'config/mail.php';
require 'includes/functions.php';

$message = '';
$verificationLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $fullname = cleanText($_POST['fullname'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'];

    if (empty($fullname) || empty($email) || empty($password)) {
        $message = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    } elseif (!isStrongPassword($password)) {
        $message = implode(' ', passwordValidationErrors($password));
    } else {

        $check = $pdo->prepare(
            "SELECT id FROM users WHERE email = ?"
        );

        $check->execute([$email]);

        if ($check->rowCount() > 0) {

            $message = "Email already exists.";

        } else {

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare(
                "INSERT INTO users(fullname,email,password)
                 VALUES(?,?,?)"
            );

            $stmt->execute([
                $fullname,
                $email,
                $hashedPassword
            ]);

            $userId = (int) $pdo->lastInsertId();
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);

            $stmt = $pdo->prepare("
                INSERT INTO email_verification_tokens(user_id, token_hash, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
            ");
            $stmt->execute([$userId, $tokenHash]);

            $verifyUrl = appUrl('verify-email.php?token=' . urlencode($token));
            $mailSent = sendAppMail(
                $email,
                'Verify your Exam Shield account',
                '<p>Hello ' . htmlspecialchars($fullname) . ',</p>
                 <p>Click the link below to verify your email before logging in:</p>
                 <p><a href="' . htmlspecialchars($verifyUrl) . '">Verify Email</a></p>
                 <p>This link expires in 24 hours.</p>',
                "Verify your Exam Shield account: $verifyUrl"
            );

            if ($mailSent) {
                $message = "Registration successful. Please check your email to verify your account before logging in.";
            } else {
                $message = "Registration successful, but the verification email could not be sent automatically. Use the link below to verify your account.";
                $verificationLink = '<p class="muted"><a href="' . htmlspecialchars($verifyUrl) . '">Verify your email now</a></p>';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">

<div class="auth-container">

    <h2>Create account</h2>
    <p class="muted">Register once, verify your email, and take certification exams securely.</p>

    <?php if($message): ?>
        <p class="notice"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>
    <?php if (!empty($verificationLink)): ?>
        <?= $verificationLink ?>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">

        <input
            type="text"
            name="fullname"
            placeholder="Full Name"
            required
        >

        <input
            type="email"
            name="email"
            placeholder="Email"
            required
        >

        <input
            type="password"
            name="password"
            id="password"
            placeholder="Password"
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

        <label class="password-check">
            <input type="checkbox" class="show-password-toggle">
            <span>Show password</span>
        </label>

        <button type="submit">
            Register
        </button>

    </form>

    <p class="muted">
        Already have an account?
        <a href="login.php">Login</a>
    </p>

</div>

<script src="assets/js/app.js"></script>

</body>
</html>
