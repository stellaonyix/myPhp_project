<?php
session_start();
require '../config/database.php';
require '../includes/functions.php';

requireAdmin();

$stmt = $pdo->query("
    SELECT pe.*, u.fullname, u.email, e.title AS exam_title
    FROM proctor_events pe
    JOIN users u ON u.id = pe.user_id
    JOIN exam_attempts ea ON ea.id = pe.attempt_id
    JOIN exams e ON e.id = ea.exam_id
    ORDER BY pe.id DESC
    LIMIT 200
");
$events = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Proctoring Logs</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<header class="topbar">
    <div class="brand"><span class="brand-mark">ES</span> Exam Shield Admin</div>
    <nav class="nav-actions">
        <a href="dashboard.php">Dashboard</a>
        <a href="add-exam.php">Create Exam</a>
        <a href="add-question.php">Add Questions</a>
        <a href="proctor-events.php">Logs</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main class="page-shell">
<h2>Proctoring Logs</h2>
<p class="muted">Recent anti-cheat events captured during student exam attempts.</p>

<div class="table-wrap">
<table>
    <tr>
        <th>Time</th>
        <th>Student</th>
        <th>Exam</th>
        <th>Event</th>
        <th>Details</th>
    </tr>
    <?php foreach ($events as $event): ?>
    <tr>
        <td><?= htmlspecialchars($event['created_at']); ?></td>
        <td><?= htmlspecialchars($event['fullname'] . ' (' . $event['email'] . ')'); ?></td>
        <td><?= htmlspecialchars($event['exam_title']); ?></td>
        <td><?= htmlspecialchars($event['event_type']); ?></td>
        <td><?= htmlspecialchars($event['details'] ?? ''); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
</div>

<p><a href="dashboard.php">Back to Dashboard</a></p>
</main>

<script src="../assets/js/app.js"></script>

</body>
</html>
