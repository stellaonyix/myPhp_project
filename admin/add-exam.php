<?php
session_start();
require '../config/database.php';
require '../includes/functions.php';

requireAdmin();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title = cleanText($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $duration = (int)$_POST['duration'];
    $pass_mark = (int)$_POST['pass_mark'];

    if ($title === '' || $duration < 1 || $pass_mark < 1 || $pass_mark > 100) {
        $message = "Enter a title, duration, and a pass mark between 1 and 100.";
    } else {
    $stmt = $pdo->prepare("
        INSERT INTO exams
        (title, description, duration, pass_mark)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $title,
        $description,
        $duration,
        $pass_mark
    ]);

    $message = "Exam created successfully.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Exam</title>
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
<h2>Create Exam</h2>
<p class="muted">Create a certification exam with a fixed duration and pass mark.</p>

<?php if($message): ?>
<p class="notice"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">

    <div class="form-row">
        <label for="title">Exam Title</label>
        <input id="title" type="text" name="title" placeholder="Exam Title" required>
    </div>

    <div class="form-row">
        <label for="description">Exam Description</label>
        <textarea id="description" name="description" placeholder="Exam Description"></textarea>
    </div>

    <div class="form-grid">
        <div class="form-row">
            <label for="duration">Duration in Minutes</label>
            <input id="duration" type="number" name="duration" placeholder="Duration in Minutes" required>
        </div>

        <div class="form-row">
            <label for="pass_mark">Pass Mark (%)</label>
            <input id="pass_mark" type="number" name="pass_mark" placeholder="Pass Mark" required>
        </div>
    </div>

    <button type="submit">
        Create Exam
    </button>

</form>

<p><a href="dashboard.php">Back to Dashboard</a></p>
</main>

<script src="../assets/js/app.js"></script>

</body>
</html>
