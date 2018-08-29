<?php

if ( getenv('MW_INSTALL_PATH') ) {
	require_once( getenv('MW_INSTALL_PATH') . '/maintenance/Maintenance.php' );
} else {
	require_once( dirname( __FILE__ ) . '/../../../maintenance/Maintenance.php' );
}
$maintClass = "TranslateWiki";

class TranslateWiki extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->mDescription = "Translates Wiki Contents to other languages using Google Translate";
	}

	public function execute() {
		global $wgTranslateWikiNamespaces, $wgTranslateWikiLanguages;

		$dbr = wfGetDB( DB_SLAVE );
		foreach( $wgTranslateWikiNamespaces as $namespace ) {
			$conds = [ 'page_namespace' => $namespace, 'page_is_redirect' => 0 ];
			$res = $dbr->select( 'page',
				[ 'page_title', 'page_id' ],
				$conds,
				__METHOD__
			);

			foreach( $wgTranslateWikiLanguages as $lang ) {
				$autoTranslate = new AutoTranslate( $lang );
				reset( $res );
				foreach( $res as $row ) {
					$autoTranslate->translateTitle( $row->page_id );
				}
				reset( $res );
				foreach( $res as $row ) {
					echo "Translating title:" . $row->page_title . " to lang:" . $lang . "\n";
					$autoTranslate->translate( $row->page_id );
				}
			}
		}
	}
}

require_once( DO_MAINTENANCE );
