<?php
session_start();
require '../config/database.php';
require '../includes/functions.php';

requireAdmin();

if (isset($_GET['download_template'])) {
    downloadQuestionTemplate();
}

$message = '';
$messageClass = 'notice';
$previewRows = [];
$previewExamId = (int) ($_POST['exam_id'] ?? 0);
$previewHasErrors = false;
$supportsXlsx = class_exists('ZipArchive');
$allowedExtensions = $supportsXlsx ? ['csv', 'xlsx'] : ['csv'];
$questionFileLabel = $supportsXlsx ? 'Question File (.csv or .xlsx)' : 'Question File (.csv)';
$questionFileAccept = $supportsXlsx ? '.csv,.xlsx' : '.csv';

$exams = $pdo->query("SELECT * FROM exams WHERE is_active = 1 ORDER BY title")->fetchAll();

function adminExamExists(PDO $pdo, int $examId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE id = ? AND is_active = 1");
    $stmt->execute([$examId]);
    return (bool) $stmt->fetchColumn();
}

function buildPreviewRows(array $rows): array
{
    $preview = [];

    foreach ($rows as $index => $row) {
        $row = [
            'question' => cleanText((string) ($row['question'] ?? '')),
            'option_a' => cleanText((string) ($row['option_a'] ?? '')),
            'option_b' => cleanText((string) ($row['option_b'] ?? '')),
            'option_c' => cleanText((string) ($row['option_c'] ?? '')),
            'option_d' => cleanText((string) ($row['option_d'] ?? '')),
            'correct_answer' => normalizeCorrectAnswer((string) ($row['correct_answer'] ?? '')),
            'marks' => (int) ($row['marks'] ?? 1),
            'remove' => !empty($row['remove']),
        ];

        $row['errors'] = $row['remove'] ? [] : validateQuestionImportRow($row);
        $row['row_number'] = $index + 1;
        $preview[] = $row;
    }

    return $preview;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $previewExamId = (int) ($_POST['exam_id'] ?? 0);

    if (!adminExamExists($pdo, $previewExamId)) {
        $message = 'Select a valid active exam before uploading questions.';
        $messageClass = 'notice error';
    } elseif ($action === 'preview') {
        $file = $_FILES['questions_file'] ?? null;

        $extension = $file ? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) : '';

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $message = 'Upload a valid ' . ($supportsXlsx ? '.csv or .xlsx' : '.csv') . ' file.';
            $messageClass = 'notice error';
        } elseif (!in_array($extension, $allowedExtensions, true)) {
            $message = $supportsXlsx
                ? 'Only .csv and .xlsx question files are supported.'
                : 'Only .csv question files are supported on this server. Enable the PHP Zip extension to upload .xlsx files.';
            $messageClass = 'notice error';
        } elseif ((int) $file['size'] > 5 * 1024 * 1024) {
            $message = 'The question file is too large. Keep uploads below 5MB.';
            $messageClass = 'notice error';
        } else {
            try {
                $previewRows = buildPreviewRows(parseQuestionUploadRows($file['tmp_name'], $file['name']));

                if (!$previewRows) {
                    $message = 'No question rows were found. Keep the first row as headings and add questions below it.';
                    $messageClass = 'notice error';
                } else {
                    foreach ($previewRows as $row) {
                        if (!empty($row['errors'])) {
                            $previewHasErrors = true;
                            break;
                        }
                    }

                    $message = $previewHasErrors
                        ? 'Preview loaded. Fix the highlighted rows before importing.'
                        : 'Preview loaded. Review, edit, or remove rows before final import.';
                    $messageClass = $previewHasErrors ? 'notice warn' : 'notice';
                }
            } catch (Throwable $e) {
                $message = $e->getMessage();
                $messageClass = 'notice error';
            }
        }
    } elseif ($action === 'import') {
        $postedRows = $_POST['rows'] ?? [];
        $normalizedRows = [];

        foreach ($postedRows as $row) {
            $normalizedRows[] = [
                'question' => $row['question'] ?? '',
                'option_a' => $row['option_a'] ?? '',
                'option_b' => $row['option_b'] ?? '',
                'option_c' => $row['option_c'] ?? '',
                'option_d' => $row['option_d'] ?? '',
                'correct_answer' => $row['correct_answer'] ?? '',
                'marks' => $row['marks'] ?? 1,
                'remove' => !empty($row['remove']),
            ];
        }

        $previewRows = buildPreviewRows($normalizedRows);
        $rowsToImport = array_values(array_filter($previewRows, fn($row) => empty($row['remove'])));

        foreach ($rowsToImport as $row) {
            if (!empty($row['errors'])) {
                $previewHasErrors = true;
                break;
            }
        }

        if (!$rowsToImport) {
            $message = 'At least one valid question must remain before import.';
            $messageClass = 'notice error';
        } elseif ($previewHasErrors) {
            $message = 'Some rows still have errors. Fix them or mark them for removal.';
            $messageClass = 'notice warn';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO questions
                (exam_id, question, option_a, option_b, option_c, option_d, correct_answer, marks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $pdo->beginTransaction();
            foreach ($rowsToImport as $row) {
                $stmt->execute([
                    $previewExamId,
                    $row['question'],
                    $row['option_a'],
                    $row['option_b'],
                    $row['option_c'],
                    $row['option_d'],
                    $row['correct_answer'],
                    (int) $row['marks'],
                ]);
            }
            $pdo->commit();

            $message = count($rowsToImport) . ' question(s) imported successfully.';
            $messageClass = 'notice success';
            $previewRows = [];
            $previewHasErrors = false;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Upload Questions</title>
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

<main class="page-shell">
<div class="page-heading">
    <div>
        <h2>Upload Questions</h2>
        <p class="muted">Create an exam first, then upload its questions with the question template.</p>
    </div>
    <a class="btn btn-secondary" href="add-question.php?download_template=1">Download Template</a>
</div>

<?php if($message): ?>
<p class="<?= htmlspecialchars($messageClass); ?>"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="upload-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">
    <input type="hidden" name="action" value="preview">

    <div class="form-row">
        <label for="exam_id">Select Exam</label>
        <select id="exam_id" name="exam_id" required>
            <option value="">Choose an active exam</option>
            <?php foreach($exams as $exam): ?>
            <option value="<?= (int) $exam['id']; ?>" <?= $previewExamId === (int) $exam['id'] ? 'selected' : ''; ?>>
                <?= htmlspecialchars($exam['title']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-row">
        <label for="questions_file"><?= htmlspecialchars($questionFileLabel); ?></label>
        <input id="questions_file" type="file" name="questions_file" accept="<?= htmlspecialchars($questionFileAccept); ?>" required>
    </div>

    <button type="submit">Upload Questions</button>
</form>

<?php if (!empty($previewRows)): ?>
<section class="import-preview">
    <div class="page-heading compact">
        <div>
            <h3>Preview Questions</h3>
            <p class="muted">Edit any cell or tick Remove before saving the final import.</p>
        </div>
        <span class="status-badge <?= $previewHasErrors ? 'warn' : 'excellent'; ?>">
            <?= $previewHasErrors ? 'Needs review' : count($previewRows) . ' rows ready'; ?>
        </span>
    </div>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()); ?>">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="exam_id" value="<?= (int) $previewExamId; ?>">

        <div class="table-wrap import-table-wrap">
        <table class="import-table">
            <tr>
                <th>Remove</th>
                <th>Question</th>
                <th>Option A</th>
                <th>Option B</th>
                <th>Option C</th>
                <th>Option D</th>
                <th>Correct</th>
                <th>Marks</th>
                <th>Status</th>
            </tr>
            <?php foreach ($previewRows as $index => $row): ?>
            <tr class="<?= !empty($row['errors']) ? 'has-errors' : ''; ?>">
                <td>
                    <input type="checkbox" name="rows[<?= $index; ?>][remove]" value="1" <?= !empty($row['remove']) ? 'checked' : ''; ?>>
                </td>
                <td><textarea name="rows[<?= $index; ?>][question]" required><?= htmlspecialchars($row['question']); ?></textarea></td>
                <td><input type="text" name="rows[<?= $index; ?>][option_a]" value="<?= htmlspecialchars($row['option_a']); ?>" required></td>
                <td><input type="text" name="rows[<?= $index; ?>][option_b]" value="<?= htmlspecialchars($row['option_b']); ?>" required></td>
                <td><input type="text" name="rows[<?= $index; ?>][option_c]" value="<?= htmlspecialchars($row['option_c']); ?>" required></td>
                <td><input type="text" name="rows[<?= $index; ?>][option_d]" value="<?= htmlspecialchars($row['option_d']); ?>" required></td>
                <td>
                    <select name="rows[<?= $index; ?>][correct_answer]" required>
                        <?php foreach (['A', 'B', 'C', 'D'] as $answer): ?>
                        <option value="<?= $answer; ?>" <?= $row['correct_answer'] === $answer ? 'selected' : ''; ?>><?= $answer; ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" min="1" max="1000" name="rows[<?= $index; ?>][marks]" value="<?= (int) $row['marks']; ?>" required></td>
                <td>
                    <?php if (!empty($row['errors'])): ?>
                        <span class="status-badge fail">Invalid</span>
                        <small><?= htmlspecialchars(implode(' ', $row['errors'])); ?></small>
                    <?php else: ?>
                        <span class="status-badge">Ready</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>

        <button type="submit">Save Imported Questions</button>
    </form>
</section>
<?php endif; ?>

<p><a href="dashboard.php">Back to Dashboard</a></p>
</main>

<script src="../assets/js/app.js"></script>

</body>
</html>
