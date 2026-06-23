<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require 'config/database.php';
require 'includes/functions.php';

requireLogin('login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: instructions.php');
    exit();
}

verifyCsrf();

$examId = (int) ($_POST['exam_id'] ?? 0);

$pdo->prepare("UPDATE exam_attempts SET status = 'auto_submitted', submitted_at = NOW() WHERE user_id = ? AND submitted_at IS NULL AND ends_at <= NOW() AND status IN ('in_progress', 'flagged')")
    ->execute([$_SESSION['user_id']]);

unset($_SESSION['attempt_id']);
$stmt = $pdo->prepare("
    SELECT e.*, COUNT(q.id) AS question_count
    FROM exams e
    JOIN questions q ON q.exam_id = e.id
    WHERE e.id = ? AND e.is_active = 1
    GROUP BY e.id
");
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam) {
    die('Selected exam is not available.');
}

$stmt = $pdo->prepare("
    INSERT INTO exam_attempts(user_id, exam_id, started_at, ends_at, total_questions, status, violation_count)
    VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, 'in_progress', 0)
");
$stmt->execute([
    $_SESSION['user_id'],
    $examId,
    (int) $exam['duration'],
    (int) $exam['question_count'],
]);

$_SESSION['attempt_id'] = (int) $pdo->lastInsertId();
?>

<!DOCTYPE html>
<html>
<head>
<title>Preparing Exam...</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<main class="page-shell">
<h2>Preparing Exam Environment...</h2>
<p class="muted">Your webcam preview must remain active throughout the exam.</p>

<p id="status">
Checking camera permissions...
</p>

<video id="cameraPreview" autoplay muted playsinline></video>

<button id="startBtn" style="display:none;">
Begin Examination
</button>
</main>

<script>
let cameraStream = null;

navigator.mediaDevices.getUserMedia({
    video:true,
    audio:false
})
.then(function(stream){
    cameraStream = stream;
    document.getElementById('cameraPreview').srcObject = stream;

    document.getElementById('status').innerHTML =
    "Camera access granted.";

    document.getElementById('startBtn')
        .style.display = 'block';

})
.catch(function(){

    document.getElementById('status').innerHTML =
    "Camera permission required.";

});

document.getElementById('startBtn')
.addEventListener('click', async function(){

    if(document.documentElement.requestFullscreen){

        await document.documentElement
        .requestFullscreen();

    }

    window.location.href = "exam.php?attempt_id=<?= (int) $_SESSION['attempt_id']; ?>";

});

</script>
<script src="assets/js/app.js"></script>

</body>
</html>
