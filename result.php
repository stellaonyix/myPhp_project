<?php
session_start();
require 'config/database.php';
require 'includes/functions.php';

requireLogin('login.php');

$attemptId = (int) ($_GET['attempt_id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT ea.*, e.title, e.pass_mark
    FROM exam_attempts ea
    JOIN exams e ON e.id = ea.exam_id
    WHERE ea.id = ? AND ea.user_id = ?
");
$stmt->execute([$attemptId, $_SESSION['user_id']]);
$attempt = $stmt->fetch();

if (!$attempt) {
    die('Result not found.');
}

$score = (int) $attempt['score'];
$resultStatus = examResultStatus($score);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Exam Result</title>
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
    <section class="result-card" id="resultCard">
    <h1>Exam Result</h1>
    <div class="score-ring" style="--score: <?= $score; ?>%;">
        <span><?= $score; ?>%</span>
    </div>
    <p><strong>Exam:</strong> <span id="resultExam"><?= htmlspecialchars($attempt['title']); ?></span></p>
    <p><strong>Status:</strong> <span id="resultStatus" class="status-badge <?= htmlspecialchars($resultStatus['class']); ?>"><?= htmlspecialchars($resultStatus['label']); ?></span></p>
    <p><strong>Score:</strong> <span id="resultScore"><?= $score; ?>%</span></p>
    <p><strong>Pass mark:</strong> <span id="resultPassMark"><?= (int) $resultStatus['pass_mark']; ?>%</span></p>
    <p><strong>Proctoring violations:</strong> <span id="resultViolations"><?= (int) $attempt['violation_count']; ?></span></p>
    <?php if ($attempt['violation_count'] >= 3): ?>
    <p><span class="status-badge warn">Flagged for admin review</span></p>
    <?php endif; ?>
    <div class="result-actions">
        <button type="button" id="printResultBtn">Print / Save as PDF</button>
        <button type="button" class="btn-secondary" id="saveResultBtn">Save Result</button>
        <a class="btn" href="instructions.php">Take another exam</a>
    </div>
    </section>
</main>
<script src="assets/js/app.js"></script>
<script>
document.getElementById('printResultBtn').addEventListener('click', function() {
    window.print();
});

document.getElementById('saveResultBtn').addEventListener('click', function() {
    const exam = document.getElementById('resultExam').textContent.trim();
    const status = document.getElementById('resultStatus').textContent.trim();
    const score = document.getElementById('resultScore').textContent.trim();
    const passMark = document.getElementById('resultPassMark').textContent.trim();
    const violations = document.getElementById('resultViolations').textContent.trim();
    const savedAt = new Date().toLocaleString();
    const safeName = exam.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'exam';
    const html = `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Exam Result - ${exam}</title>
    <style>
        body{font-family:Arial,Helvetica,sans-serif;margin:40px;color:#172033;line-height:1.5}
        .result{max-width:620px;margin:auto;border:1px solid #d8e1ee;padding:28px;border-radius:8px}
        h1{margin-top:0}
        strong{display:inline-block;min-width:170px}
    </style>
</head>
<body>
    <section class="result">
        <h1>Exam Result</h1>
        <p><strong>Exam:</strong> ${exam}</p>
        <p><strong>Status:</strong> ${status}</p>
        <p><strong>Score:</strong> ${score}</p>
        <p><strong>Pass mark:</strong> ${passMark}</p>
        <p><strong>Proctoring violations:</strong> ${violations}</p>
        <p><strong>Saved:</strong> ${savedAt}</p>
    </section>
</body>
</html>`;

    const blob = new Blob([html], {type: 'text/html'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = safeName + '-result.html';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
});
</script>
</body>
</html>
