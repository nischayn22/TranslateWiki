<?php

use Google\Cloud\Translate\TranslateClient;

/**
	Translates contents of a page from one lang to another using Google Translate.
	Uses its own translation cache which can be corrected/approved using Special:ApproveTranslations
**/

class AutoTranslate {

	private $pageId;

	private $googleTranslateProjectId;

	private $translateTo;

	function __construct( $translateTo ) {
		global $wgTranslateWikiGtProjectId;

		$this->googleTranslateProjectId = $wgTranslateWikiGtProjectId;
		$this->translateTo = $translateTo;
	}


	function translateTitle( $pageId ) {
        assert(!empty($pageId));
        $this->pageId = $pageId;
		$title = Revision::newFromPageId( $this->pageId )->getTitle();
		return $this->translateText( $title->getFullText() );
	}

	function translate( $pageId ) {
        assert(!empty($pageId));
        $this->pageId = $pageId;
		$revision = Revision::newFromPageId( $this->pageId );
		$content = ContentHandler::getContentText( $revision->getContent( Revision::RAW ) );
		return $this->translateWikiText( $content );
	}

	private function translateInternalLink( $link_str ) {
		$link_parts = explode( '|', $link_str );
		$translated_link = $this->translateText( $link_parts[0] );

		if ( count( $link_parts ) == 2 ) {
			return $translated_link . '|' . $this->translateText( $link_parts[1] );
		}
		return $translated_link;
	}

	private function translateTemplateContents( $templateContent ) {
		$pos = strpos( $templateContent, '|' );
		$templateName = substr( $templateContent, 0, $pos );
		$templateParametersContent = substr( $templateContent, $pos + 1, strlen( $templateContent ) - ( $pos + 1 ) );

		$translatedTemplateContent = $templateName . '|' . $this->translateWikiText( $templateParametersContent, true );
		return $translatedTemplateContent;
	}

	private function translateText( $text ) {
		if ( empty( trim( $text ) ) ) {
			return $text;
		}

		// trim text and then join the parts back as Google trims them
		$ltrimmed = ltrim( $text );

		$ltrim = '';
		if ( strlen( $text ) > strlen( $ltrimmed ) ) {
			$ltrim = substr( $text, 0, strlen( $text ) - strlen( $ltrimmed ) );
		}

		$rtrim = '';

		$rtrimmed = trim( $ltrimmed );
		if ( strlen( $ltrimmed ) > strlen( $rtrimmed ) ) {
			$rtrim = substr( $ltrimmed, strlen( $rtrimmed ), strlen( $ltrimmed ) - strlen( $rtrimmed ) );
		}

		$md5 = md5( $rtrimmed );

		# The target language
		$target = $this->translateTo;

		$translated_string = '';
		$cache = TranslationCache::getCache( $md5, $target );
		if ( $cache ) {
			$translated_string = $cache;
		} else {
			# Your Google Cloud Platform project ID
			$projectId = $this->googleTranslateProjectId;

			$translate = new TranslateClient([
				'projectId' => $projectId
			]);

			# Translates some text into Russian
			$translation = $translate->translate($rtrimmed, [
				'target' => $target,
				'format' => 'text'
			]);

			$translated_string = $translation['text'];
			TranslationCache::setCache( $this->pageId, $target, $rtrimmed, $translated_string );
		}
		return $ltrim . $translated_string . $rtrim;
	}

	// TODO: DISPLAYTITLE, <includeonly>, etc

	// $templateContent: true if $content provided is content inside a template and parameter names should not be translated

