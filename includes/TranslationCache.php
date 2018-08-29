<?php

/**
 */
class TranslationCache {
	const TABLE = 'translation_cache';

	/**
	 * Get this call from db cache
	 */
	public static function getCache( $original_str, $target_lang ) {
		$dbr = wfGetDB( DB_SLAVE );
		$conds = array( 'md5' => md5( $original_str ), "lang" => $target_lang );
		$conds[] = $dbr->encodeExpiry( wfTimestampNow() ) . ' < expiration';

		$translated_str = $dbr->selectField( self::TABLE, 'translated_str', $conds, __METHOD__ );

		if ( $translated_str ) {
			return ( $translated_str );
		} else {
			return false;
		}
	}

	/**
	 * Store this call in cache
	 */
	public static function setCache( $pageId, $target_lang, $original_str, $translated_str ) {
		$cache_expire = 60 * 24 * 3600;

		$dbw = wfGetDB( DB_MASTER );
		$data = array(
			'page_id' => $pageId,
			'lang' => $target_lang,
			'md5' => md5( $original_str ),
			'translated_str' => $translated_str,
			'expiration' => $dbw->encodeExpiry( wfTimestamp( TS_MW, time() + $cache_expire ) )
		);
		$result = $dbw->upsert( self::TABLE, $data, array( 'md5' ), $data, __METHOD__ );
		if ( !$result ) {
			throw new MWException( __METHOD__ . ': Set Cache failed' );
		}

		return $result;
	}
}
