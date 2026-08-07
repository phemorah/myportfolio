<?php
declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow', true);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

function escape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function redirectAdmin() { header('Location: ./'); exit; }

$documentRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim((string) $_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR) : dirname(dirname(dirname(__DIR__)));
$dataDirectory = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'application_data';
$configFile = $dataDirectory . DIRECTORY_SEPARATOR . 'payment-config.php';
$config = [];
if (file_exists($configFile)) {
    define('PAYMENT_APP', true);
    $loadedConfig = require $configFile;
    if (is_array($loadedConfig)) $config = $loadedConfig;
}
$adminHash = (string) ($config['admin']['password_hash'] ?? '');
$bookingUrl = (string) ($config['admin']['booking_url'] ?? '');
$configured = $adminHash !== '' && $adminHash !== 'PASTE_PASSWORD_HASH_HERE';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    redirectAdmin();
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
    $lockedUntil = (int) ($_SESSION['locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        $loginError = 'Too many attempts. Try again in a few minutes.';
    } elseif ($configured && password_verify((string) $_POST['login_password'], $adminHash)) {
        session_regenerate_id(true);
        $_SESSION['payment_admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        $_SESSION['login_attempts'] = 0;
        redirectAdmin();
    } else {
        $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['locked_until'] = time() + 900;
            $_SESSION['login_attempts'] = 0;
        }
        $loginError = 'The password is incorrect.';
    }
}

$authenticated = !empty($_SESSION['payment_admin']);
if (!$authenticated):
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Payment Administration | Femi Ajao</title><link rel="icon" href="../../../img/favico.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="./admin.css"></head><body class="login-page"><main class="login-card"><img src="../../../img/favico.png" width="52" height="52" alt="Femi Ajao"><span>Private administration</span><h1>Payment verification</h1><?php if (!$configured): ?><div class="alert error">Administrator access is not configured. Add an admin password hash to the private payment configuration.</div><?php else: ?><p>Sign in to review payment evidence and update verification status.</p><?php if ($loginError): ?><div class="alert error"><?= escape($loginError) ?></div><?php endif; ?><form method="post"><label>Administrator password<input type="password" name="login_password" autocomplete="current-password" required autofocus></label><button type="submit">Sign in →</button></form><?php endif; ?></main></body></html><?php exit; endif;

$confirmationsFile = $dataDirectory . DIRECTORY_SEPARATOR . 'transfer-confirmations.csv';
$requestsFile = $dataDirectory . DIRECTORY_SEPARATOR . 'payment-requests.csv';
$evidenceDirectory = $dataDirectory . DIRECTORY_SEPARATOR . 'payment-evidence';

function readRows($file)
{
    $rows = [];
    $handle = @fopen($file, 'rb');
    if ($handle === false) return $rows;
    $header = fgetcsv($handle);
    while (($row = fgetcsv($handle)) !== false) {
        if ($header && count($header) === count($row)) $rows[] = array_combine($header, $row);
    }
    fclose($handle);
    return $rows;
}

function updateStatus($file, $reference, $statusColumn, $status)
{
    $input = @fopen($file, 'rb');
    if ($input === false || !flock($input, LOCK_SH)) return false;
    $temp = tempnam(dirname($file), 'payment-');
    $output = $temp ? @fopen($temp, 'wb') : false;
    if ($output === false) { flock($input, LOCK_UN); fclose($input); return false; }
    $updated = false;
    while (($row = fgetcsv($input)) !== false) {
        if (isset($row[0]) && hash_equals($row[0], $reference)) {
            $row[$statusColumn] = $status;
            $updated = true;
        }
        fputcsv($output, $row);
    }
    fflush($output); fclose($output); flock($input, LOCK_UN); fclose($input);
    if (!$updated || !@rename($temp, $file)) { @unlink($temp); return false; }
    return true;
}

$confirmations = readRows($confirmationsFile);

