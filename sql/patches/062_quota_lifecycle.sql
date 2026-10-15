-- Explicit activation and provider identity, independent of response data.
CREATE TABLE survey_quota_exit_drafts (
    run_id INT UNSIGNED NOT NULL PRIMARY KEY,
    complete_run_unit_id INT UNSIGNED NOT NULL,
    provider_json LONGTEXT NOT NULL CHECK (JSON_VALID(provider_json)),
    CONSTRAINT quota_exit_draft_run_fk FOREIGN KEY (run_id) REFERENCES survey_runs(id),
    CONSTRAINT quota_exit_draft_unit_fk FOREIGN KEY (complete_run_unit_id,run_id)
        REFERENCES survey_run_units(id,run_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE survey_quota_lifecycle (
    quota_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL UNIQUE,
    quota_run_unit_id INT UNSIGNED NOT NULL,
    complete_run_unit_id INT UNSIGNED NOT NULL,
    provider_json LONGTEXT NOT NULL CHECK (JSON_VALID(provider_json)),
    activated_by INT UNSIGNED NOT NULL,
    activated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT quota_lifecycle_quota_fk FOREIGN KEY (quota_id) REFERENCES survey_quotas(id),
    CONSTRAINT quota_lifecycle_run_fk FOREIGN KEY (run_id) REFERENCES survey_runs(id),
    CONSTRAINT quota_lifecycle_unit_fk FOREIGN KEY (quota_run_unit_id,run_id) REFERENCES survey_run_units(id,run_id),
    CONSTRAINT quota_lifecycle_complete_fk FOREIGN KEY (complete_run_unit_id,run_id) REFERENCES survey_run_units(id,run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE survey_quota_provider_ids (
    run_session_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    quota_id BIGINT UNSIGNED NOT NULL,
    provider_id TEXT NOT NULL,
    captured_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT quota_identity_quota_fk FOREIGN KEY (quota_id) REFERENCES survey_quotas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE survey_pages ADD COLUMN provider_exit ENUM('', 'screen_out') NOT NULL DEFAULT '';
