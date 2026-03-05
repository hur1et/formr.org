ALTER TABLE `survey_ai_log`
  ADD COLUMN `session_token`      VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'formr run session token' AFTER `user_id`,
  ADD COLUMN `prompt_text`        TEXT                              COMMENT 'The user prompt text for this call' AFTER `output_tokens`,
  ADD COLUMN `response_text`      TEXT                              COMMENT 'The AI response text for this call' AFTER `prompt_text`,
  ADD COLUMN `conversation_json`  MEDIUMTEXT                        COMMENT 'Full conversation history as JSON array [{role,content}]' AFTER `response_text`,
  ADD KEY `idx_session_token` (`session_token`(64));
