<?php

// Handles annotation CRUD API endpoints for the dashboard.

declare(strict_types=1);

require_once __DIR__ . '/bootstrap_loader.php';

function pumpergy_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

if (!empty($container['boot_error'])) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'setup_required', 'detail' => $container['boot_error']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
header('Content-Type: application/json; charset=utf-8');

try {
    if ($method === 'GET') {
        [$_, $start, $end] = DateRange::fromQuery($_GET);
        $data = $container['services']['annotations']->getAnnotations($start, $end);
        echo json_encode([
            'start' => $start,
            'end' => $end,
            'annotations' => $data,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST') {
        $payload = pumpergy_read_json_body();
        if ($payload === []) {
            $payload = $_POST;
        }

        $ts = trim((string)($payload['ts'] ?? $payload['timestamp'] ?? ''));
        $icon = trim((string)($payload['icon'] ?? 'note'));
        $note = trim((string)($payload['note'] ?? $payload['text'] ?? ''));
        $durationHours = (float)($payload['duration_hours'] ?? 0.0);

        if ($ts === '') {
            throw new InvalidArgumentException('Missing annotation timestamp');
        }

        $id = $container['services']['annotations']->create($ts, $icon, $note, $durationHours);
        echo json_encode(['ok' => true, 'id' => $id], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $payload = pumpergy_read_json_body();
        if ($payload === []) {
            $payload = $_POST;
        }

        $id = (int)($payload['id'] ?? 0);
        $ts = trim((string)($payload['ts'] ?? $payload['timestamp'] ?? ''));
        $icon = trim((string)($payload['icon'] ?? 'note'));
        $note = trim((string)($payload['note'] ?? $payload['text'] ?? ''));
        $durationHours = (float)($payload['duration_hours'] ?? 0.0);

        if ($id <= 0 || $ts === '') {
            throw new InvalidArgumentException('Missing annotation id or timestamp');
        }

        $ok = $container['services']['annotations']->update($id, $ts, $icon, $note, $durationHours);
        echo json_encode(['ok' => $ok, 'id' => $id], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'DELETE') {
        $payload = pumpergy_read_json_body();
        if ($payload === []) {
            $payload = $_GET;
        }

        $id = (int)($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Missing annotation id');
        }

        $ok = $container['services']['annotations']->delete($id);
        echo json_encode(['ok' => $ok, 'id' => $id], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request', 'detail' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
