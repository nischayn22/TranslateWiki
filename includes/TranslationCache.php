<?php

/**
 */
class TranslationCache {
	const TABLE = 'translation_cache';

	private $translationFragmentsInCache = array();
	private $usedTranslationFragments = array();
	private $pageId = 0;
	private $translateTo = '';

	function __construct( $pageId, $translateTo ) {
		$this->pageId = $pageId;
		$this->translateTo = $translateTo;

		$dbr = wfGetDB( DB_SLAVE );
		$dbw = wfGetDB( DB_MASTER );
		$conds = array( 'page_id' => $this->pageId, "lang" => $this->translateTo );

		$res = $dbr->select( 
			self::TABLE,
			'id,md5,translated_str,expiration',
			$conds,
			__METHOD__
		);
		foreach( $res as $row ) {
			if ( strtotime( $row->expiration ) > wfTimestampNow() ) {
				$dbw->delete(
					self::TABLE,
					array( 'id' => $row->id ),
					__METHOD__
				);
			} else {
				$this->translationFragmentsInCache[$row->md5] = $row->translated_str;
			}
		}
	}

	function deleteUnusedCache() {
		$dbw = wfGetDB( DB_MASTER );
		foreach( $this->translationFragmentsInCache as $md5 => $translated_str ) {
			if ( !array_key_exists( $md5, $this->usedTranslationFragments ) ) {
				$dbw->delete(
					self::TABLE,
					array( 'md5' => $md5, 'page_id' => $this->pageId ),
					__METHOD__
				);
			}
		}
	}

	function getCache( $original_str ) {
		$md5 = md5( $original_str );
		if ( array_key_exists( $md5, $this->translationFragmentsInCache ) ) {
			$this->usedTranslationFragments[$md5] = 1;
			return $this->translationFragmentsInCache[$md5];
		} else {
			// Check in other page cache
			$dbr = wfGetDB( DB_SLAVE );
			$conds = array( 'md5' => $md5, "lang" => $this->translateTo );
			$conds[] = $dbr->encodeExpiry( wfTimestampNow() ) . ' < expiration';

			$translated_str = $dbr->selectField( self::TABLE, 'translated_str', $conds, __METHOD__ );

			if ( $translated_str ) {
				return ( $translated_str );
			} else {
				return false;
			}
		}
	}

	/**
	 * Store this call in cache
	 */
	public function setCache( $target_lang, $original_str, $translated_str, $pageId = 0 ) {
		if ( $pageId == 0 ) {
			$pageId = $this->pageId;
		}
		$cache_expire = 60 * 24 * 3600;

		$dbw = wfGetDB( DB_MASTER );
		$data = array(
			'page_id' => $pageId,
			'lang' => $target_lang,
			'md5' => md5( $original_str ),
			'translated_str' => $translated_str,
			'expiration' => $dbw->encodeExpiry( wfTimestamp( TS_MW, time() + $cache_expire ) )
		);
		$result = $dbw->insert( self::TABLE, $data, __METHOD__ );
		if ( !$result ) {
			throw new MWException( __METHOD__ . ': Set Cache failed' );
		}

		return $result;
	}
}
