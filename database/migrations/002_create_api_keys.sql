CREATE TABLE api_keys (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id       BINARY(16) NOT NULL,
    project_id      BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(100) NOT NULL,
    secret_hash     BINARY(32) NOT NULL,
    status          VARCHAR(20) NOT NULL,
    created_at      DATETIME(6) NOT NULL,
    revoked_at      DATETIME(6) NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_api_keys_public_id (public_id),
    UNIQUE KEY uq_api_keys_project_name (project_id, name),
    KEY idx_api_keys_project_status (project_id, status),

    CONSTRAINT chk_api_keys_status_revoked_at CHECK (
        (status = 'active' AND revoked_at IS NULL) OR 
        (status = 'revoked' AND revoked_at IS NOT NULL AND revoked_at >= created_at)
    ),

    CONSTRAINT fk_api_keys_project
        FOREIGN KEY (project_id)
        REFERENCES projects(id)
        ON DELETE RESTRICT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
