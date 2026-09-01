<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'storage.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function finish($status, $ok, $message, $details = []) { http_response_code($status); echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $details)); exit; }
function inputValue($name, $limit) { $value = trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$name] ?? '')) ?? ''); return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit); }
function safeCsv($value) { return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') finish(405, false, 'Method not allowed.');

$reference = inputValue('payment_reference', 40);
$email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$senderName = inputValue('sender_name', 120);
$senderBank = inputValue('sender_bank', 100);
$transactionReference = inputValue('transaction_reference', 120);
$transferredAt = inputValue('transferred_at', 30);
$amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
$declared = ($_POST['declaration'] ?? '') === 'yes';

if (!preg_match('/^PAY-[0-9]{6}-[A-F0-9]{8}$/', $reference) || $email === false || $senderName === '' || $senderBank === '' || $transactionReference === '' || $transferredAt === '' || $amount === false || abs((float) $amount - 167999.00) > 0.009 || !$declared) finish(422, false, 'Please complete all transfer fields accurately.');
if (!isset($_FILES['evidence']) || $_FILES['evidence']['error'] !== UPLOAD_ERR_OK) finish(422, false, 'Please attach valid payment evidence.');
if ($_FILES['evidence']['size'] < 1 || $_FILES['evidence']['size'] > 5 * 1024 * 1024) finish(422, false, 'The evidence file must be no larger than 5 MB.');

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['evidence']['tmp_name']);
$types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($types[$mime])) finish(422, false, 'Only PDF, JPG, PNG or WebP evidence is accepted.');

$base = paymentDataDirectory(true);
$requestsFile = $base . DIRECTORY_SEPARATOR . 'payment-requests.csv';
$requestMatched = false;
$requestsHandle = @fopen($requestsFile, 'rb');
if ($requestsHandle !== false) {
    while (($row = fgetcsv($requestsHandle)) !== false) {
        if (isset($row[0], $row[3], $row[5], $row[6]) && hash_equals($row[0], $reference) && strcasecmp($row[3], (string) $email) === 0 && $row[5] === 'Direct NGN bank transfer' && $row[6] === 'NGN') {
            $requestMatched = true;
            break;
        }
    }
    fclose($requestsHandle);
}
if (!$requestMatched) finish(403, false, 'The payment reference and application email could not be verified.');
$evidenceDirectory = $base . DIRECTORY_SEPARATOR . 'payment-evidence';
if (!is_dir($evidenceDirectory) && !mkdir($evidenceDirectory, 0750, true) && !is_dir($evidenceDirectory)) finish(500, false, 'Secure upload storage is unavailable.');
$filename = $reference . '-' . bin2hex(random_bytes(6)) . '.' . $types[$mime];
$destination = $evidenceDirectory . DIRECTORY_SEPARATOR . $filename;
if (!move_uploaded_file($_FILES['evidence']['tmp_name'], $destination)) finish(500, false, 'The evidence could not be stored.');

$logFile = $base . DIRECTORY_SEPARATOR . 'transfer-confirmations.csv';
$newLog = !file_exists($logFile) || filesize($logFile) === 0;
$handle = @fopen($logFile, 'ab');
if ($handle === false || !flock($handle, LOCK_EX)) { @unlink($destination); finish(500, false, 'The confirmation could not be recorded.'); }
if ($newLog) fputcsv($handle, ['Payment reference', 'Submitted at', 'Applicant email', 'Sender name', 'Sender bank', 'Amount', 'Transfer date', 'Bank reference', 'Evidence file', 'Status']);
$saved = fputcsv($handle, array_map('safeCsv', [$reference, gmdate('Y-m-d H:i:s') . ' UTC', (string) $email, $senderName, $senderBank, number_format((float) $amount, 2, '.', ''), $transferredAt, $transactionReference, $filename, 'Awaiting verification'])) !== false;
flock($handle, LOCK_UN); fclose($handle);
if (!$saved) { @unlink($destination); finish(500, false, 'The confirmation could not be recorded.'); }

// Keep a protected in-project mirror so deployments with unusual DOCUMENT_ROOT
// values still expose the same records to the administration dashboard.
$mirrorBase = paymentMirrorDirectory(true);
$mirrorEvidenceDirectory = $mirrorBase . DIRECTORY_SEPARATOR . 'payment-evidence';
if (!is_dir($mirrorEvidenceDirectory)) @mkdir($mirrorEvidenceDirectory, 0750, true);
$mirrorLog = $mirrorBase . DIRECTORY_SEPARATOR . 'transfer-confirmations.csv';
$mirrorIsNew = !is_file($mirrorLog) || filesize($mirrorLog) === 0;
$mirrorHandle = @fopen($mirrorLog, 'ab');
if ($mirrorHandle !== false && flock($mirrorHandle, LOCK_EX)) {
    if ($mirrorIsNew) fputcsv($mirrorHandle, ['Payment reference', 'Submitted at', 'Applicant email', 'Sender name', 'Sender bank', 'Amount', 'Transfer date', 'Bank reference', 'Evidence file', 'Status']);
    fputcsv($mirrorHandle, array_map('safeCsv', [$reference, gmdate('Y-m-d H:i:s') . ' UTC', (string) $email, $senderName, $senderBank, number_format((float) $amount, 2, '.', ''), $transferredAt, $transactionReference, $filename, 'Awaiting verification']));
    flock($mirrorHandle, LOCK_UN);
    fclose($mirrorHandle);
    @copy($destination, $mirrorEvidenceDirectory . DIRECTORY_SEPARATOR . $filename);
}

$subject = "Transfer awaiting verification — {$reference}";
$body = "NGN transfer evidence has been submitted.\n\nPayment reference: {$reference}\nApplicant email: {$email}\nSender: {$senderName}\nSending bank: {$senderBank}\nAmount: NGN " . number_format((float) $amount, 2) . "\nTransfer date: {$transferredAt}\nBank reference: {$transactionReference}\nEvidence file: {$filename}\nStatus: Awaiting verification\n";
$headers = ['From: Femi Ajao Website <hello@femiajao.com>', 'Reply-To: ' . $email, 'Content-Type: text/plain; charset=UTF-8'];
@mail('hello@femiajao.com', $subject, $body, implode("\r\n", $headers));
finish(200, true, 'Evidence received and awaiting verification.', ['reference' => $reference, 'status' => 'Awaiting verification']);
