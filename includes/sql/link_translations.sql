CREATE TABLE IF NOT EXISTS /*_*/link_translations (
	`id` int unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
	`lang` varchar(50) NOT NULL,
	`el_id` int(10),
	`original_str` BLOB,
	`corrected_str` BLOB
) /*$wgDBTableOptions*/;