<?php
require "config/database.php";
$stmt = $pdo->prepare("INSERT INTO exam_attempts(user_id, exam_id, started_at, ends_at, total_questions) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)");
$stmt->execute([999, 1, 1, 1]);
echo "INSERT_OK:" . $pdo->lastInsertId() . PHP_EOL;
$pdo->exec("DELETE FROM exam_attempts WHERE id=" . $pdo->lastInsertId());