if (isset($_GET['download'])) {
    $requested = basename((string) $_GET['download']);
    $allowed = false;
    foreach ($confirmations as $confirmation) {
        if (($confirmation['Evidence file'] ?? '') === $requested) { $allowed = true; break; }
    }
    $path = $evidenceDirectory . DIRECTORY_SEPARATOR . $requested;
    if (!$allowed || !is_file($path)) { http_response_code(404); exit('Evidence not found.'); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode($requested) . '"');
    readfile($path); exit;
}

if (isset($_GET['receipt'])) {
    $receiptReference = (string) $_GET['receipt'];
    $receipt = null;
    foreach ($confirmations as $confirmation) {
        if (($confirmation['Payment reference'] ?? '') === $receiptReference && ($confirmation['Status'] ?? '') === 'Paid') { $receipt = $confirmation; break; }
    }
    if (!$receipt) { http_response_code(404); exit('Paid receipt not found.'); }
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Receipt <?= escape($receiptReference) ?></title><style>body{margin:0;background:#f4f4f2;color:#0c1838;font:14px Arial,sans-serif}.receipt{max-width:720px;margin:50px auto;padding:55px;background:#fff;border-top:8px solid #071943}.top{display:flex;justify-content:space-between;gap:30px;border-bottom:1px solid #ddd;padding-bottom:30px}.brand{font-size:25px;font-weight:800}.paid{color:#07834e;font-weight:800}.details{margin:35px 0}.row{display:grid;grid-template-columns:180px 1fr;padding:13px 0;border-bottom:1px solid #eee}.row span{color:#687080}.amount{margin:35px 0;padding:25px;background:#071943;color:#fff}.amount span,.amount strong{display:block}.amount strong{margin-top:6px;font-size:34px}.note{color:#687080;font-size:12px}.actions{max-width:720px;margin:20px auto;text-align:right}.actions button{padding:12px 18px;border:0;background:#ff7100;color:#fff;font-weight:700;cursor:pointer}@media print{body{background:#fff}.receipt{margin:0;max-width:none}.actions{display:none}}</style></head><body><div class="receipt"><div class="top"><div><div class="brand">Femi Ajao</div><div>Full-Stack Development Mentorship</div></div><div><strong>PAYMENT RECEIPT</strong><div class="paid">PAID</div></div></div><div class="details"><div class="row"><span>Payment reference</span><strong><?= escape($receipt['Payment reference'] ?? '') ?></strong></div><div class="row"><span>Applicant email</span><strong><?= escape($receipt['Applicant email'] ?? '') ?></strong></div><div class="row"><span>Name on account</span><strong><?= escape($receipt['Sender name'] ?? '') ?></strong></div><div class="row"><span>Transfer date</span><strong><?= escape($receipt['Transfer date'] ?? '') ?></strong></div><div class="row"><span>Bank reference</span><strong><?= escape($receipt['Bank reference'] ?? '') ?></strong></div></div><div class="amount"><span>Amount received</span><strong>₦<?= escape(number_format((float) ($receipt['Amount'] ?? 0), 2)) ?></strong></div><p class="note">Payment for Personalised Full-Stack Application Development Mentorship — 8 Sessions. This receipt records payment verification and is not a tax invoice.</p></div><div class="actions"><button onclick="window.print()">Print / Save as PDF</button></div></body></html><?php exit;
}

$flash = '';
$flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_action'])) {
    if (!isset($_POST['csrf']) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) $_POST['csrf'])) { http_response_code(403); exit('Invalid request token.'); }
    $reference = (string) ($_POST['reference'] ?? '');
    $action = (string) $_POST['payment_action'];
    $newStatus = $action === 'confirm' ? 'Paid' : ($action === 'review' ? 'Needs review' : '');
    if (!preg_match('/^PAY-[0-9]{6}-[A-F0-9]{8}$/', $reference) || $newStatus === '') { http_response_code(422); exit('Invalid payment action.'); }
    $confirmationUpdated = updateStatus($confirmationsFile, $reference, 9, $newStatus);
    $requestUpdated = updateStatus($requestsFile, $reference, 7, $newStatus);
    if ($confirmationUpdated && $requestUpdated) {
        $flash = "{$reference} marked {$newStatus}.";
        if ($newStatus === 'Paid') {
            $confirmations = readRows($confirmationsFile);
            foreach ($confirmations as $item) {
                if (($item['Payment reference'] ?? '') === $reference) {
                    $recipient = (string) ($item['Applicant email'] ?? '');
                    if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                        $subject = "Payment confirmed — {$reference}";
                        $body = "Hello,\n\nYour payment of NGN 167,999.00 for the Personalised Full-Stack Application Development Mentorship has been verified.\n\nPayment reference: {$reference}\nStatus: Paid\n";
                        if ($bookingUrl !== '') $body .= "\nBook your first session here: {$bookingUrl}\n";
                        else $body .= "\nFemi will contact you shortly to arrange your first session.\n";
                        $body .= "\nThank you,\nFemi Ajao\n";
                        @mail($recipient, $subject, $body, implode("\r\n", ['From: Femi Ajao <hello@femiajao.com>', 'Reply-To: hello@femiajao.com', 'Content-Type: text/plain; charset=UTF-8']));
                    }
                    break;
                }
            }
        }
    } else { $flash = 'The payment status could not be updated.'; $flashType = 'error'; }
    $confirmations = readRows($confirmationsFile);
}

