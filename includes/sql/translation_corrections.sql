CREATE TABLE IF NOT EXISTS /*_*/translation_corrections (
	`id` int unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`lang` varchar(50) NOT NULL,
	`original_str` BLOB,
	`translated_str` BLOB
) /*$wgDBTableOptions*/;