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
			$start_msg = "Start";

			$out->addHTML( Html::closeElement( 'select' ) . "<br>" );
			$out->addHTML(
				"<br>" .
				Html::submitButton( $start_msg, array() ) .
				Html::closeElement( 'form' )
			);
			return;
		}

		$page_action = $request->getVal( 'page_action' );
		$url = $request->getVal( 'url' );
		$this->search_pattern = $request->getVal( 'search_pattern' );

		if ( empty( $url ) ) {
			if ( $page_action == 'save_translation' ) {
				$this->saveTranslation();
				$out->addHTML( '<div style="background-color:#28dc28;color:white;padding:5px;">Translation Saved.</div>' );
			} else if ( $page_action == 'update_translation' ) {
				$this->updateTranslation();
				$out->addHTML( '<div style="background-color:#28dc28;color:white;padding:5px;">Translation Updated.</div>' );
			}
		}

		if ( !empty( $url ) ) {
			$urlLink = Linker::makeExternalLink( $url, $url );
			$formOpts = [
				'id' => 'add_translation',
				'method' => 'post',
				'action' => $this->getTitle()->getFullUrl()
			];

			$out->addHTML(
				Html::openElement( 'form', $formOpts ) . "<br>" .
				Html::element( 'input', [ 'name' => 'lang', 'value' => $this->lang, 'type' => 'hidden' ] ) .
				Html::element( 'input', [ 'name' => 'original_str', 'value' => $url, 'type' => 'hidden' ] ) .
				Html::element( 'input', [ 'name' => 'search_pattern', 'value' => $this->search_pattern, 'type' => 'hidden' ] ) .
				Html::element( 'input', [ 'name' => 'limit', 'value' => $this->limit, 'type' => 'hidden' ] ) .
				Html::element( 'input', [ 'name' => 'offset', 'value' => $this->offset, 'type' => 'hidden' ] ) .
				'<span>Url: '. $urlLink .'</span><br>' .
				Html::label( "Translated String:","", array( "for" => "translated_str" ) ) . "<br>" .
				Html::textarea( "translated_str", $request->getVal( 'translated_str' ) ) . "<br>" .
				Html::element( 'input', [ 'name' => 'page_action', 'value' => $page_action, 'type' => 'hidden' ] ) .
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
			'tables' => [ 'externallinks', LinkTranslations::TABLE ],
			'fields' => [
				'count' => 'COUNT(el_to)',
				'url' => 'el_to',
				'translated_str' => 'translated_str'
			],
			'join_conds' => [
				LinkTranslations::TABLE => [ "LEFT OUTER JOIN", [ 'el_to = original_str' ] ],
			],
			'conds' => [
				"$clause $like"
			],
			'options' => [ 'GROUP BY' => 'el_to' ]
		];
		return $retval;
	}

	function getOrderFields() {
		return ['count'];
	}

	function linkParameters() {
		return [
			'lang' => $this->lang,
			'search_pattern' => $this->search_pattern
		];
	}

	function outputResults( $out, $skin, $dbr, $res, $num, $offset ) {
		$html = '
		<table class="wikitable">
			<tr>
				<th>Link</th>
				<th>Repetitions</th>
				<th>Translation</th>
				<th>Actions</th>
		';

		for ( $i = 0; $i < $num && $row = $res->fetchObject(); $i++ ) {
			$html .= $this->formatResult( $skin, $row );
		}

		$html .= '</table>';

		$out->addHTML( $html );
	}

	function formatResult( $skin, $result ) {
		$url = $result->url;
		$urlLink = Linker::makeExternalLink( $url, $url );
		$link_search_url = Linker::linkKnown( Title::makeTitle(NS_SPECIAL,'LinkSearch'), $result->count, array( 'target' => '_blank' ), array( 'target' => $result->url ) );
		$add_translation_url = $this->getTitle()->getFullUrl() . '?lang=' . $this->lang . '&url='. $result->url . '&search_pattern=' . $this->search_pattern . '&limit='. $this->limit .'&offset='. $this->offset .'&translated_str=' . $result->translated_str;

		$action = "Add";
		$externalLink = "--";
		if( !empty( $result->translated_str ) ) {
			$externalLink = Linker::makeExternalLink( $result->translated_str, $result->translated_str );
			$action = "Update";
			$add_translation_url .= '&page_action=update_translation';
		} else {
			$add_translation_url .= '&page_action=save_translation';
		}
		return '
		<tr>
			<td>' . $urlLink . '</td>
			<td>' . $link_search_url . '</td>
			<td>'. $externalLink .'</td>
			<td><a href="'. $add_translation_url . '">' . $action . '</a></td>
		</tr>';
	}

	function saveTranslation() {
		$dbw = wfGetDB( DB_MASTER );
		$request = $this->getRequest();

		$original_str = $request->getVal( 'original_str' );
		$translated_str = $request->getVal( 'translated_str' );
		$this->lang = $request->getVal( 'lang' );

		$dbw->insert(
			LinkTranslations::TABLE,
			[ 'lang' => $this->lang, 'original_str' => $original_str, 'translated_str' => $translated_str ],
			__METHOD__
		);
	}

	function updateTranslation() {
		$dbw = wfGetDB( DB_MASTER );
		$request = $this->getRequest();

		$original_str = $request->getVal( 'original_str' );
		$translated_str = $request->getVal( 'translated_str' );
		$this->lang = $request->getVal( 'lang' );

		$dbw->update(
			LinkTranslations::TABLE,
			[ 'lang' => $this->lang, 'translated_str' => $translated_str ],
			[ 'original_str' => $original_str ],
			__METHOD__
		);
	}

}
