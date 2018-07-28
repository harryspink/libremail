CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `folder_id` int(10) unsigned NOT NULL,
  `unique_id` int(10) unsigned DEFAULT NULL,
  `thread_id` int(10) unsigned DEFAULT NULL,
  `date_str` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `charset` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(270) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_id` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `in_reply_to` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recv_str` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` int(10) unsigned DEFAULT NULL,
  `message_no` int(10) unsigned DEFAULT NULL,
  `to` text COLLATE utf8mb4_unicode_ci,
  `from` text COLLATE utf8mb4_unicode_ci,
  `cc` text COLLATE utf8mb4_unicode_ci,
  `bcc` text COLLATE utf8mb4_unicode_ci,
  `reply_to` text COLLATE utf8mb4_unicode_ci,
  `text_plain` longtext COLLATE utf8mb4_unicode_ci,
  `text_html` longtext COLLATE utf8mb4_unicode_ci,
  `references` text COLLATE utf8mb4_unicode_ci,
  `attachments` text COLLATE utf8mb4_unicode_ci,
  `raw_headers` longtext COLLATE utf8mb4_unicode_ci,
  `raw_content` longtext COLLATE utf8mb4_unicode_ci,
  `seen` tinyint(1) unsigned DEFAULT NULL,
  `draft` tinyint(1) unsigned DEFAULT NULL,
  `recent` tinyint(1) unsigned DEFAULT NULL,
  `flagged` tinyint(1) unsigned DEFAULT NULL,
  `deleted` tinyint(1) unsigned DEFAULT NULL,
  `answered` tinyint(1) unsigned DEFAULT NULL,
  `synced` tinyint(1) unsigned DEFAULT NULL,
  `date` datetime DEFAULT NULL,
  `date_recv` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX (`date`),
  INDEX (`seen`),
  INDEX (`synced`),
  INDEX (`deleted`),
  INDEX (`flagged`),
  INDEX (`folder_id`),
  INDEX (`unique_id`),
  INDEX (`thread_id`),
  INDEX (`account_id`),
  INDEX (`message_id`(16)),
  INDEX (`in_reply_to`(16))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;