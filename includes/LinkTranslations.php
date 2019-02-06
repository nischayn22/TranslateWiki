<?php

/**
 */
class LinkTranslations {
	const TABLE = 'link_translations';

	protected $target_lang;
	protected $linkTranslationCache = array();

	function __construct( $target_lang ) {
		$this->target_lang = $target_lang;
	}

	function init() {
		if ( !empty( $this->linkTranslationCache ) ) {
			return;
		}
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 
			self::TABLE,
			[ 'id', 'original_str', 'translated_str' ],
			[ 'lang' => $this->target_lang ],
			__METHOD__
		);
		foreach( $res as $row ) {
			$this->linkTranslationCache[$row->original_str] = $row->translated_str;
		}
	}

	function applyTranslations( $text ) {
		$this->init();
		return str_replace( array_keys( $this->linkTranslationCache ), array_values( $this->linkTranslationCache ), $text );
	}
}
