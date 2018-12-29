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
	public function execute( $subpage ) {
		global $wgTranslateWikiLanguages;

		$this->setHeaders();
		$request = $this->getRequest();
		$out = $this->getOutput();

		if( !in_array( 'sysop', $this->getUser()->getEffectiveGroups()) ) {
			$out->addHTML( '<div class="errorbox">This page is only accessible by users with sysop right.</div>' );
			return;
		}

		$linkDefs = [
			'Add Corrections' => 'Special:TranslationCorrections',
			'Edit Corrections' => 'Special:TranslationCorrections/edit'
		];

		$links = [];
		foreach ( $linkDefs as $name => $page ) {
			$title = Title::newFromText( $page );
			$links[] = $this->getLinkRenderer()->makeLink( $title, new HtmlArmor( $name ) );
		}
		$linkStr = $this->getContext()->getLanguage()->pipeList( $links );
		$out->setSubtitle( $linkStr );

		$target_lang = $request->getVal( 'lang' );

		if ( empty( $target_lang ) ) {
			$formOpts = [
				'id' => 'select_lang',
				'method' => 'get',
				'action' => $this->getTitle()->getFullUrl() . "/" . $subpage
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
			$start_msg = "Start Adding";
			if ( $subpage == "edit" ) {
				$start_msg = "Start Editing";
			}
			$out->addHTML( Html::closeElement( 'select' ) . "<br>" );
			$out->addHTML(
				"<br>" .
				Html::submitButton( $start_msg, array() ) .
				Html::closeElement( 'form' )
			);
			return;
		}

		$page_action = $request->getVal( 'page_action' );
		if ( $page_action == 'save_correction' ) {
			$this->saveCorrection();
			$out->addHTML( '<div style="background-color:#28dc28;color:white;padding:5px;">Correction Saved.</div>' );
		} else if ( $page_action == 'edit_corrections' ) {
			$update = $request->getVal( 'update' );
			if ( $update != '' ) {
				$update_status = $this->editCorrection();
				if ( $update_status ) {
					$out->addHTML( '<div style="background-color:#28dc28;color:white;padding:5px;">Correction updated successfully!</div>' );
				} else {
					$out->addHTML( '<div style="background-color:red;color:white;padding:5px;">Update failed!</div>' );
				}
			} else {
				$update_status = $this->deleteCorrection();
				if ( $update_status ) {
					$out->addHTML( '<div style="background-color:#28dc28;color:white;padding:5px;">Correction deleted successfully!</div>' );
				} else {
					$out->addHTML( '<div style="background-color:red;color:white;padding:5px;">Delete failed!</div>' );
				}
			}
		}

		if ( $subpage != 'edit' ) {
			$formOpts = [
				'id' => 'add_correction',
				'method' => 'post',
				'action' => $this->getTitle()->getFullUrl() . "/" . $subpage
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
				'action' => $this->getTitle()->getFullUrl() . "/" . $subpage
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
				$out->addHTML( '
					<div>
						<div style="float:left;width:30%;height:40px;">
							<h4>Original String</h4>
						</div>
						<div style="float:left;margin-left:1%;margin-right:1%;height: 10px;"></div>
						<div style="float:left;width:30%;height:40px;">
							<h4>Corrected String</h4>
						</div>
						<div style="float:left;margin-left:1%;margin-right:1%;height: 10px;"></div>
						<div style="float:left;width:30%;height:40px;">
							<h4>Actions</h4>
						</div>
					</div>
				');
				foreach ( $res as $row ) {
					$formOpts = [
						'id' => 'edit_corrections',
						'method' => 'post',
						'action' => $this->getTitle()->getFullUrl() . "/" . $subpage,
						'style' => 'clear:both;'
					];

					$out->addHTML(
						"<br>" .
						Html::openElement( 'form', $formOpts ) .
						Html::element( 'input', [ 'name' => 'lang', 'value' => $target_lang, 'type' => 'hidden' ] ) .
						Html::element( 'input', [ 'name' => 'page_offset', 'value' => $current_offset, 'type' => 'hidden' ] ) .
						Html::element( 'input', [ 'name' => 'page_limit', 'value' => $limit, 'type' => 'hidden' ] ) .
						Html::element( 'input', [ 'name' => 'update_existing', 'value' => 1, 'type' => 'hidden' ] ) .
						Html::element( 'input', [ 'name' => 'correction_id', 'value' => $row->id, 'type' => 'hidden' ] ) .
						Html::element( 'input', [ 'name' => 'page_action', 'value' => 'edit_corrections', 'type' => 'hidden' ] )
					);
					$out->addHTML( '
						<div>
							<div style="float:left;width:30%;height:100px;">
								' . Html::textarea( 'original_str', $row->original_str ) . '
							</div>
							<div style="float:left;margin-left:1%;margin-right:1%;height: 100px;"></div>
							<div style="float:left;width:30%;height:100px;">
								'. Html::textarea( 'corrected_str', $row->corrected_str ) .'
							</div>
							<div style="float:left;margin-left:1%;margin-right:1%;height: 100px;"></div>
							<div style="float:left;width:30%;height:100px;">
								'. 
								Html::submitButton( "Update Correction", array( 'name' => 'update' ) ) .
								'&emsp;' .
								Html::submitButton( "Delete Correction", array( 'name' => 'delete' ) ) .
								Html::closeElement( 'form' )
								.'
							</div>
						</div>
						<br>
					' );
				}
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

	function deleteCorrection() {
		$dbr = wfGetDB( DB_SLAVE );
		$dbw = wfGetDB( DB_MASTER );
		$request = $this->getRequest();

		$current_offset = $request->getVal( 'page_offset' );
		$target_lang = $request->getVal( 'lang' );

		$correction_id = $request->getVal( 'correction_id' );

		$result = $dbr->delete(
			TranslationCorrections::TABLE,
			[ 'id' => $correction_id, 'lang' => $target_lang ],
			__METHOD__
		);
		if ( $result ) {
			return true;
		} else {
			return false;
		}
	}

	function editCorrection() {
		$dbr = wfGetDB( DB_SLAVE );
		$dbw = wfGetDB( DB_MASTER );
		$request = $this->getRequest();

		$current_offset = $request->getVal( 'page_offset' );
		$target_lang = $request->getVal( 'lang' );

		$correction_id = $request->getVal( 'correction_id' );

		$row = $dbr->selectRow( 
			TranslationCorrections::TABLE,
			[ 'original_str', 'corrected_str' ],
			[ 'id' => $correction_id, 'lang' => $target_lang ],
			__METHOD__
		);
		$original_str = $request->getVal( 'original_str' );
		$corrected_str = $request->getVal( 'corrected_str' );
		if ( empty( $original_str ) || empty( $corrected_str ) ) {
			return false;
		}
		if ( $original_str != $row->original_str || $corrected_str != $row->corrected_str ) {
			$dbw->update(
				TranslationCorrections::TABLE,
				[ 'original_str' => $original_str, 'corrected_str' => $corrected_str ],
				array( 'id' => $correction_id ),
				__METHOD__
			);
			return true;
		}
		return false;
	}

}
