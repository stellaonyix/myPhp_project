<?php

function requireLogin(string $redirect = 'login.php'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        header("Location: $redirect");
        exit();
    }
}

function requireAdmin(): void
{
    requireLogin('../login.php');

    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('Access Denied');
    }
}

function csrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $posted = $_POST['csrf_token'] ?? '';

    if (!$posted || !hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
        http_response_code(419);
        die('Invalid form token. Please refresh and try again.');
    }
}

function cleanText(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value));
}

function percentageScore(int $score, int $total): int
{
    if ($total <= 0) {
        return 0;
    }

    return (int) round(($score / $total) * 100);
}

function normalizeCorrectAnswer(string $answer): string
{
    $answer = strtoupper(trim($answer));
    $answer = preg_replace('/^OPTION\s+/', '', $answer);
    return in_array($answer, ['A', 'B', 'C', 'D'], true) ? $answer : '';
}

function validateQuestionImportRow(array $row): array
{
    $errors = [];

    foreach (['question', 'option_a', 'option_b', 'option_c', 'option_d'] as $field) {
        if (trim((string) ($row[$field] ?? '')) === '') {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }

    if (normalizeCorrectAnswer((string) ($row['correct_answer'] ?? '')) === '') {
        $errors[] = 'Correct Answer must be A, B, C, or D.';
    }

    $marks = filter_var($row['marks'] ?? null, FILTER_VALIDATE_INT);
    if ($marks === false || $marks < 1 || $marks > 1000) {
        $errors[] = 'Marks must be a number from 1 to 1000.';
    }

    return $errors;
}

function xlsxColumnIndex(string $cellReference): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellReference));
    $index = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

function xlsxCellValue(SimpleXMLElement $cell, array $sharedStrings): string
{
    $type = (string) ($cell['t'] ?? '');

    if ($type === 'inlineStr') {
        return trim((string) ($cell->is->t ?? ''));
    }

    $value = trim((string) ($cell->v ?? ''));

    if ($type === 's') {
        return $sharedStrings[(int) $value] ?? '';
    }

    if ($type === 'b') {
        return $value === '1' ? 'TRUE' : 'FALSE';
    }

    return $value;
}

function parseQuestionXlsxRows(string $filePath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel upload requires the PHP Zip extension.');
    }

    if (!function_exists('simplexml_load_string')) {
        throw new RuntimeException('Excel upload requires the PHP SimpleXML extension.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException('The uploaded Excel file could not be opened.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

    if ($sharedXml !== false) {
        $shared = simplexml_load_string($sharedXml);
        if ($shared) {
            foreach ($shared->si as $item) {
                $text = '';
                if (isset($item->t)) {
                    $text = (string) $item->t;
                } elseif (isset($item->r)) {
                    foreach ($item->r as $run) {
                        $text .= (string) ($run->t ?? '');
                    }
                }
                $sharedStrings[] = trim($text);
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false) {
        throw new RuntimeException('The Excel file must contain a first worksheet.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData->row)) {
        throw new RuntimeException('No readable rows were found in the Excel file.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = array_fill(0, 7, '');

        foreach ($row->c as $cell) {
            $columnIndex = xlsxColumnIndex((string) ($cell['r'] ?? ''));
            if ($columnIndex >= 0 && $columnIndex < 7) {
                $cells[$columnIndex] = xlsxCellValue($cell, $sharedStrings);
            }
        }

        $rows[] = $cells;
    }

    if (!$rows) {
        return [];
    }

    array_shift($rows);
    $questionRows = [];

    foreach ($rows as $row) {
        $hasContent = false;
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                $hasContent = true;
                break;
            }
        }

        if (!$hasContent) {
            continue;
        }

        $questionRows[] = [
            'question' => cleanText((string) $row[0]),
            'option_a' => cleanText((string) $row[1]),
            'option_b' => cleanText((string) $row[2]),
            'option_c' => cleanText((string) $row[3]),
            'option_d' => cleanText((string) $row[4]),
            'correct_answer' => normalizeCorrectAnswer((string) $row[5]),
            'marks' => (int) ($row[6] ?: 1),
        ];
    }

    return $questionRows;
}

function parseQuestionCsvRows(string $filePath): array
{
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The uploaded CSV file could not be opened.');
    }

    $rows = [];
    $isFirstRow = true;

    while (($row = fgetcsv($handle)) !== false) {
        if ($isFirstRow) {
            $isFirstRow = false;
            continue;
        }

        $row = array_pad($row, 7, '');
        $row = array_slice($row, 0, 7);

        $hasContent = false;
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                $hasContent = true;
                break;
            }
        }

        if (!$hasContent) {
            continue;
        }

        $rows[] = [
            'question' => cleanText((string) $row[0]),
            'option_a' => cleanText((string) $row[1]),
            'option_b' => cleanText((string) $row[2]),
            'option_c' => cleanText((string) $row[3]),
            'option_d' => cleanText((string) $row[4]),
            'correct_answer' => normalizeCorrectAnswer((string) $row[5]),
            'marks' => (int) ($row[6] ?: 1),
        ];
    }

    fclose($handle);
    return $rows;
}

