-- Patch 047: Create survey_ai_log table for AI API rate limiting and token cost tracking
-- Each successful call to /api/post/ai-complete is logged here.
-- Used by ApiHelper::checkAIAccess() (rate limit + daily token cap)
-- and ApiHelper::logAICall() (insert after successful completion).

CREATE TABLE IF NOT EXISTS `survey_ai_log` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       int(10) UNSIGNED NOT NULL,
  `provider`      varchar(20)       NOT NULL,
  `model`         varchar(100)      NOT NULL,
  `input_tokens`  int(10) UNSIGNED  NOT NULL DEFAULT 0,
  `output_tokens` int(10) UNSIGNED  NOT NULL DEFAULT 0,
  `created`       datetime          NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_created` (`user_id`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
