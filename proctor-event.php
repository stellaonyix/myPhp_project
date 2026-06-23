<?php
session_start();
require 'config/database.php';
require 'includes/functions.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);

if (!is_array($payload) || !hash_equals($_SESSION['csrf_token'] ?? '', $payload['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode(['ok' => false]);
    exit();
}

$attemptId = (int) ($payload['attempt_id'] ?? 0);
$eventType = substr(preg_replace('/[^a-z0-9_ -]/i', '', $payload['event_type'] ?? 'unknown'), 0, 80);
$details = substr(trim($payload['details'] ?? ''), 0, 1000);

$stmt = $pdo->prepare("
    SELECT id, exam_id
    FROM exam_attempts
    WHERE id = ? AND user_id = ? AND status IN ('in_progress','flagged')
");
$stmt->execute([$attemptId, $_SESSION['user_id']]);
$attempt = $stmt->fetch();

if (!$attempt) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit();
}

$pdo->beginTransaction();
$pdo->prepare("
    INSERT INTO proctor_events(attempt_id, user_id, exam_id, event_type, details)
    VALUES (?, ?, ?, ?, ?)
")->execute([$attemptId, $_SESSION['user_id'], $attempt['exam_id'], $eventType, $details]);

$pdo->prepare("
    UPDATE exam_attempts
    SET violation_count = violation_count + 1,
        status = CASE WHEN violation_count + 1 >= 3 THEN 'flagged' ELSE status END
    WHERE id = ?
")->execute([$attemptId]);

$stmt = $pdo->prepare("SELECT violation_count, status FROM exam_attempts WHERE id = ?");
$stmt->execute([$attemptId]);
$updatedAttempt = $stmt->fetch();
$pdo->commit();

echo json_encode([
    'ok' => true,
    'violations' => (int) ($updatedAttempt['violation_count'] ?? 0),
    'status' => $updatedAttempt['status'] ?? 'in_progress',
]);
