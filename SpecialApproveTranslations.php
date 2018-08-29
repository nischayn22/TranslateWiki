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
		global $wgTranslateWikiNamespaces, $wgTranslateWikiLanguages;

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

		$formOpts = [
			'id' => 'select_page',
			'method' => 'get',
			'action' => $this->getTitle()->getFullUrl()
		];

		$out->addHTML(
			Html::openElement( 'form', $formOpts ) . "<br>" .
			Html::element( 'input', [ 'name' => 'ns', 'value' => $namespace, 'type' => 'hidden' ] ) .
			Html::element( 'input', [ 'name' => 'lang', 'value' => $target_lang, 'type' => 'hidden' ] ) .
			Html::label( "Select Page","", array( "for" => "page" ) ) . "<br>" .
			Html::openElement( 'select', array( "id" => "page", "name" => "page", "style" => "width:100%;" ) )
		);

		$dbr = wfGetDB( DB_SLAVE );
		$conds = [ 'page_namespace' => $namespace, 'page_is_redirect' => 0 ];
		$res = $dbr->select( 'page',
			[ 'page_title', 'page_id' ],
			$conds,
			__METHOD__
		);
		foreach ( $res as $row ) {
			$conds = array( 'page_id' => $row->page_id, "lang" => $target_lang, "approval_status" => 1 );
			$approved_translations = $dbr->selectField( TranslationCache::TABLE, 'COUNT(*)', $conds, __METHOD__ );

			$conds = array( 'page_id' => $row->page_id, "lang" => $target_lang, "approval_status" => 0 );
			$unapproved_translations = $dbr->selectField( TranslationCache::TABLE, 'COUNT(*)', $conds, __METHOD__ );

			$page_summary = '';
			if ( $approved_translations == 0 && $unapproved_translations == 0 ) {
				$page_summary = 'No Translations';
			} else if ( $unapproved_translations > 0 ) {
				$page_summary = $unapproved_translations . ' Approvals Pending';
			} else {
				$page_summary = 'No Approvals Pending';
			}

			$out->addHTML(
				Html::element(
					'option', [
						'value' => $row->page_id,
					], $row->page_title . ' - ' . $page_summary
				)
			);
		}
		$out->addHTML( Html::closeElement( 'select' ) );

		$out->addHTML(
			"<br>" .
			Html::submitButton( "Show Approvals", array() ) .
			Html::closeElement( 'form' )
		);
		$current_page = $request->getVal( 'page' );
		if ( $current_page != '' ) {
			$title = Revision::newFromPageId( $current_page )->getTitle()->getFullText();
			$content = ContentHandler::getContentText( Revision::newFromPageId( $current_page )->getContent( Revision::RAW ) );

			$autoTranslate = new AutoTranslate( $target_lang );
			$translated_title = $autoTranslate->translateTitle( $current_page );
			$translated_content = $autoTranslate->translate( $current_page );

			$out->addHTML( '
				<h2>Wikitext Preview</h2>
				<div>
					<div style="float:left;width:45%;height:300px;">
						<h4>'. $title .'</h4>
						<textarea style="height:250px;" disabled>' . $content . '</textarea>
					</div>
					<div style="float:left;margin-left:3%;margin-right:3%;border-left: 2px solid grey;height: 300px;"></div>
					<div style="float:left;width:45%;height:300px;">
						<h4>'. $translated_title .'</h4>
						<textarea style="height:250px;" disabled>' . $translated_content . '</textarea>
					</div>
				</div>
			' );

			$conds = array( 'page_id' => $current_page, "lang" => $target_lang );
			$approved_translations = $dbr->select( TranslationCache::TABLE, 'id,translated_str', $conds, __METHOD__ );

			$formOpts = [
				'id' => 'approve_translations',
				'method' => 'post',
				'action' => $this->getTitle()->getFullUrl(),
				'style' => 'clear:both;'
			];

			$out->addHTML(
				Html::openElement( 'form', $formOpts ) . "<br>" .
				Html::element( 'input', [ 'name' => 'ns', 'value' => $namespace, 'type' => 'hidden' ] ) .
				Html::element( 'input', [ 'name' => 'lang', 'value' => $target_lang, 'type' => 'hidden' ] )
			);
			$out->addHTML(
				'<h2>Translated Fragments</h2>
				<span>Note: Edit translations to make corrections</span>
				'
				
			);
			foreach( $approved_translations as $translation ) {
				$out->addHTML( 
					Html::textarea( $translation->id, $translation->translated_str )
				);
			}
			$out->addHTML(
				"<br>" .
				Html::submitButton( "Approve All Translations", array() ) .
				Html::closeElement( 'form' )
			);
		}
	}
}