	function translateWikiText( $content, $templateContent = false ) {
		assert( !empty( $this->googleTranslateProjectId ) );
		$translated_content = '';

		$len = strlen( $content );
		$curr_str = '';
		$state_deep = 0;
		$state_arr = array( 'CONTENT' );

		for ( $i = 0; $i < $len; $i++ ){

			if ( $content[$i] == "<" && $content[$i+1] == "!" && $state_arr[$state_deep] == 'CONTENT' ) {
				if ( $content[$i+2] == "-" && $content[$i+3] == "-" ) {
					$translated_content .= $this->translateText( $curr_str );
					$curr_str = '';
					$state_arr[] = 'COMMENTBEGIN';
					$state_deep++;
					$i = $i + 3;
					continue;
				}
			}

			if ( $content[$i] == "-" && $content[$i+1] == "-" && $state_arr[$state_deep] == 'COMMENTBEGIN' ) {
				if ( $content[$i+2] == ">" ) {
					$translated_content .=  "<!--" . $curr_str . "-->";
					$curr_str = '';

					array_pop( $state_arr );
					$state_deep--;
					$i = $i + 2;
					continue;
				}
			}

			if ( $content[$i] == "'" && $content[$i+1] == "'" && $state_arr[$state_deep] == 'CONTENT' ) {
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';
				if ( $content[$i+2] == "'" && $content[$i+3] == "'" && $content[$i+4] == "'" ) {
					$state_arr[] = 'BOLDITALICBEGIN';
					$state_deep++;
					$i = $i + 4;
					continue;
				} else if ( $content[$i+2] == "'" ) {
					$state_arr[] = 'BOLDBEGIN';
					$state_deep++;
					$i = $i + 2;
					continue;
				} else {
					$state_arr[] = 'ITALICBEGIN';
					$state_deep++;
					$i = $i + 1;
					continue;
				}
			}

			if ( $content[$i] == "'" && $content[$i+1] == "'" && $state_arr[$state_deep] == 'BOLDITALICBEGIN' ) {
				$translated_content .=  "'''''" . $this->translateWikiText( $curr_str ) . "'''''";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 4;
				continue;
			}
			if ( $content[$i] == "'" && $content[$i+1] == "'" && $state_arr[$state_deep] == 'BOLDBEGIN' ) {
				$translated_content .=  "'''" . $this->translateWikiText( $curr_str ) . "'''";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 2;
				continue;
			}
			if ( $content[$i] == "'" && $content[$i+1] == "'" && $state_arr[$state_deep] == 'ITALICBEGIN' ) {
				$translated_content .=  "''" . $this->translateWikiText( $curr_str ) . "''";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 1;
				continue;
			}

			if ( $content[$i] == "=" && $content[$i+1] == "=" && $state_arr[$state_deep] == 'CONTENT' ) {
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';

				if ( $content[$i+2] == "=" && $content[$i+3] == "=" && $content[$i+4] == "=" ) {
					$state_arr[] = 'SEC5BEGIN';
					$state_deep++;
					$i = $i + 4;
					continue;
				} else if ( $content[$i+2] == "=" && $content[$i+3] == "=" ) {
					$state_arr[] = 'SEC4BEGIN';
					$state_deep++;
					$i = $i + 3;
					continue;
				} else if ( $content[$i+2] == "=" ) {
					$state_arr[] = 'SEC3BEGIN';
					$state_deep++;
					$i = $i + 2;
					continue;
				} else {
					$state_arr[] = 'SEC2BEGIN';
					$state_deep++;
					$i = $i + 1;
					continue;
				}
			}

			if ( $content[$i] == "=" && $content[$i+1] == "=" && $state_arr[$state_deep] == 'SEC5BEGIN' ) {
				$translated_content .=  "=====" . ucfirst( trim( $this->translateWikiText( $curr_str ) ) ) . "=====";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 4;
				continue;
			}

			if ( $content[$i] == "=" && $content[$i+1] == "=" && $state_arr[$state_deep] == 'SEC4BEGIN' ) {
				$translated_content .=  "====" . ucfirst( trim( $this->translateWikiText( $curr_str ) ) ) . "====";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 3;
				continue;
			}

			if ( $content[$i] == "=" && $content[$i+1] == "=" && $state_arr[$state_deep] == 'SEC3BEGIN' ) {
				$translated_content .=  "===" . ucfirst( trim( $this->translateWikiText( $curr_str ) ) ) . "===";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 2;
				continue;
			}

			if ( $content[$i] == "=" && $content[$i+1] == "=" && $state_arr[$state_deep] == 'SEC2BEGIN' ) {
				$translated_content .=  "==" . ucfirst( trim( $this->translateWikiText( $curr_str ) ) ) . "==";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 1;
				continue;
			}

			if ( $content[$i] == '[' && $state_arr[$state_deep] == 'CONTENT' ) {

				// Translate content accumulated so far
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';

				$state_arr[] = 'LINKBEGIN';
				$state_deep++;
				continue;
			}

			// Internal Link Begin
			if ( $content[$i] == '[' && $state_arr[$state_deep] == 'LINKBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'INTERNALLINKBEGIN';
				continue;
			}

			// External Link End
			// No need to translate
			if ( $content[$i] == ']' && $state_arr[$state_deep] == 'LINKBEGIN' ) {
				array_pop( $state_arr );
				$state_deep--;
				$translated_content .= "[" . $curr_str . "]";
				$curr_str = '';
				continue;
			}

			// Internal Link End
			if ( $content[$i] == ']' && $state_arr[$state_deep] == 'INTERNALLINKBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'INTERNALLINKEND';
				continue;
			}

			if ( $content[$i] == ']' && $state_arr[$state_deep] == 'INTERNALLINKEND' ) {
				array_pop( $state_arr );
				$state_deep--;
				$translated_content .= "[[" . $this->translateInternalLink( $curr_str ) . "]]";
				$curr_str = '';
				continue;
			}

			if ( $content[$i] == '{' && $state_arr[$state_deep] == 'CONTENT' ) {

				// Translate content accumulated so far
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';

				$state_arr[] = 'CURLYBEGIN';
				$state_deep++;
				continue;
			}

			if ( $content[$i] == '#'&& substr( $content, $i+1, 8 ) == 'REDIRECT' && $state_arr[$state_deep] == 'CONTENT' ) {
				$curr_str .= "#REDIRECT";
				$i = $i + 8;
				continue;
			}

			if ( $content[$i] == '{'&& $content[$i+1] == '#' && $state_arr[$state_deep] == 'CURLYBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'PARSERFUNCBEGIN';
				continue;
			}

			if ( $content[$i] == '{' && $state_arr[$state_deep] == 'CURLYBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'TEMPLATEBEGIN';
				continue;
			}

			// Handle nested templates
			if ( $content[$i] == '{' && in_array( $state_arr[$state_deep], array( 'PARSERFUNCBEGIN', 'TEMPLATEBEGIN' ) ) ) {
				$state_arr[] = 'NESTEDTEMPLATEBEGIN';
				$state_deep++;
				$curr_str .= $content[$i];
				continue;
			}
			if ( $content[$i] == '{' && $state_arr[$state_deep] == 'NESTEDTEMPLATEBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'NESTEDTEMPLATE';
				$curr_str .= $content[$i];
				continue;
			}
			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'NESTEDTEMPLATE' ) {
				array_pop( $state_arr );
				$state_arr[] = 'NESTEDTEMPLATEEND';
				$curr_str .= $content[$i];
				continue;
			}
			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'NESTEDTEMPLATEEND' ) {
				array_pop( $state_arr );
				$state_deep--;
				$curr_str .= $content[$i];
				continue;
			}

			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'PARSERFUNCBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'PARSERFUNCEND';
				continue;
			}

			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'PARSERFUNCEND' ) {
				array_pop( $state_arr );
				$state_deep--;
				$translated_content .= "{{#" . $curr_str . "}}";
				$curr_str = '';
				continue;
			}

			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'TEMPLATEBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'TEMPLATEEND';
				continue;
			}

			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'TEMPLATEEND' ) {
				array_pop( $state_arr );
				$state_deep--;

				if ( strpos( $curr_str, '|' ) !== false ) {
					$translated_content .= "{{" . $this->translateTemplateContents( $curr_str ) . "}}";
				} else {
					$translated_content .= "{{" . $curr_str . "}}";
				}

				$curr_str = '';
				continue;
			}

			if ( $content[$i] == '_' && $state_arr[$state_deep] == 'CONTENT' ) {
				$state_arr[] = 'UNDERSCBEGIN';
				$state_deep++;
				continue;
			}
			if ( $content[$i] != '_' && $state_arr[$state_deep] == 'UNDERSCBEGIN' ) {
				array_pop( $state_arr );
				$state_deep--;

				// We didn't add this before so add now
				$curr_str .= '_';
			}

			if ( $content[$i] == '_' && $state_arr[$state_deep] == 'UNDERSCBEGIN' ) {
				// Translate content accumulated so far
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';

				array_pop( $state_arr );
				$state_arr[] = 'MAGICBEGIN';
				continue;
			}
			if ( $content[$i] == '_' && $state_arr[$state_deep] == 'MAGICBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'MAGICEND';
			}
			if ( $content[$i] == '_' && $state_arr[$state_deep] == 'MAGICEND' ) {
				array_pop( $state_arr );
				$state_deep--;
				$translated_content .= "__" . $curr_str . "__";
				$curr_str = '';
				continue;
			}

			if ( $templateContent && $state_arr[$state_deep] == 'CONTENT' && in_array( $content[$i], array( '|', '=' ) ) ) {
				if ( $content[$i] == '=' ) { //Its a parameter name of a template
					$translated_content .= $curr_str . '=';
					$curr_str = '';
				} else if ( $content[$i] == '|' ) {
					$translated_content .= $this->translateText( $curr_str ) . '|';
					$curr_str = '';
				}
				continue;
			}

			// Reached here means add it to curr_str
			$curr_str .= $content[$i];
		}
		$translated_content .= $this->translateText( $curr_str );

		return $translated_content;
	}

}