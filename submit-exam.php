<?php
session_start();
require 'config/database.php';
require 'includes/functions.php';

requireLogin('login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: instructions.php');
    exit();
}

verifyCsrf();

$attemptId = (int) ($_POST['attempt_id'] ?? 0);
$answers = $_POST['answers'] ?? [];

$stmt = $pdo->prepare("
    SELECT ea.*, e.pass_mark, e.title
    FROM exam_attempts ea
    JOIN exams e ON e.id = ea.exam_id
    WHERE ea.id = ? AND ea.user_id = ? AND ea.submitted_at IS NULL
    LIMIT 1
");
$stmt->execute([$attemptId, $_SESSION['user_id']]);
$attempt = $stmt->fetch();

if (!$attempt) {
    die('This exam has already been submitted or is unavailable.');
}

$stmt = $pdo->prepare("SELECT id, correct_answer, marks FROM questions WHERE exam_id = ?");
$stmt->execute([$attempt['exam_id']]);
$questions = $stmt->fetchAll();

$scoreMarks = 0;
$totalMarks = 0;
$pdo->beginTransaction();

foreach ($questions as $question) {
    $questionId = (int) $question['id'];
    $marks = max(1, (int) ($question['marks'] ?? 1));
    $selected = $answers[$questionId] ?? null;
    $selected = in_array($selected, ['A', 'B', 'C', 'D'], true) ? $selected : null;
    $isCorrect = $selected !== null && $selected === $question['correct_answer'];
    $totalMarks += $marks;

    if ($isCorrect) {
        $scoreMarks += $marks;
    }

    $pdo->prepare("
        INSERT INTO exam_answers(attempt_id, question_id, selected_answer, is_correct)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE selected_answer = VALUES(selected_answer), is_correct = VALUES(is_correct)
    ")->execute([$attemptId, $questionId, $selected, $isCorrect ? 1 : 0]);
}

$total = count($questions);
$percentage = percentageScore($scoreMarks, $totalMarks);
$status = strtotime($attempt['ends_at']) < time() ? 'auto_submitted' : 'submitted';

$pdo->prepare("
    UPDATE exam_attempts
    SET submitted_at = NOW(), score = ?, total_questions = ?, status = ?
    WHERE id = ?
")->execute([$percentage, $total, $status, $attemptId]);

$pdo->commit();

unset($_SESSION['attempt_id']);

header('Location: result.php?attempt_id=' . $attemptId);
exit();
