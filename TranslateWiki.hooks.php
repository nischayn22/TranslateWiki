<?php

class TranslateWikiHooks {

	/**
	 *
	 * @param DatabaseUpdater $updater
	 * @return boolean
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( TranslationCache::TABLE,
			__DIR__ . '/includes/sql/translation_cache.sql', true );

		$updater->addExtensionTable( TranslationCorrections::TABLE,
			__DIR__ . '/includes/sql/translation_corrections.sql', true );

		return true;
	}
}
