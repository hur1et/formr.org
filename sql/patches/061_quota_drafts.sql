-- Editor drafts only. Does not activate quotas or alter participant decisions.
CREATE TABLE survey_quota_drafts (
    run_id INT UNSIGNED NOT NULL PRIMARY KEY,
    run_unit_id INT UNSIGNED NOT NULL,
    tables_json LONGTEXT NOT NULL CHECK (JSON_VALID(tables_json)),
    modified DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT quota_draft_run_fk FOREIGN KEY (run_id) REFERENCES survey_runs(id),
    CONSTRAINT quota_draft_unit_fk FOREIGN KEY (run_unit_id, run_id)
        REFERENCES survey_run_units(id, run_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
