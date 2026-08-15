-- Pumpergy MariaDB schema (Phase 1 foundation)

CREATE TABLE IF NOT EXISTS energy_readings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resolution ENUM('hour', 'day', 'month') NOT NULL,
    ts DATETIME NOT NULL,
    metric_name VARCHAR(64) NOT NULL,
    metric_value DECIMAL(12,4) DEFAULT NULL,
    metric_group VARCHAR(64) NOT NULL,
    subcategory VARCHAR(64) NOT NULL DEFAULT '',
    source VARCHAR(64) NOT NULL DEFAULT 'unknown',
    import_batch_id VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reading (resolution, ts, metric_name, metric_group, subcategory)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_readings_resolution_ts ON energy_readings (resolution, ts);
CREATE INDEX idx_readings_metric ON energy_readings (metric_name);

CREATE TABLE IF NOT EXISTS annotations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ts DATETIME NOT NULL,
    duration_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
    icon VARCHAR(32) NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_annotations_ts ON annotations (ts);

CREATE TABLE IF NOT EXISTS handled_aux_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resolution ENUM('hour', 'day', 'month') NOT NULL,
    event_start DATETIME NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_aux_event (resolution, event_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_state (
    id TINYINT UNSIGNED PRIMARY KEY,
    last_checked_at DATETIME DEFAULT NULL,
    last_sync_at DATETIME DEFAULT NULL,
    last_success_at DATETIME DEFAULT NULL,
    last_error TEXT DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO import_state (id) VALUES (1);

CREATE TABLE IF NOT EXISTS imported_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(64) NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_modified_at DATETIME DEFAULT NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_source_external (source, external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;