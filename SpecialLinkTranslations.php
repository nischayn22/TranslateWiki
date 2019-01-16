<?php

/**
 * 
 * 
 */
class SpecialLinkTranslations extends QueryPage {
	private $search_pattern;
	private $lang;
	private $protocol;

	private $mungedQuery = false;

	function setParams( $params ) {
		$this->mQuery = $params['query'];
		$this->mProt = $params['protocol'];
	}

	public function __construct() {
		parent::__construct( 'LinkTranslations' );
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
			'Add Link Translations' => 'Special:LinkTranslations',
			'Edit Link Translations' => 'Special:LinkTranslations/edit'
		];

		$links = [];
		foreach ( $linkDefs as $name => $page ) {
			$title = Title::newFromText( $page );
			$links[] = $this->getLinkRenderer()->makeLink( $title, new HtmlArmor( $name ) );
		}
		$linkStr = $this->getContext()->getLanguage()->pipeList( $links );
		$out->setSubtitle( $linkStr );

		$this->lang = $request->getVal( 'lang' );

		if ( empty( $this->lang ) ) {
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
			$el_id = $request->getVal( 'el_id' );
			$this->search_pattern = $request->getVal( 'search_pattern' );

			if ( !empty( $el_id ) ) {
				$formOpts = [
					'id' => 'add_translation',
					'method' => 'post',
					'action' => $this->getTitle()->getFullUrl()
				];

				$out->addHTML(
					Html::openElement( 'form', $formOpts ) . "<br>" .
					Html::element( 'input', [ 'name' => 'lang', 'value' => $this->lang, 'type' => 'hidden' ] ) .
					'<h5>'. $this->search_pattern .'</h5>' . 
					Html::label( "Translated String:","", array( "for" => "translated_str" ) ) . "<br>" .
					Html::textarea( "translated_str", "" ) . "<br>" .
					Html::element( 'input', [ 'name' => 'page_action', 'value' => 'save_translation', 'type' => 'hidden' ] ) .
					Html::submitButton( "Save Translation", array() ) .
					Html::closeElement( 'form' )
				);
			} else {
				$protocols_list = [];
				foreach ( $this->getConfig()->get( 'UrlProtocols' ) as $prot ) {
					if ( $prot !== '//' ) {
						$protocols_list[] = $prot;
					}
				}
				$out->addWikiMsg(
					'linksearch-text',
					'<nowiki>' . $this->getLanguage()->commaList( $protocols_list ) . '</nowiki>',
					count( $protocols_list )
				);

				$fields = [
					'search_pattern' => [
						'type' => 'text',
						'name' => 'search_pattern',
						'id' => 'search_pattern',
						'size' => 50,
						'label-message' => 'linksearch-pat',
						'default' => $this->search_pattern,
						'dir' => 'ltr',
					]
				];
				$htmlForm = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
				$htmlForm->addHiddenField( 'lang', $this->lang );
				$htmlForm->setAction( $this->getTitle()->getFullUrl() . "/" . $subpage );
				$htmlForm->setMethod( 'get' );
				$htmlForm->prepareForm()->displayForm( false );

				if ( !empty( $this->search_pattern ) ) {
					$target2 = $this->search_pattern;
					// Get protocol, default is http://
					$protocol = 'http://';
					$bits = wfParseUrl( $this->search_pattern );
					if ( isset( $bits['scheme'] ) && isset( $bits['delimiter'] ) ) {
						$protocol = $bits['scheme'] . $bits['delimiter'];
						// Make sure wfParseUrl() didn't make some well-intended correction in the
						// protocol
						if ( strcasecmp( $protocol, substr( $this->search_pattern, 0, strlen( $protocol ) ) ) === 0 ) {
							$target2 = substr( $this->search_pattern, strlen( $protocol ) );
						} else {
							// If it did, let LinkFilter::makeLikeArray() handle this
							$protocol = '';
						}
					}
					$this->protocol = $protocol;

					$this->setParams( [
						'query' => Parser::normalizeLinkUrl( $target2 ),
						'protocol' => $protocol 
					] );
					parent::execute( $subpage );
					if ( $this->mungedQuery === false ) {
						$out->addWikiMsg( 'linksearch-error' );
					}
				}
			}
		} else {
			$formOpts = [
				'id' => 'select_range',
				'method' => 'get',
				'action' => $this->getTitle()->getFullUrl() . "/" . $subpage
			];

			$out->addHTML(
				Html::openElement( 'form', $formOpts ) . "<br>" .
				Html::element( 'input', [ 'name' => 'lang', 'value' => $this->lang, 'type' => 'hidden' ] ) .
				Html::label( "Select a range:","", array( "for" => "page_offset" ) ) . "<br>" .
				Html::openElement( 'select', array( "id" => "page_offset", "name" => "page_offset", "style" => "width:100%;" ) )
			);

			$dbr = wfGetDB( DB_SLAVE );
			$corrections_count = $dbr->selectField( 
				LinkTranslations::TABLE,
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
				LinkTranslations::TABLE,
				[ 'id', 'original_str', 'corrected_str' ],
				[ 'lang' => $this->lang ],
				__METHOD__,
				array( 'OFFSET' => $current_offset, 'LIMIT' => $limit )
			);

			if ( $res->numRows() > 0 ) {
				$out->addHTML( '
					<div>
						<div style="float:left;width:30%;height:40px;">
							<h4>Original Link</h4>
						</div>
						<div style="float:left;margin-left:1%;margin-right:1%;height: 10px;"></div>
						<div style="float:left;width:30%;height:40px;">
							<h4>Translated Link</h4>
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
						Html::element( 'input', [ 'name' => 'lang', 'value' => $this->lang, 'type' => 'hidden' ] ) .
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
								Html::submitButton( "Update Translation", array( 'name' => 'update' ) ) .
								'&emsp;' .
								Html::submitButton( "Delete Translation", array( 'name' => 'delete' ) ) .
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

	/**
	 * Return an appropriately formatted LIKE query and the clause
	 *
	 * @param string $query Search pattern to search for
	 * @param string $prot Protocol, e.g. 'http://'
	 *
	 * @return array
	 */
	static function mungeQuery( $query, $prot ) {
		$field = 'el_index';
		$dbr = wfGetDB( DB_REPLICA );

		if ( $query === '*' && $prot !== '' ) {
			// Allow queries like 'ftp://*' to find all ftp links
			$rv = [ $prot, $dbr->anyString() ];
		} else {
			$rv = LinkFilter::makeLikeArray( $query, $prot );
		}

		if ( $rv === false ) {
			// LinkFilter doesn't handle wildcard in IP, so we'll have to munge here.
			$pattern = '/^(:?[0-9]{1,3}\.)+\*\s*$|^(:?[0-9]{1,3}\.){3}[0-9]{1,3}:[0-9]*\*\s*$/';
			if ( preg_match( $pattern, $query ) ) {
				$rv = [ $prot . rtrim( $query, " \t*" ), $dbr->anyString() ];
				$field = 'el_to';
			}
		}

		return [ $rv, $field ];
	}

	public function getQueryInfo() {
		$dbr = wfGetDB( DB_REPLICA );
		// strip everything past first wildcard, so that
		// index-based-only lookup would be done
		list( $this->mungedQuery, $clause ) = self::mungeQuery( $this->mQuery, $this->mProt );
		if ( $this->mungedQuery === false ) {
			// Invalid query; return no results
			return [ 'tables' => 'page', 'fields' => 'page_id', 'conds' => '0=1' ];
		}

		$stripped = LinkFilter::keepOneWildcard( $this->mungedQuery );
		$like = $dbr->buildLike( $stripped );
		$retval = [
			'tables' => [ 'page', 'externallinks' ],
			'fields' => [
				'namespace' => 'page_namespace',
				'title' => 'page_title',
				'el_id' => 'el_id',
				'url' => 'el_to'
			],
			'conds' => [
				'page_id = el_from',
				"$clause $like"
			],
			'options' => [ 'USE INDEX' => 'el_to' ]
		];
		return $retval;
	}

	function getOrderFields() {
		return [];
	}

	function linkParameters() {
		return [
			'lang' => $this->lang,
			'search_pattern' => $this->search_pattern
		];
	}

	function formatResult( $skin, $result ) {
		$title = new TitleValue( (int)$result->namespace, $result->title );
		$pageLink = $this->getLinkRenderer()->makeLink( $title );

		$url = $result->url;
		$urlLink = Linker::makeExternalLink( $url, $url );
		$add_translation_url = $this->getTitle()->getFullUrl() . '?lang=' . $this->lang . '&el_id='. $result->el_id . '&search_pattern=' . $result->url;
		return $urlLink . ' is linked from ' . $pageLink . ' (<a href="'. $add_translation_url .'">Add Translation</a> )';
	}

	function saveCorrection() {
		$dbw = wfGetDB( DB_MASTER );
		$request = $this->getRequest();

		$original_str = $request->getVal( 'original_str' );
		$corrected_str = $request->getVal( 'corrected_str' );
		$this->lang = $request->getVal( 'lang' );

		$dbw->insert(
			LinkTranslations::TABLE,
			[ 'lang' => $this->lang, 'original_str' => $original_str, 'corrected_str' => $corrected_str ],
			__METHOD__
		);
	}

	function deleteCorrection() {
		$dbr = wfGetDB( DB_SLAVE );
		$dbw = wfGetDB( DB_MASTER );
		$request = $this->getRequest();

		$current_offset = $request->getVal( 'page_offset' );
		$this->lang = $request->getVal( 'lang' );

		$correction_id = $request->getVal( 'correction_id' );

		$result = $dbr->delete(
			LinkTranslations::TABLE,
			[ 'id' => $correction_id, 'lang' => $this->lang ],
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
		$this->lang = $request->getVal( 'lang' );

		$correction_id = $request->getVal( 'correction_id' );

		$row = $dbr->selectRow( 
			LinkTranslations::TABLE,
			[ 'original_str', 'corrected_str' ],
			[ 'id' => $correction_id, 'lang' => $this->lang ],
			__METHOD__
		);
		$original_str = $request->getVal( 'original_str' );
		$corrected_str = $request->getVal( 'corrected_str' );
		if ( empty( $original_str ) || empty( $corrected_str ) ) {
			return false;
		}
		if ( $original_str != $row->original_str || $corrected_str != $row->corrected_str ) {
			$dbw->update(
				LinkTranslations::TABLE,
				[ 'original_str' => $original_str, 'corrected_str' => $corrected_str ],
				array( 'id' => $correction_id ),
				__METHOD__
			);
			return true;
		}
		return false;
	}

}
