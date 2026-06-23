<?php
session_start();
require '../includes/functions.php';

requireAdmin();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<header class="topbar">
    <div class="brand"><span class="brand-mark">ES</span> Exam Shield Admin</div>
    <nav class="nav-actions">
        <a href="dashboard.php">Dashboard</a>
        <a href="add-exam.php">Create Exam</a>
        <a href="add-question.php">Upload Questions</a>
        <a href="proctor-events.php">Logs</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main class="app-shell">
    <section class="dashboard-hero">
        <div>
            <h1>Admin Dashboard</h1>
            <p>Manage certification exams, question banks, and anti-cheat review logs from one workspace.</p>
        </div>
    </section>

    <section class="card-grid">
        <a class="action-card" href="add-exam.php">
            <strong>Create Exam</strong>
            <span>Set title, duration, pass mark, and availability.</span>
        </a>
        <a class="action-card" href="exams.php">
            <strong>View Exams</strong>
            <span>Review all exams already configured in the system.</span>
        </a>
        <a class="action-card" href="add-question.php">
            <strong>Upload Questions</strong>
            <span>Import an Excel question sheet, preview it, and save it to an exam.</span>
        </a>
        <a class="action-card" href="proctor-events.php">
            <strong>Proctoring Logs</strong>
            <span>Audit camera, tab, fullscreen, and focus violations.</span>
        </a>
    </section>
</main>

<script src="../assets/js/app.js"></script>

</body>
</html>
