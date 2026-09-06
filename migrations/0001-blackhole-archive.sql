CREATE TABLE IF NOT EXISTS `jbh_events` (
  `event_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `contract_schema` tinyint unsigned NOT NULL,
  `resource` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `bulk` tinyint(1) NOT NULL,
  `actor_id` bigint DEFAULT NULL,
  `source` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` char(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_count` smallint unsigned NOT NULL,
  `metadata_json` longtext NOT NULL,
  `metadata_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `result_json` longtext NOT NULL,
  `warnings_json` longtext NOT NULL,
  `phase` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `ready_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `captured_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ready_at` datetime DEFAULT NULL,
  PRIMARY KEY (`event_id`),
  KEY `jbh_events_occurred` (`occurred_at`,`event_id`),
  KEY `jbh_events_resource_operation` (`resource`,`operation`,`occurred_at`),
  KEY `jbh_events_actor` (`actor_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jbh_items` (
  `event_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `item_position` smallint unsigned NOT NULL,
  `before_json` longtext NOT NULL,
  `after_json` longtext DEFAULT NULL,
  `before_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `after_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`event_id`,`item_id`),
  UNIQUE KEY `jbh_items_position` (`event_id`,`item_position`),
  CONSTRAINT `jbh_items_event_fk` FOREIGN KEY (`event_id`) REFERENCES `jbh_events` (`event_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jbh_artifacts` (
  `event_id` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `ordinal` smallint unsigned NOT NULL,
  `role` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `managed` tinyint(1) NOT NULL,
  `source_state` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `descriptor_before_json` longtext NOT NULL,
  `descriptor_before_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `descriptor_ready_json` longtext DEFAULT NULL,
  `archive_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `archive_relative_path` varchar(512) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `archive_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `archive_size` bigint unsigned DEFAULT NULL,
  `copied_at` datetime DEFAULT NULL,
  PRIMARY KEY (`event_id`,`item_id`,`ordinal`),
  KEY `jbh_artifacts_status` (`archive_status`,`copied_at`),
  CONSTRAINT `jbh_artifacts_item_fk` FOREIGN KEY (`event_id`,`item_id`) REFERENCES `jbh_items` (`event_id`,`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
