-- Revocable, read-only quota links. Coordinate development numbering with upstream.
CREATE TABLE survey_quota_shares (
    run_id INT UNSIGNED NOT NULL PRIMARY KEY,
    access_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY quota_share_key (access_key),
    CONSTRAINT quota_share_run_fk FOREIGN KEY (run_id) REFERENCES survey_runs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
