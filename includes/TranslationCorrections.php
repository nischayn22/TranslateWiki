<?php

/**
 */
class TranslationCorrections {
	const TABLE = 'translation_corrections';

	protected $target_lang;
	protected $correctionCache = array();

	function __construct( $target_lang ) {
		$this->target_lang = $target_lang;
	}

	function init() {
		if ( !empty( $this->correctionCache ) ) {
			return;
		}
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			self::TABLE,
			[ 'id', 'original_str', 'corrected_str' ],
			[ 'lang' => $this->target_lang ],
			__METHOD__
		);
		foreach( $res as $row ) {
			$this->correctionCache[$row->original_str] = $row->corrected_str;
		}
	}

	function applyCorrections( $text ) {
		$this->init();
		return str_replace( array_keys( $this->correctionCache ), array_values( $this->correctionCache ), $text );
	}
}
