<?php

// Wraps Google Drive API authentication and file operations.

declare(strict_types=1);

final class GoogleDriveClient
{
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(private array $driveConfig)
    {
    }

    public function getFolderId(): string
    {
        return trim((string)($this->driveConfig['folder_id'] ?? ''));
    }

    public function getArchiveFolderId(): string
    {
        return trim((string)($this->driveConfig['archive_folder_id'] ?? ''));
    }

    public function isConfigured(): bool
    {
        return $this->getFolderId() !== '' && $this->loadServiceAccount() !== [];
    }

    public function listFiles(string $folderId): array
    {
        $query = sprintf("'%s' in parents and trashed = false", $folderId);
        $response = $this->requestJson('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $query,
            'fields' => 'files(id,name,mimeType,modifiedTime)',
            'pageSize' => 100,
            'orderBy' => 'modifiedTime asc',
        ]));

        return $response['files'] ?? [];
    }

    public function downloadFile(string $fileId): string
    {
        return $this->requestRaw('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media');
    }

    public function moveFileToArchive(string $fileId, ?string $archiveFolderId): void
    {
        if ($archiveFolderId === null || trim($archiveFolderId) === '') {
            return;
        }

        $fileMeta = $this->requestJson('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?fields=parents');
        $parents = $fileMeta['parents'] ?? [];
        $removeParents = implode(',', $parents);

        $this->requestJson('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?' . http_build_query([
            'addParents' => $archiveFolderId,
            'removeParents' => $removeParents,
            'fields' => 'id,parents',
        ]));
    }

    private function requestJson(string $method, string $url, ?array $body = null): array
    {
        $raw = $this->requestRaw($method, $url, $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES));
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Google Drive');
        }

        return $decoded;
    }

    private function requestRaw(string $method, string $url, ?string $body = null): string
    {
        $token = $this->getAccessToken();
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Google Drive request failed: ' . $message);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            throw new RuntimeException('Google Drive request failed with status ' . $status . ': ' . $response);
        }

        return (string)$response;
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $sa = $this->loadServiceAccount();
        if ($sa === []) {
            throw new RuntimeException('Google Drive service account not configured');
        }

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $tokenUri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        if (!is_string($tokenUri) || !str_starts_with($tokenUri, 'https://')) {
            $tokenUri = 'https://oauth2.googleapis.com/token';
        }
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive',
            'aud' => $tokenUri,
            'iat' => time(),
            'exp' => time() + 3600,
        ], JSON_UNESCAPED_SLASHES));
        $unsigned = $header . '.' . $claims;

        $signature = '';
        $privateKey = $sa['private_key'] ?? '';
        if ($privateKey === '' || !openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign Google Drive JWT');
        }

        $jwt = $unsigned . '.' . $this->base64UrlEncode($signature);
        $post = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $ch = curl_init($tokenUri);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Failed to obtain Google Drive access token: ' . $message);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if ($status >= 400 || !is_array($decoded) || empty($decoded['access_token'])) {
            throw new RuntimeException('Failed to obtain Google Drive access token: ' . $response);
        }

        $this->accessToken = (string)$decoded['access_token'];
        $this->tokenExpiresAt = time() + max(60, ((int)($decoded['expires_in'] ?? 3600)) - 60);

        return $this->accessToken;
    }

    private function loadServiceAccount(): array
    {
        $sourceConfig = $this->driveConfig['service_account_json'] ?? '';
        if (is_array($sourceConfig)) {
            return $sourceConfig;
        }

        $source = trim((string)$sourceConfig);
        $path = trim((string)($this->driveConfig['service_account_json_path'] ?? ''));

        if ($source === '' && $path !== '' && is_file($path)) {
            $source = (string)file_get_contents($path);
        }

        if ($source === '') {
            return [];
        }

        $decoded = json_decode($source, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
