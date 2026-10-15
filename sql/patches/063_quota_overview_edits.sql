-- One receipt per overview submission makes a whole-page correction retry-safe.
-- Coordinate development patch numbering with upstream before deployment.
CREATE TABLE survey_quota_edits (
    quota_id BIGINT UNSIGNED NOT NULL,
    operation_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (quota_id, operation_key),
    CONSTRAINT quota_edit_quota_fk FOREIGN KEY (quota_id) REFERENCES survey_quotas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
