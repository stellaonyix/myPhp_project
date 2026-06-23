<?php

$host = "localhost";
$dbname = "examshield";
$username = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Connection Failed: " . $e->getMessage());
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!columnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function initializeDatabase(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fullname VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('student','admin') NOT NULL DEFAULT 'student',
            email_verified_at DATETIME NULL,
            failed_login_attempts INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (tableExists($pdo, 'users')) {
        ensureColumn($pdo, 'users', 'role', "ENUM('student','admin') NOT NULL DEFAULT 'student'");
        ensureColumn($pdo, 'users', 'email_verified_at', 'DATETIME NULL');
        ensureColumn($pdo, 'users', 'failed_login_attempts', 'INT NOT NULL DEFAULT 0');
        ensureColumn($pdo, 'users', 'locked_until', 'DATETIME NULL');
        ensureColumn($pdo, 'users', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(190) NOT NULL,
            description TEXT NULL,
            duration INT NOT NULL,
            pass_mark INT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ensureColumn($pdo, 'exams', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensureColumn($pdo, 'exams', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            exam_id INT NOT NULL,
            question TEXT NOT NULL,
            option_a TEXT NOT NULL,
            option_b TEXT NOT NULL,
            option_c TEXT NOT NULL,
            option_d TEXT NOT NULL,
            correct_answer ENUM('A','B','C','D') NOT NULL,
            marks INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (exam_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (tableExists($pdo, 'questions')) {
        ensureColumn($pdo, 'questions', 'marks', 'INT NOT NULL DEFAULT 1');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_verification_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            exam_id INT NOT NULL,
            started_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            submitted_at DATETIME NULL,
            score INT NULL,
            total_questions INT NOT NULL DEFAULT 0,
            status ENUM('in_progress','submitted','flagged','auto_submitted') NOT NULL DEFAULT 'in_progress',
            violation_count INT NOT NULL DEFAULT 0,
            INDEX (user_id),
            INDEX (exam_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (tableExists($pdo, 'exam_attempts')) {
        ensureColumn($pdo, 'exam_attempts', 'started_at', 'DATETIME NOT NULL');
        ensureColumn($pdo, 'exam_attempts', 'ends_at', 'DATETIME NOT NULL');
        ensureColumn($pdo, 'exam_attempts', 'submitted_at', 'DATETIME NULL');
        ensureColumn($pdo, 'exam_attempts', 'score', 'INT NULL');
        ensureColumn($pdo, 'exam_attempts', 'total_questions', 'INT NOT NULL DEFAULT 0');
        ensureColumn($pdo, 'exam_attempts', 'status', "ENUM('in_progress','submitted','flagged','auto_submitted') NOT NULL DEFAULT 'in_progress'");
        ensureColumn($pdo, 'exam_attempts', 'violation_count', 'INT NOT NULL DEFAULT 0');

        $pdo->exec("ALTER TABLE exam_attempts MODIFY status ENUM('in_progress','submitted','flagged','auto_submitted','fail') NOT NULL DEFAULT 'in_progress'");
        $pdo->exec("UPDATE exam_attempts SET status = CASE WHEN submitted_at IS NULL THEN 'in_progress' ELSE 'submitted' END WHERE status IN ('fail', '') OR status IS NULL");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_answers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT NOT NULL,
            question_id INT NOT NULL,
            selected_answer ENUM('A','B','C','D') NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY attempt_question (attempt_id, question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS proctor_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT NOT NULL,
            user_id INT NOT NULL,
            exam_id INT NOT NULL DEFAULT 0,
            event_type VARCHAR(80) NOT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (attempt_id),
            INDEX (user_id),
            INDEX (exam_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (tableExists($pdo, 'proctor_events')) {
        ensureColumn($pdo, 'proctor_events', 'exam_id', 'INT NOT NULL DEFAULT 0');
    }
}

initializeDatabase($pdo);
