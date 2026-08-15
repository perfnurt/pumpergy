<?php

// Provides request and payload validation helpers for API endpoints.

declare(strict_types=1);

final class Validation
{
    public static function requireMethod(string $expected): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($expected)) {
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'method_not_allowed']);
            exit;
        }
    }
}
