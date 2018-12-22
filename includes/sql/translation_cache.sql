CREATE TABLE IF NOT EXISTS /*_*/translation_cache (
	`id` int unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`page_id` int NOT NULL,
	`md5` varchar(256) NOT NULL,
	`lang` varchar(50) NOT NULL,
	`translated_str` BLOB,
	`approval_status` BOOLEAN DEFAULT 0,
	`expiration` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) /*$wgDBTableOptions*/;
ALTER TABLE `translation_cache` ADD UNIQUE `unique_index`(`md5`, `lang`);