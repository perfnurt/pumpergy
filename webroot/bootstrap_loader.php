<?php

// Loads the main application bootstrap for all web entry points.

declare(strict_types=1);

$bootstrapPath = __DIR__ . '/app/bootstrap.php';

if (!is_file($bootstrapPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bootstrap not found at: ' . $bootstrapPath;
    exit;
}

require_once $bootstrapPath;
