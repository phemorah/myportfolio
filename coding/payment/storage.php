<?php
declare(strict_types=1);

function paymentDataDirectories(): array
{
    $projectRoot = dirname(__DIR__, 2);
    $directories = [
        dirname($projectRoot) . DIRECTORY_SEPARATOR . 'application_data',
        $projectRoot . DIRECTORY_SEPARATOR . 'application_data',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage',
    ];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $documentRoot = rtrim((string) $_SERVER['DOCUMENT_ROOT'], "\\/");
        $directories[] = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'application_data';
        $realDocumentRoot = realpath($documentRoot);
        if ($realDocumentRoot !== false) {
            $directories[] = dirname($realDocumentRoot) . DIRECTORY_SEPARATOR . 'application_data';
        }
    }
    $unique = [];
    foreach ($directories as $directory) {
        $key = str_replace('\\', '/', $directory);
        if (!isset($unique[$key])) $unique[$key] = $directory;
    }
    return array_values($unique);
}

function paymentMirrorDirectory(bool $create = false): string
{
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    if ($create && !is_dir($directory)) @mkdir($directory, 0750, true);
    return $directory;
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
