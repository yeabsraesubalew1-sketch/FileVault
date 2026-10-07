CREATE TABLE api_keys (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id      BIGINT UNSIGNED NOT NULL,
    key_hash        BINARY(32) NOT NULL,
    key_prefix      VARCHAR(16) NOT NULL,
    status          VARCHAR(20) NOT NULL,
    created_at      DATETIME(6) NOT NULL,
    last_used_at    DATETIME(6) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_key_hash (key_hash),
    KEY idx_api_keys_project_status (project_id, status),

    CONSTRAINT fk_api_keys_project
        FOREIGN KEY (project_id)
        REFERENCES projects(id)
        ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
