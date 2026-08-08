-- pdfOCR schema. Idempotent: safe to run repeatedly.
-- Applied automatically by src/Migrator.php when app.auto_migrate is true.

CREATE TABLE IF NOT EXISTS ocr_jobs (
    id              CHAR(36)     NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    stored_name     VARCHAR(255) NOT NULL,
    mime_type       VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    size_bytes      INT UNSIGNED NOT NULL DEFAULT 0,
    sha256          CHAR(64)     NOT NULL,
    language        VARCHAR(20)  NOT NULL DEFAULT 'spa',
    status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    source_type     ENUM('unknown','text_layer','ocr','mixed') NOT NULL DEFAULT 'unknown',
    page_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    pages_done      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    word_count      INT UNSIGNED NOT NULL DEFAULT 0,
    mean_confidence DECIMAL(5,2) NULL,
    duration_ms     INT UNSIGNED NULL,
    engine          JSON         NULL,
    result_path     VARCHAR(255) NULL,
    error_message   TEXT         NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    completed_at    DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_jobs_created (created_at),
    KEY idx_jobs_status (status),
    KEY idx_jobs_sha256 (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ocr_pages (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id       CHAR(36)        NOT NULL,
    page_number  SMALLINT UNSIGNED NOT NULL,
    width        DECIMAL(10,2)   NOT NULL DEFAULT 0,
    height       DECIMAL(10,2)   NOT NULL DEFAULT 0,
    rotation     SMALLINT        NOT NULL DEFAULT 0,
    source       ENUM('text_layer','ocr','empty') NOT NULL DEFAULT 'ocr',
    confidence   DECIMAL(5,2)    NULL,
    word_count   INT UNSIGNED    NOT NULL DEFAULT 0,
    text         LONGTEXT        NULL,
    layout       JSON            NULL,
    created_at   DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_page (job_id, page_number),
    CONSTRAINT fk_pages_job FOREIGN KEY (job_id) REFERENCES ocr_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ocr_blocks (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id       CHAR(36)        NOT NULL,
    page_number  SMALLINT UNSIGNED NOT NULL,
    block_index  SMALLINT UNSIGNED NOT NULL,
    block_type   VARCHAR(32)     NOT NULL DEFAULT 'paragraph',
    text         MEDIUMTEXT      NULL,
    line_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    confidence   DECIMAL(5,2)    NULL,
    bbox         JSON            NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_block (job_id, page_number, block_index),
    KEY idx_blocks_type (block_type),
    CONSTRAINT fk_blocks_job FOREIGN KEY (job_id) REFERENCES ocr_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/value candidates ("Fecha: 12/03/2025") and typed entities (NIF, IBAN, amounts…).
CREATE TABLE IF NOT EXISTS ocr_entities (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id       CHAR(36)        NOT NULL,
    page_number  SMALLINT UNSIGNED NOT NULL,
    kind         ENUM('key_value','entity') NOT NULL DEFAULT 'entity',
    entity_type  VARCHAR(40)     NOT NULL,
    label        VARCHAR(255)    NULL,
    raw_value    TEXT            NULL,
    normalized   VARCHAR(255)    NULL,
    confidence   DECIMAL(5,2)    NULL,
    bbox         JSON            NULL,
    PRIMARY KEY (id),
    KEY idx_entities_job (job_id),
    KEY idx_entities_type (entity_type),
    KEY idx_entities_label (label),
    CONSTRAINT fk_entities_job FOREIGN KEY (job_id) REFERENCES ocr_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reserved for the next milestone: supervised mapping of extracted candidates
-- onto business field names, so a model can be trained per document type.
CREATE TABLE IF NOT EXISTS ocr_field_labels (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id        CHAR(36)        NOT NULL,
    entity_id     BIGINT UNSIGNED NULL,
    document_type VARCHAR(64)     NULL,
    field_name    VARCHAR(64)     NOT NULL,
    field_value   TEXT            NULL,
    origin        ENUM('manual','rule','model') NOT NULL DEFAULT 'manual',
    created_at    DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_labels_job (job_id),
    KEY idx_labels_field (document_type, field_name),
    CONSTRAINT fk_labels_job FOREIGN KEY (job_id) REFERENCES ocr_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_labels_entity FOREIGN KEY (entity_id) REFERENCES ocr_entities (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
