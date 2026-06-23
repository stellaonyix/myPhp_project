<?php
session_start();
require 'config/database.php';
require 'includes/functions.php';

requireLogin('login.php');

$stmt = $pdo->query("
    SELECT e.*, COUNT(q.id) AS question_count
    FROM exams e
    LEFT JOIN questions q ON q.exam_id = e.id
    WHERE e.is_active = 1
    GROUP BY e.id
    HAVING question_count > 0
    ORDER BY e.title
");
$exams = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Exam Instructions</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="topbar">
    <div class="brand"><span class="brand-mark">ES</span> Exam Shield</div>
    <nav class="nav-actions">
        <a href="instructions.php">Exams</a>
        <a href="logout.php">Logout</a>
    </nav>
</header>

<main class="page-shell">
<h1>Exam Instructions</h1>
<p class="muted">Choose an exam and confirm the proctoring rules before starting.</p>

<ol class="instruction-list">
    <li>Do not switch tabs.</li>
    <li>Do not minimize your browser.</li>
    <li>Allow webcam access.</li>
    <li>Stay in fullscreen mode.</li>
    <li>Any suspicious activity will be logged.</li>
    <li>The timer cannot be paused.</li>
</ol>

<form action="start-exam.php" method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">

    <?php if (empty($exams)): ?>
    <p class="notice">
        No exam is available yet. An admin must create an active exam and add at least one question before students can start.
    </p>
    <?php endif; ?>

    <div class="form-row">
        <label for="exam_id">Select Certification Exam</label>
        <select id="exam_id" name="exam_id" required <?= empty($exams) ? 'disabled' : ''; ?>>
            <option value=""><?= empty($exams) ? 'No exam available' : 'Choose an exam'; ?></option>
            <?php foreach ($exams as $exam): ?>
            <option value="<?= (int) $exam['id']; ?>">
                <?= htmlspecialchars($exam['title']); ?> - <?= (int) $exam['duration']; ?> mins, <?= (int) $exam['question_count']; ?> questions
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <label>
        <input type="checkbox" required>
        I have read and understood the instructions
    </label>

    <br><br>

    <button type="submit" <?= empty($exams) ? 'disabled' : ''; ?>>
        Start Exam
    </button>

</form>

<p><a href="logout.php">Logout</a></p>
</main>

<script src="assets/js/app.js"></script>

</body>
</html>
