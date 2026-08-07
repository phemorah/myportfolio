<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function reply($status, $ok, $message, $details = [])
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $details));
    exit;
}

function field($name, $limit)
{
    $value = trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$name] ?? '')) ?? '');
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function csvValue($value)
{
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, false, 'Method not allowed.');

$name = field('name', 120);
$email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$phone = field('phone', 40);
$method = field('method', 80);
$currency = field('currency', 3);
$confirmed = ($_POST['confirmation'] ?? '') === 'yes';
$methods = ['Paystack — USD card', 'Paystack — NGN', 'USD bank transfer', 'Stablecoin — USDC/USDT'];

if ($name === '' || $email === false || !preg_match('/^[0-9+()\-\s]{7,40}$/', $phone) || !in_array($method, $methods, true) || !in_array($currency, ['USD', 'NGN'], true) || !$confirmed) {
    reply(422, false, 'Please complete all required fields with valid information.');
}

$documentRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) : dirname(dirname(__DIR__));
$directory = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'application_data';
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) reply(500, false, 'Storage is unavailable.');

$file = $directory . DIRECTORY_SEPARATOR . 'payment-requests.csv';
$newFile = !file_exists($file) || filesize($file) === 0;
$handle = @fopen($file, 'ab');
if ($handle === false || !flock($handle, LOCK_EX)) reply(500, false, 'Storage is unavailable.');

$reference = 'PAY-' . gmdate('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$submitted = gmdate('Y-m-d H:i:s') . ' UTC';
if ($newFile) fputcsv($handle, ['Reference', 'Submitted at', 'Name', 'Email', 'WhatsApp', 'Method', 'Currency', 'Status']);
$saved = fputcsv($handle, array_map('csvValue', [$reference, $submitted, $name, (string) $email, $phone, $method, $currency, 'Payment details requested'])) !== false;
flock($handle, LOCK_UN);
fclose($handle);
if (!$saved) reply(500, false, 'The request could not be stored.');

$subject = "Payment request {$reference} — {$name}";
$body = "A mentorship payment request has been received.\n\nReference: {$reference}\nName: {$name}\nEmail: {$email}\nWhatsApp: {$phone}\nMethod: {$method}\nCurrency: {$currency}\nSubmitted: {$submitted}\n";
$headers = ['From: Femi Ajao Website <hello@femiajao.com>', 'Reply-To: ' . $email, 'Content-Type: text/plain; charset=UTF-8'];
@mail('hello@femiajao.com', $subject, $body, implode("\r\n", $headers));

reply(200, true, 'Payment request saved.', ['reference' => $reference]);
