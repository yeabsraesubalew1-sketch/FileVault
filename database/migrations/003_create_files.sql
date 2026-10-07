CREATE TABLE files (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id      BIGINT UNSIGNED NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    storage_key     VARCHAR(512) NOT NULL,
    mime_type       VARCHAR(127) NOT NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL,
    visibility      VARCHAR(16) NOT NULL,
    status          VARCHAR(20) NOT NULL,
    created_at      DATETIME(6) NOT NULL,
    updated_at      DATETIME(6) NOT NULL,
    trashed_at      DATETIME(6) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_files_storage_key (storage_key),
    KEY idx_files_project_status_created (project_id, status, created_at),

    CONSTRAINT fk_files_project
        FOREIGN KEY (project_id)
        REFERENCES projects(id)
        ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
