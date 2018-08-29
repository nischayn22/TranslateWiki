<?php

class TranslateWikiHooks {

	/**
	 *
	 * @param DatabaseUpdater $updater
	 * @return boolean
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$updater->addExtensionTable( TranslationCache::TABLE,
			__DIR__ . '/translation_cache.sql', true );
		return true;
	}
}
