<?php

/**
 * 
 * 
 */
class SpecialTranslationCorrections extends SpecialPage {
	public function __construct() {
		parent::__construct( 'TranslationCorrections', 'translationcorrections' );
	}

	/**
	 */
	public function execute( $par ) {
		global $wgTranslateWikiLanguages;

		$this->setHeaders();
		$request = $this->getRequest();
		$out = $this->getOutput();

		if( !in_array( 'sysop', $this->getUser()->getEffectiveGroups()) ) {
			$out->addHTML( '<div class="errorbox">This page is only accessible by users with sysop right.</div>' );
			return;
		}

		$target_lang = $request->getVal( 'lang' );

		if ( empty( $target_lang ) ) {
			$formOpts = [
				'id' => 'select_lang',
				'method' => 'get',
				'action' => $this->getTitle()->getFullUrl()
			];
			$out->addHTML(
				Html::openElement( 'form', $formOpts ) . "<br>" .
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
				Html::submitButton( "Add Corrections", array() ) .
				"<br><br>" .
				Html::submitButton( "Edit Corrections", array( 'name' => 'update_existing' ) ) .
				Html::closeElement( 'form' )
			);
			return;
		}

		$page_action = $request->getVal( 'page_action' );
		if ( $page_action == 'save_correction' ) {
			$this->saveCorrection();
			$out->addHTML( '<div style="background-color:#28dc28;color:white;padding:5px;">Correction Saved.</div>' );
		} else if ( $page_action == 'edit_corrections' ) {
			$updated_count = $this->editCorrection();
			$out->addHTML( '<div style="background-color:#28dc28;color:white;padding:5px;">'. $updated_count .' Corrections Updated.</div>' );
		}

		if ( $request->getVal( 'update_existing' ) == '' ) {
			$formOpts = [
				'id' => 'add_correction',
				'method' => 'post',
				'action' => $this->getTitle()->getFullUrl()
			];

			$out->addHTML(
				Html::openElement( 'form', $formOpts ) . "<br>" .
				Html::element( 'input', [ 'name' => 'lang', 'value' => $target_lang, 'type' => 'hidden' ] ) .
				Html::label( "Original String:","", array( "for" => "original_str" ) ) .
				Html::textarea( "original_str", "" ) . "<br>" .
				Html::label( "Corrected String:","", array( "for" => "corrected_str" ) ) . "<br>" .
				Html::textarea( "corrected_str", "" ) . "<br>" .
				Html::element( 'input', [ 'name' => 'page_action', 'value' => 'save_correction', 'type' => 'hidden' ] ) .
				Html::submitButton( "Add Correction", array() ) .
				Html::closeElement( 'form' )
			);
		} else {
			$formOpts = [
				'id' => 'select_range',
				'method' => 'get',
				'action' => $this->getTitle()->getFullUrl()
			];

			$out->addHTML(
				Html::openElement( 'form', $formOpts ) . "<br>" .
				Html::element( 'input', [ 'name' => 'lang', 'value' => $target_lang, 'type' => 'hidden' ] ) .
				Html::label( "Select a range:","", array( "for" => "page_offset" ) ) . "<br>" .
				Html::openElement( 'select', array( "id" => "page_offset", "name" => "page_offset", "style" => "width:100%;" ) )
			);

			$dbr = wfGetDB( DB_SLAVE );
			$corrections_count = $dbr->selectField( 
				TranslationCorrections::TABLE,
				[ 'COUNT(*)' ],
				[ 'true' ],
				__METHOD__
			);

			$limit = 20;
			if ( $corrections_count < $limit ) {
				$limit = $corrections_count;
			}
			$offsets = range( 0, $corrections_count, $limit );
			$current_offset = $request->getVal( 'page_offset' );
			if ( $current_offset == '' ) {
				$current_offset = 0;
			}

			foreach( $offsets as $offset ) {
				$to_offset = min( $offset + $limit, $corrections_count );
				if ( $offset == $to_offset ) {
					continue;
				}
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
				Html::submitButton( "Get Corrections List", array() ) .
				Html::closeElement( 'form' )
			);

			$dbr = wfGetDB( DB_SLAVE );
			$res = $dbr->select( 
				TranslationCorrections::TABLE,
				[ 'id', 'original_str', 'corrected_str' ],
				[ 'lang' => $target_lang ],
				__METHOD__,
				array( 'OFFSET' => $current_offset, 'LIMIT' => $limit )
			);

			if ( $res->numRows() > 0 ) {
				$formOpts = [
					'id' => 'edit_corrections',
					'method' => 'post',
					'action' => $this->getTitle()->getFullUrl(),
					'style' => 'clear:both;'
				];

				$out->addHTML(
					Html::openElement( 'form', $formOpts ) . "<br>" .
					Html::element( 'input', [ 'name' => 'lang', 'value' => $target_lang, 'type' => 'hidden' ] ) .
					Html::element( 'input', [ 'name' => 'page_offset', 'value' => $current_offset, 'type' => 'hidden' ] ) .
					Html::element( 'input', [ 'name' => 'page_limit', 'value' => $limit, 'type' => 'hidden' ] ) .
					Html::element( 'input', [ 'name' => 'update_existing', 'value' => 1, 'type' => 'hidden' ] ) .
					Html::element( 'input', [ 'name' => 'page_action', 'value' => 'edit_corrections', 'type' => 'hidden' ] )
				);
				foreach ( $res as $row ) {
					$out->addHTML( '
						<div>
							<div style="float:left;width:45%;height:100px;">
								' . Html::textarea( $row->id . '_original_str', $row->original_str ) . '
							</div>
							<div style="float:left;margin-left:3%;margin-right:3%;border-left: 2px solid grey;height: 100px;"></div>
							<div style="float:left;width:45%;height:100px;">
								'. Html::textarea( $row->id . '_corrected_str', $row->corrected_str ) .'
							</div>
						</div>
						<br>
					' );
				}
				$out->addHTML(
					"<br>" .
					Html::submitButton( "Update Corrections", array() ) .
					Html::closeElement( 'form' )
				);
			}
		}
	}

	function saveCorrection() {
		$dbw = wfGetDB( DB_MASTER );
		$request = $this->getRequest();

		$original_str = $request->getVal( 'original_str' );
		$corrected_str = $request->getVal( 'corrected_str' );
		$target_lang = $request->getVal( 'lang' );

		$dbw->insert(
			TranslationCorrections::TABLE,
			[ 'lang' => $target_lang, 'original_str' => $original_str, 'corrected_str' => $corrected_str ],
			__METHOD__
		);
	}

	function editCorrection() {
		$dbr = wfGetDB( DB_SLAVE );
		$dbw = wfGetDB( DB_MASTER );
		$request = $this->getRequest();
		$updated_count = 0;

		$current_offset = $request->getVal( 'page_offset' );
		$page_limit = $request->getVal( 'page_limit' );
		$target_lang = $request->getVal( 'lang' );

		$res = $dbr->select( 
			TranslationCorrections::TABLE,
			[ 'id', 'original_str', 'corrected_str' ],
			[ 'lang' => $target_lang ],
			__METHOD__,
			array( 'OFFSET' => $current_offset, 'LIMIT' => $page_limit )
		);
		foreach( $res as $row ) {
			$original_str = $request->getVal( $row->id . '_original_str' );
			$corrected_str = $request->getVal( $row->id . '_corrected_str' );
			if ( empty( $original_str ) || empty( $corrected_str ) ) {
				continue;
			}
			if ( $original_str != $row->original_str || $corrected_str != $row->corrected_str ) {
				$updated_count++;
				$dbw->update(
					TranslationCorrections::TABLE,
					[ 'original_str' => $original_str, 'corrected_str' => $corrected_str ],
					array( 'id' => $row->id ),
					__METHOD__
				);
			}
		}
		return $updated_count;
	}

}
