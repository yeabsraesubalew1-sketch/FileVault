CREATE TABLE projects (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(120) NOT NULL,
    slug            VARCHAR(120) NOT NULL,
    status          VARCHAR(20) NOT NULL,
    storage_quota   BIGINT UNSIGNED NOT NULL,
    max_file_size   BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME(6) NOT NULL,
    updated_at      DATETIME(6) NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_projects_slug (slug)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
