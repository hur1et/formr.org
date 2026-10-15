-- Quota persistence core. Additive; not a complete deployable Quota/Complete feature.
-- Coordinate patch numbering with upstream before merging. No historic data is changed.
-- Lifecycle, run-unit attachment and deletion policy must be integrated before deployment.
CREATE TABLE survey_quotas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    run_id INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY quota_run (run_id),
    CONSTRAINT quota_run_fk FOREIGN KEY (run_id) REFERENCES survey_runs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE survey_quota_tables (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quota_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dimensions_json LONGTEXT NOT NULL CHECK (JSON_VALID(dimensions_json)),
    UNIQUE KEY quota_table_code (quota_id, code),
    UNIQUE KEY quota_table_identity (id, quota_id),
    CONSTRAINT quota_table_quota_fk FOREIGN KEY (quota_id) REFERENCES survey_quotas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE survey_quota_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quota_id BIGINT UNSIGNED NOT NULL,
    table_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    quota_limit INT UNSIGNED NOT NULL,
    UNIQUE KEY quota_group_code (table_id, code),
    UNIQUE KEY quota_group_identity (id, table_id, quota_id),
    CONSTRAINT quota_group_table_fk FOREIGN KEY (table_id, quota_id) REFERENCES survey_quota_tables(id, quota_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE survey_quota_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quota_id BIGINT UNSIGNED NOT NULL,
    run_session_id BIGINT UNSIGNED NOT NULL,
    cohort ENUM('field', 'test') NOT NULL,
    outcome ENUM('admitted', 'rejected') NOT NULL,
    reason VARCHAR(32) NULL,
    rejected_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    rejected_source TEXT NULL,
    decided_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY quota_participation (quota_id, run_session_id),
    UNIQUE KEY quota_decision_identity (id, quota_id),
    KEY quota_cohort (quota_id, cohort),
    CONSTRAINT quota_decision_quota_fk FOREIGN KEY (quota_id) REFERENCES survey_quotas(id),
    CONSTRAINT quota_decision_outcome CHECK (
        (outcome = 'admitted' AND reason IS NULL AND rejected_table IS NULL AND rejected_source IS NULL)
        OR (outcome = 'rejected' AND reason IS NOT NULL
            AND reason IN ('group_full', 'missing_answer', 'unmatched_answer') AND rejected_table IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE survey_quota_assignments (
    decision_id BIGINT UNSIGNED NOT NULL,
    quota_id BIGINT UNSIGNED NOT NULL,
    table_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (decision_id, table_id),
    KEY quota_assignment_group (group_id, decision_id),
    CONSTRAINT quota_assignment_decision_fk FOREIGN KEY (decision_id, quota_id) REFERENCES survey_quota_decisions(id, quota_id),
    CONSTRAINT quota_assignment_group_fk FOREIGN KEY (group_id, table_id, quota_id) REFERENCES survey_quota_groups(id, table_id, quota_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE survey_quota_completions (
    decision_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    complete_unit_session_id BIGINT UNSIGNED NOT NULL,
    completed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    CONSTRAINT quota_completion_decision_fk FOREIGN KEY (decision_id) REFERENCES survey_quota_decisions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE survey_quota_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quota_id BIGINT UNSIGNED NOT NULL,
    table_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    cohort ENUM('field', 'test') NOT NULL,
    delta BIGINT NOT NULL CHECK (delta <> 0),
    actor_id INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    operation_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY quota_adjustment_operation (quota_id, operation_key),
    KEY quota_adjustment_total (quota_id, cohort, group_id),
    CONSTRAINT quota_adjustment_group_fk FOREIGN KEY (group_id, table_id, quota_id) REFERENCES survey_quota_groups(id, table_id, quota_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE survey_quota_limit_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quota_id BIGINT UNSIGNED NOT NULL,
    table_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    old_limit INT UNSIGNED NOT NULL,
    new_limit INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    operation_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY quota_limit_operation (quota_id, operation_key),
    CONSTRAINT quota_limit_group_fk FOREIGN KEY (group_id, table_id, quota_id) REFERENCES survey_quota_groups(id, table_id, quota_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- No FK to existing run/unit sessions or audit actors yet: deployed legacy schemas differ
-- in signedness, and deletion policy is not agreed. QuotaStore validates current ownership.
-- Quota-internal FKs are restrictive: no silent cascading loss of completion evidence.
