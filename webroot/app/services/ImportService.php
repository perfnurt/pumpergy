<?php

// Imports CSV data from Google Drive into database tables.

declare(strict_types=1);

final class ImportService
{
    public function __construct(
        private PDO $pdo,
        private DriveSyncService $sync,
        private ImportRepository $imports,
        private GoogleDriveClient $drive,
        private CsvParser $parser
    )
    {
    }

    public function importIfDue(int $intervalSeconds, bool $force = false): array
    {
        if (!$this->sync->claimSyncSlot($intervalSeconds, $force)) {
            return [
                'status' => 'skipped',
                'reason' => 'sync_not_due',
                ...$this->sync->getSyncWindow($intervalSeconds),
            ];
        }

        if (!$this->drive->isConfigured()) {
            $message = 'Google Drive credentials or folder ID are not configured';
            $this->sync->markSyncFailure($message);
            return ['status' => 'error', 'error' => $message];
        }

        $folderId = $this->drive->getFolderId();
        $archiveFolderId = $this->drive->getArchiveFolderId();

        $importedFiles = 0;
        $importedRows = 0;

        try {
            $files = $this->drive->listFiles($folderId);
            foreach ($files as $file) {
                $fileId = (string)($file['id'] ?? '');
                $fileName = (string)($file['name'] ?? '');
                $modifiedAt = isset($file['modifiedTime'])
                    ? date('Y-m-d H:i:s', strtotime((string)$file['modifiedTime']))
                    : null;
                if ($fileId === '' || $fileName === '') {
                    continue;
                }

                if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv') {
                    continue;
                }

                if (!$this->imports->shouldImportFile('google_drive', $fileId, $modifiedAt)) {
                    continue;
                }

                $csvContent = $this->drive->downloadFile($fileId);
                $rows = $this->parser->parse($csvContent, 'google_drive', $fileId);
                if ($rows === []) {
                    throw new RuntimeException(sprintf('CSV file %s contained no readable measurements', $fileName));
                }

                $this->pdo->beginTransaction();
                $written = $this->imports->upsertReadings($rows);
                $this->imports->recordImportedFile(
                    'google_drive',
                    $fileId,
                    $fileName,
                    $modifiedAt
                );
                $this->pdo->commit();

                if ($archiveFolderId !== '') {
                    $this->drive->moveFileToArchive($fileId, $archiveFolderId);
                }

                $importedFiles++;
                $importedRows += $written;
            }

            $this->sync->markSyncSuccess();
            $window = $this->sync->getSyncWindow($intervalSeconds);

            return [
                'status' => 'ok',
                'imported_files' => $importedFiles,
                'imported_rows' => $importedRows,
                ...$window,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->sync->markSyncFailure($e->getMessage());
            $window = $this->sync->getSyncWindow($intervalSeconds);
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'imported_files' => $importedFiles,
                'imported_rows' => $importedRows,
                ...$window,
            ];
        }
    }
}
