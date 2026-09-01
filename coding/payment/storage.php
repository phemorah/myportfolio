<?php
declare(strict_types=1);

function paymentDataDirectories(): array
{
    $projectRoot = dirname(__DIR__, 2);
    $directories = [dirname($projectRoot) . DIRECTORY_SEPARATOR . 'application_data'];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $documentRoot = rtrim((string) $_SERVER['DOCUMENT_ROOT'], "\\/");
        $directories[] = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'application_data';
    }
    $unique = [];
    foreach ($directories as $directory) {
        $key = str_replace('\\', '/', $directory);
        if (!isset($unique[$key])) $unique[$key] = $directory;
    }
    return array_values($unique);
}

function paymentDataDirectory(bool $create = false): string
{
    $directories = paymentDataDirectories();
    foreach ($directories as $directory) {
        if (is_file($directory . DIRECTORY_SEPARATOR . 'payment-config.php')) return $directory;
    }
    $directory = $directories[0];
    if ($create && !is_dir($directory)) @mkdir($directory, 0755, true);
    return $directory;
}
