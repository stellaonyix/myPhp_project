<?php
require 'config/database.php';
$rows = $pdo->query("SELECT id, user_id, exam_id, status, ends_at, submitted_at, total_questions, violation_count FROM exam_attempts ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo json_encode($row, JSON_PRETTY_PRINT) . PHP_EOL;
}