function parseQuestionUploadRows(string $filePath, string $fileName): array
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if ($extension === 'csv') {
        return parseQuestionCsvRows($filePath);
    }

    if ($extension === 'xlsx') {
        return parseQuestionXlsxRows($filePath);
    }

    throw new RuntimeException('Only .csv and .xlsx question files are supported.');
}

function downloadQuestionCsvTemplate(): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="examshield-question-template.csv"');

    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Correct Answer', 'Marks']);
    fputcsv($output, ['What does HTML stand for?', 'Hyper Text Markup Language', 'High Text Machine Language', 'Hyper Tool Multi Language', 'Home Tool Markup Language', 'A', '1']);
    fclose($output);
    exit();
}

function downloadQuestionTemplate(): void
{
    if (!class_exists('ZipArchive')) {
        downloadQuestionCsvTemplate();
    }

    $tempPath = tempnam(sys_get_temp_dir(), 'examshield-template-');
    $zip = new ZipArchive();

    if ($zip->open($tempPath, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        die('Could not create template file.');
    }

    $cell = function (string $reference, string $value): string {
        return '<c r="' . $reference . '" t="inlineStr"><is><t>' .
            htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') .
            '</t></is></c>';
    };

    $headers = ['Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Correct Answer', 'Marks'];
    $sample = ['What does HTML stand for?', 'Hyper Text Markup Language', 'High Text Machine Language', 'Hyper Tool Multi Language', 'Home Tool Markup Language', 'A', '1'];
    $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    $rowsXml = '';
    foreach ([$headers, $sample] as $rowNumber => $values) {
        $excelRow = $rowNumber + 1;
        $rowsXml .= '<row r="' . $excelRow . '">';
        foreach ($values as $index => $value) {
            $rowsXml .= $cell($columns[$index] . $excelRow, $value);
        }
        $rowsXml .= '</row>';
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Questions" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetData>' . $rowsXml . '</sheetData></worksheet>');
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="examshield-question-template.xlsx"');
    header('Content-Length: ' . filesize($tempPath));
    readfile($tempPath);
    unlink($tempPath);
    exit();
}

function examResultStatus(int $score): array
{
    if ($score >= 70) {
        return [
            'label' => 'Excellent',
            'class' => 'excellent',
            'pass_mark' => 60,
        ];
    }

    if ($score >= 60) {
        return [
            'label' => 'Passed',
            'class' => '',
            'pass_mark' => 60,
        ];
    }

    return [
        'label' => 'Failed',
        'class' => 'fail',
        'pass_mark' => 60,
    ];
}

function passwordValidationErrors(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must include at least one capital letter.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must include at least one small letter.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must include at least one number.';
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must include at least one special character.';
    }

    return $errors;
}

function isStrongPassword(string $password): bool
{
    return passwordValidationErrors($password) === [];
}
