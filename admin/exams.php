<?php
session_start();
require '../config/database.php';
require '../includes/functions.php';

requireAdmin();

$stmt = $pdo->query("
    SELECT * FROM exams
    ORDER BY id DESC
");

$exams = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>All Exams</title>
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
<h2>Available Exams</h2>
<p class="muted">All certification exams currently registered in Exam Shield.</p>

<div class="table-wrap">
<table>

<tr>
    <th>ID</th>
    <th>Title</th>
    <th>Duration</th>
    <th>Pass Mark</th>
</tr>

<?php foreach($exams as $exam): ?>

<tr>
    <td><?= $exam['id']; ?></td>

    <td><?= htmlspecialchars($exam['title']); ?></td>

    <td><?= $exam['duration']; ?> mins</td>

    <td><?= $exam['pass_mark']; ?>%</td>
</tr>

<?php endforeach; ?>

</table>
</div>

<p><a href="dashboard.php">Back to Dashboard</a></p>
</main>

<script src="../assets/js/app.js"></script>

</body>
</html>
