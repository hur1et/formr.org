-- Editable completion/debriefing page. Existing Complete units use application defaults.
CREATE TABLE survey_quota_completion_pages (
    unit_id INT UNSIGNED NOT NULL PRIMARY KEY,
    body MEDIUMTEXT NOT NULL,
    redirect_message TEXT NOT NULL,
    redirect_delay INT UNSIGNED NOT NULL DEFAULT 10,
    CONSTRAINT quota_completion_page_unit_fk FOREIGN KEY (unit_id)
        REFERENCES survey_units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