$counts = ['Awaiting verification' => 0, 'Paid' => 0, 'Needs review' => 0];
foreach ($confirmations as $item) { $status = $item['Status'] ?? ''; if (isset($counts[$status])) $counts[$status]++; }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Payment Administration | Femi Ajao</title><link rel="icon" href="../../../img/favico.png"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="./admin.css"></head>
<body><header><a class="identity" href="../../../"><img src="../../../img/favico.png" width="42" height="42" alt=""><span><strong>Payment administration</strong><small>Femi Ajao Coding Programme</small></span></a><a class="logout" href="?logout=1">Sign out</a></header><main><div class="page-title"><div><span>Private dashboard</span><h1>Transfer verification</h1><p>Confirm funds in Stanbic IBTC before marking any submission as paid.</p></div><a class="refresh" href="./">Refresh records</a></div><?php if ($flash): ?><div class="alert <?= escape($flashType) ?>"><?= escape($flash) ?></div><?php endif; ?><section class="metrics"><article><span>Awaiting verification</span><strong><?= $counts['Awaiting verification'] ?></strong></article><article><span>Paid</span><strong><?= $counts['Paid'] ?></strong></article><article><span>Needs review</span><strong><?= $counts['Needs review'] ?></strong></article><article><span>Total submissions</span><strong><?= count($confirmations) ?></strong></article></section><section class="records"><div class="records-heading"><h2>Payment evidence</h2><p>Uploaded receipts support your review but do not prove that funds were received.</p></div><?php if (!$confirmations): ?><div class="empty">No payment evidence has been submitted yet.</div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Reference</th><th>Applicant</th><th>Transfer</th><th>Evidence</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach (array_reverse($confirmations) as $item): $status = $item['Status'] ?? 'Awaiting verification'; ?><tr><td><strong><?= escape($item['Payment reference'] ?? '') ?></strong><small><?= escape($item['Submitted at'] ?? '') ?></small></td><td><?= escape($item['Sender name'] ?? '') ?><small><?= escape($item['Applicant email'] ?? '') ?></small></td><td><strong>₦<?= escape(number_format((float) ($item['Amount'] ?? 0), 2)) ?></strong><small><?= escape($item['Sender bank'] ?? '') ?> · <?= escape($item['Bank reference'] ?? '') ?></small></td><td><a class="evidence" href="?download=<?= rawurlencode($item['Evidence file'] ?? '') ?>" target="_blank" rel="noopener">Open evidence ↗</a></td><td><span class="status status-<?= escape(strtolower(str_replace(' ', '-', $status))) ?>"><?= escape($status) ?></span></td><td><form method="post" class="actions"><input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf'] ?? '') ?>"><input type="hidden" name="reference" value="<?= escape($item['Payment reference'] ?? '') ?>"><button name="payment_action" value="confirm" type="submit" onclick="return confirm('Confirm that cleared funds are visible in the bank account?')">Confirm paid</button><button name="payment_action" value="review" type="submit">Flag</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section></main></body></html>
