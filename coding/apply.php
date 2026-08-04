<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond($status, $ok, $message, $details = [])
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $details));
    exit;
}

function clean($value, $maxLength)
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength)
        : substr($value, 0, $maxLength);
}

function csvSafe($value)
{
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Method not allowed.');
}

$name = clean((string) ($_POST['name'] ?? ''), 120);
$email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$phone = clean((string) ($_POST['phone'] ?? ''), 40);
$goal = clean((string) ($_POST['goal'] ?? ''), 2000);
$privacyConsent = ($_POST['privacy_consent'] ?? '') === 'yes';
$marketingConsent = ($_POST['marketing_consent'] ?? '') === 'yes';

if ($name === '' || $email === false || $phone === '' || $goal === '' || !$privacyConsent) {
    respond(422, false, 'Please complete all required fields with valid information.');
}

if (!preg_match('/^[0-9+()\-\s]{7,40}$/', $phone)) {
    respond(422, false, 'Please enter a valid WhatsApp number.');
}

$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== ''
    ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR)
    : dirname(__DIR__);
$storageDirectory = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'application_data';
$storageFile = $storageDirectory . DIRECTORY_SEPARATOR . 'coding-applications.csv';

if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0755, true) && !is_dir($storageDirectory)) {
    respond(500, false, 'The application could not be stored.');
}

if (!is_writable($storageDirectory)) {
    respond(500, false, 'The application storage directory is not writable.');
}

$isNewFile = !file_exists($storageFile) || filesize($storageFile) === 0;
$handle = fopen($storageFile, 'ab');
if ($handle === false || !flock($handle, LOCK_EX)) {
    respond(500, false, 'The application could not be stored.');
}

if ($isNewFile) {
    fputcsv($handle, ['Submitted at', 'Name', 'Email', 'WhatsApp', 'Goal', 'Future updates consent', 'IP address']);
}

$submittedAt = gmdate('Y-m-d H:i:s') . ' UTC';
$ipAddress = clean((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 45);
$saved = fputcsv($handle, array_map('csvSafe', [
    $submittedAt,
    $name,
    (string) $email,
    $phone,
    $goal,
    $marketingConsent ? 'Yes' : 'No',
    $ipAddress,
])) !== false;

flock($handle, LOCK_UN);
fclose($handle);

if (!$saved) {
    respond(500, false, 'The application could not be stored.');
}

// Keep a protected mirror in the website folder for easy access in hPanel.
$mirrorFile = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'coding-applications.csv';
$mirrorDirectory = dirname($mirrorFile);
if (!is_dir($mirrorDirectory)) {
    @mkdir($mirrorDirectory, 0755, true);
}
if (is_writable($mirrorDirectory)) {
    $mirrorIsNew = !file_exists($mirrorFile) || filesize($mirrorFile) === 0;
    $mirrorHandle = @fopen($mirrorFile, 'ab');
    if ($mirrorHandle !== false && flock($mirrorHandle, LOCK_EX)) {
        if ($mirrorIsNew) {
            fputcsv($mirrorHandle, ['Submitted at', 'Name', 'Email', 'WhatsApp', 'Goal', 'Future updates consent', 'IP address']);
        }
        fputcsv($mirrorHandle, array_map('csvSafe', [
            $submittedAt, $name, (string) $email, $phone, $goal,
            $marketingConsent ? 'Yes' : 'No', $ipAddress,
        ]));
        flock($mirrorHandle, LOCK_UN);
        fclose($mirrorHandle);
    }
}

$subject = 'New coding programme application — ' . $name;
$body = "A new coding programme application has been received.\n\n"
    . "Submitted: {$submittedAt}\n"
    . "Name: {$name}\n"
    . "Email: {$email}\n"
    . "WhatsApp: {$phone}\n"
    . 'Future updates consent: ' . ($marketingConsent ? 'Yes' : 'No') . "\n\n"
    . "Goal:\n{$goal}\n";
$headers = [
    'From: Femi Ajao Website <hello@femiajao.com>',
    'Reply-To: ' . $email,
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . phpversion(),
];

// Storage is the durable source of truth; email is a notification channel.
$emailQueued = @mail('hello@femiajao.com', $subject, $body, implode("\r\n", $headers));

clearstatcache(true, $storageFile);
$lineCount = 0;
$countHandle = @fopen($storageFile, 'rb');
if ($countHandle !== false) {
    while (fgetcsv($countHandle) !== false) {
        $lineCount++;
    }
    fclose($countHandle);
}

respond(200, true, 'Application saved successfully.', [
    'saved_records' => max(0, $lineCount - 1),
    'storage' => 'application_data/coding-applications.csv',
    'file_manager_mirror' => 'public_html/coding/storage/coding-applications.csv',
    'email_queued' => $emailQueued,
]);
