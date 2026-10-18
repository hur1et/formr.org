-- Editable drafts alongside the live configuration; history is never deleted.
ALTER TABLE survey_quota_lifecycle ADD COLUMN editing TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE survey_quota_tables ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE survey_quota_groups ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE survey_quota_decisions ADD COLUMN expected_tables_json LONGTEXT NULL
    CHECK (expected_tables_json IS NULL OR JSON_VALID(expected_tables_json));

UPDATE survey_quota_decisions d SET expected_tables_json = (
    SELECT JSON_ARRAYAGG(t.code) FROM survey_quota_tables t WHERE t.quota_id=d.quota_id
);

CREATE TABLE survey_quota_configuration_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quota_id BIGINT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    action ENUM('reopen','reactivate') NOT NULL,
    tables_json LONGTEXT NOT NULL CHECK (JSON_VALID(tables_json)),
    provider_json LONGTEXT NOT NULL CHECK (JSON_VALID(provider_json)),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY quota_configuration_history (quota_id,id),
    CONSTRAINT quota_configuration_log_fk FOREIGN KEY (quota_id) REFERENCES survey_quotas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
