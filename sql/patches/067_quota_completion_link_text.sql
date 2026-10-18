-- Let researchers translate the individual return link as well as the surrounding text.
ALTER TABLE survey_quota_completion_pages
    ADD COLUMN redirect_link_text VARCHAR(255) NOT NULL DEFAULT 'click here';
