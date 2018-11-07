<?php

/**
 * 
 * 
 */
class SpecialApproveTranslations extends SpecialPage {
	public function __construct() {
		parent::__construct( 'ApproveTranslations', 'approvetranslations' );
	}

	/**
	 */
	public function execute( $par ) {
		global $wgTranslateWikiNamespaces, $wgTranslateWikiLanguages, $wgSyncWikis;

		$this->setHeaders();
		$request = $this->getRequest();
		$out = $this->getOutput();

		if( !in_array( 'sysop', $this->getUser()->getEffectiveGroups()) ) {
			$out->addHTML( '<div class="errorbox">This page is only accessible by users with sysop right.</div>' );
			return;
		}

		$namespace = $request->getVal( 'ns' );
		$target_lang = $request->getVal( 'lang' );

		if ( $namespace == '' || empty( $target_lang ) ) {
			$formOpts = [
				'id' => 'select_ns',
				'method' => 'get',
				'action' => $this->getTitle()->getFullUrl()
			];
			$out->addHTML(
				Html::openElement( 'form', $formOpts ) . "<br>" .
				Html::label( "Select Namespace: ","", array( "for" => "ns" ) ) .
				Html::openElement( 'select', array( "id" => "ns", "name" => "ns" ) )
			);
			foreach( $wgTranslateWikiNamespaces as $namespace ) {
				$out->addHTML(
					Html::element(
						'option', [
							'value' => $namespace,
						], $namespace
					)
				);
			}
			$out->addHTML( Html::closeElement( 'select' ) . "<br>" );
			$out->addHTML(
				Html::label( "Select Language: ","", array( "for" => "lang" ) ) .
				Html::openElement( 'select', array( "id" => "lang", "name" => "lang" ) )
			);

			foreach( $wgTranslateWikiLanguages as $language ) {
				$out->addHTML(
					Html::element(
						'option', [
							'value' => $language,
						], $language
					)
				);
			}
			$out->addHTML( Html::closeElement( 'select' ) . "<br>" );
			$out->addHTML(
				"<br>" .
				Html::submitButton( "Get Page List", array() ) .
				Html::closeElement( 'form' )
			);
			return;
		}

		$current_page = $request->getVal( 'page' );
		$page_action = $request->getVal( 'page_action' );
		if ( $page_action == 'approve_translations' ) {
			$updated_count = $this->approveTranslations( $current_page, $target_lang );
			Hooks::run( 'TranslationsApproved', array( $current_page, $target_lang ) );
			$out->addHTML( '<div style="background-color:#28dc28;color:white;padding:5px;">'. $updated_count .' translations corrected. All approved.</div>' );
		}

		$out->addHTML( '<i>Note: You can also translate all pages in bulk using the maintenance script autoTranslateWiki.php </i>' );

		$formOpts = [
			'id' => 'select_range',
			'method' => 'get',
			'action' => $this->getTitle()->getFullUrl()
		];

		$out->addHTML(
			Html::openElement( 'form', $formOpts ) . "<br>" .
			Html::element( 'input', [ 'name' => 'ns', 'value' => $namespace, 'type' => 'hidden' ] ) .
			Html::element( 'input', [ 'name' => 'lang', 'value' => $target_lang, 'type' => 'hidden' ] ) .
			Html::label( "Select a range:","", array( "for" => "page_offset" ) ) . "<br>" .
			Html::openElement( 'select', array( "id" => "page_offset", "name" => "page_offset", "style" => "width:100%;" ) )
		);

		$dbr = wfGetDB( DB_SLAVE );
		$conds = [ 'page_namespace' => $namespace, 'page_is_redirect' => 0 ];
		$pages_count = $dbr->selectField( 'page',
			[ 'COUNT(*)' ],
			$conds,
			__METHOD__
		);

		$limit = 20;
		$offsets = range( 0, $pages_count, $limit );
		$current_offset = $request->getVal( 'page_offset' );
		if ( $current_offset == '' ) {
			$current_offset = 0;
		}

		foreach( $offsets as $offset ) {
			$to_offset = min( $offset + $limit, $pages_count );
			$out->addHTML(
				Html::element(
					'option', [
						'selected' => $offset == $current_offset,
						'value' => $offset,
					], $offset . ' - ' . $to_offset
				)
			);
		}

		$out->addHTML( Html::closeElement( 'select' ) . "<br>" );
		$out->addHTML(
			"<br>" .
			Html::submitButton( "Get Page List", array() ) .
			Html::closeElement( 'form' )
		);

		$formOpts = [
			'id' => 'select_range',
			'method' => 'get',
			'action' => $this->getTitle()->getFullUrl()
		];
		$out->addHTML(
			Html::openElement( 'form', $formOpts ) . "<br>" .
			Html::element( 'input', [ 'name' => 'ns', 'value' => $namespace, 'type' => 'hidden' ] ) .
			Html::element( 'input', [ 'name' => 'lang', 'value' => $target_lang, 'type' => 'hidden' ] ) .
			Html::element( 'input', [ 'name' => 'page_offset', 'value' => $current_offset, 'type' => 'hidden' ] ) .
			Html::label( "Select a page:","", array( "for" => "page" ) ) . "<br>" .
			Html::openElement( 'select', array( "id" => "page", "name" => "page", "style" => "width:100%;" ) )
		);

		$dbr = wfGetDB( DB_SLAVE );
		$conds = [ 'page_namespace' => $namespace, 'page_is_redirect' => 0 ];
		$res = $dbr->select( 'page',
			[ 'page_title', 'page_id' ],
			$conds,
			__METHOD__,
			array( 'OFFSET' => $current_offset, 'LIMIT' => $limit )
		);

		foreach ( $res as $row ) {
			$conds = array( 'page_id' => $row->page_id, "lang" => $target_lang, "approval_status" => 1 );
			$approved_translations = $dbr->selectField( TranslationCache::TABLE, 'COUNT(*)', $conds, __METHOD__ );

			$conds = array( 'page_id' => $row->page_id, "lang" => $target_lang, "approval_status" => 0 );
			$unapproved_translations = $dbr->selectField( TranslationCache::TABLE, 'COUNT(*)', $conds, __METHOD__ );

			$page_summary = '';
			if ( $approved_translations == 0 && $unapproved_translations == 0 ) {
				$page_summary = 'Not Translated';
			} else if ( $unapproved_translations > 0 ) {
				$page_summary = $unapproved_translations . ' Approvals Pending';
			} else {
				$page_summary = 'No Approvals Pending';
			}

			$out->addHTML(
				Html::element(
					'option', [
						'selected' => $row->page_id == $current_page,
						'value' => $row->page_id,
					], $row->page_title . ' - ' . $page_summary
				)
			);
		}
		$out->addHTML( Html::closeElement( 'select' ) );

		$out->addHTML(
			"<br><br>" .
			Html::submitButton( "Auto Translate and Show Approvals", array() ) .
			"<br><br>" .
			Html::submitButton( "Re-translate and Show Approvals", array( 'name' => 'retranslate' ) ) .
			Html::closeElement( 'form' )
		);
		if ( $current_page != '' ) {

			$title = Revision::newFromPageId( $current_page )->getTitle();
			$content = ContentHandler::getContentText( Revision::newFromPageId( $current_page )->getContent( Revision::RAW ) );

			$shouldPurge = false;
			if ( $request->getVal( 'retranslate' ) != '' ) {
				$shouldPurge = true;
			}
			$autoTranslate = new AutoTranslate( $target_lang );
			$translated_title = $autoTranslate->translateTitle( $current_page, $shouldPurge );
			$translated_content = $autoTranslate->translate( $current_page, $shouldPurge );

			$syncWikiLinks = '';
			foreach( $wgSyncWikis as $wgSyncWiki ) {
				if ( $wgSyncWiki['translate_to'] == $target_lang ) {
					$page_link = $wgSyncWiki['article_path'] .'/'. urlencode( str_replace( " ", "_", $translated_title ) );
					$syncWikiLinks .= '<a target="_blank" href="'. $page_link .'">'. $translated_title .'</a>';
				}
			}

			$out->addHTML( '
				<h2>Wikitext Preview</h2>
				<div>
					<div style="float:left;width:45%;height:300px;">
						<h4>'. $title->getFullText() .'</h4>
						<a target="_blank" href="'. $title->getFullUrl() .'">'. $title->getText() .'</a>
						<textarea style="height:250px;" disabled>' . $content . '</textarea>
					</div>
					<div style="float:left;margin-left:3%;margin-right:3%;border-left: 2px solid grey;height: 300px;"></div>
					<div style="float:left;width:45%;height:300px;">
						<h4>'. $translated_title .'</h4>
						'. $syncWikiLinks .'
						<textarea style="height:250px;" disabled>' . $translated_content . '</textarea>
					</div>
				</div>
			' );

			$conds = array( 'page_id' => $current_page, "lang" => $target_lang );
			$conds[] = $dbr->encodeExpiry( wfTimestampNow() ) . ' < expiration';
			$approved_translations = $dbr->select( TranslationCache::TABLE, 'id,md5,translated_str', $conds, __METHOD__ );

			$formOpts = [
				'id' => 'approve_translations',
				'method' => 'post',
				'action' => $this->getTitle()->getFullUrl(),
				'style' => 'clear:both;'
			];

			$out->addHTML(
				Html::openElement( 'form', $formOpts ) . "<br>" .
				Html::element( 'input', [ 'name' => 'ns', 'value' => $namespace, 'type' => 'hidden' ] ) .
				Html::element( 'input', [ 'name' => 'lang', 'value' => $target_lang, 'type' => 'hidden' ] ) .
				Html::element( 'input', [ 'name' => 'page_offset', 'value' => $current_offset, 'type' => 'hidden' ] ) .
				Html::element( 'input', [ 'name' => 'page', 'value' => $current_page, 'type' => 'hidden' ] ) .
				Html::element( 'input', [ 'name' => 'page_action', 'value' => 'approve_translations', 'type' => 'hidden' ] )
			);
			$out->addHTML(
				'<h2>Translated Fragments</h2>'
			);
			$translation_fragments = $autoTranslate->getTranslationFragments();
			foreach( $approved_translations as $translation ) {
				// If fragment doesn't exist in the current translation fragments its no longer used on this page.
				if ( array_key_exists( $translation->md5, $translation_fragments ) ) {
					$out->addHTML( '
						<div>
							<div style="float:left;width:45%;height:100px;">
								<textarea disabled>' . $translation_fragments[ $translation->md5 ][0] . '</textarea>
							</div>
							<div style="float:left;margin-left:3%;margin-right:3%;border-left: 2px solid grey;height: 100px;"></div>
							<div style="float:left;width:45%;height:100px;">
								'. Html::textarea( $translation->id, $translation->translated_str ) .'
							</div>
						</div>
						<br>
					' );
				}
			}
			$out->addHTML(
				"<br>" .
				Html::submitButton( "Approve All Translations & Sync", array() ) .
				Html::closeElement( 'form' )
			);
		}
	}

	function approveTranslations( $current_page, $target_lang ) {
		$dbr = wfGetDB( DB_SLAVE );
		$dbw = wfGetDB( DB_MASTER );
		$updated_count = 0;

		$request = $this->getRequest();
		$conds = array( 'page_id' => $current_page, "lang" => $target_lang );
		$conds[] = $dbr->encodeExpiry( wfTimestampNow() ) . ' < expiration';
		$approved_translations = $dbr->select( TranslationCache::TABLE, 'id,md5,translated_str', $conds, __METHOD__ );
		foreach( $approved_translations as $translation ) {
			$approved_translation = $request->getVal( $translation->id );
			if ( $approved_translation !== $translation->translated_str ) {
				$updated_count++;
			}
			$data = array(
				'translated_str' => $approved_translation,
				'approval_status' => 1,
			);
			$dbw->update(
				TranslationCache::TABLE,
				$data,
				array( 'id' => $translation->id ),
				__METHOD__
			);
		}
		return $updated_count;
	}
}