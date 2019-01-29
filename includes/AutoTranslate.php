<?php

use Google\Cloud\Translate\TranslateClient;

/**
	Translates contents of a page from one lang to another using Google Translate.
	Uses its own translation cache which can be corrected/approved using Special:ApproveTranslations
**/

class AutoTranslate {
	private $pageId;

	private $googleTranslateKeyFilePath;

	private $translateTo;

	private $translationCache = null;
	private $translationCorrections = null;

	private $translationFragments = array();

	function __construct( $translateTo ) {
		global $googleTranslateKeyFilePath;

		$this->googleTranslateKeyFilePath = $googleTranslateKeyFilePath;
		$this->translateTo = $translateTo;
	}

	function translateTitle( $pageId, $shouldPurge = false ) {
        assert(!empty($pageId));
        $this->pageId = $pageId;
		if ( $this->translationCache == null ) {
			$this->translationCache = new TranslationCache( $pageId, $this->translateTo, $shouldPurge );
		}
		if ( $this->translationCorrections == null ) {
			$this->translationCorrections = new TranslationCorrections( $this->translateTo );
		}
		$title = Revision::newFromPageId( $this->pageId )->getTitle();
		return $this->translateText( $title->getFullText() );
	}

	function translate( $pageId, $shouldPurge = false ) {
        assert(!empty($pageId));
        $this->pageId = $pageId;
		if ( $this->translationCache == null ) {
			$this->translationCache = new TranslationCache( $pageId, $this->translateTo, $shouldPurge );
		}
		if ( $this->translationCorrections == null ) {
			$this->translationCorrections = new TranslationCorrections( $this->translateTo );
		}
		$revision = Revision::newFromPageId( $this->pageId );
		$content = ContentHandler::getContentText( $revision->getContent( Revision::RAW ) );
		$translated_content = $this->translateWikiText( $content );

		$this->translationCache->deleteUnusedCache();

		return $translated_content;
	}

	function getTranslationFragments() {
		return $this->translationFragments;
	}

	private function translateTableLine( $table_line, $line_type ) {
		if ( $line_type == "TABLE_START" ) {
			return $table_line;
		} else if ( $line_type == "CAPTION_START" ) {
			if ( count( explode( "|", $table_line ) ) == 2 ) {
				return "|+" . current( explode( "|", $table_line ) ) . "|" . $this->translateWikiText( end( explode( "|", $table_line ) ) );
			} else {
				return "|+" . $this->translateWikiText( $table_line );
			}
		} else if ( $line_type == "ROW_START" ) {
			return "|-" . $table_line;
		} else if ( $line_type == "HEADER_START" ) {
			$headers = explode( "!!", $table_line );
			foreach( $headers as &$header ) {
				if ( count( explode( "|", $header ) ) == 2 ) {
					$header = current( explode( "|", $header ) ) . "|" . $this->translateWikiText( end( explode( "|", $header ) ) );
				} else {
					$header = $this->translateWikiText( $header );
				}
			}
			return "!" . implode( "!!", $headers );
		} else if ( $line_type == "DATA_CELL_START" ) {
			$cells = explode( "||", $table_line );
			foreach( $cells as &$cell ) {
				if ( count( explode( "|", $cell ) ) == 2 ) {
					$cell = current( explode( "|", $cell ) ) . "|" . $this->translateWikiText( end( explode( "|", $cell ) ) );
				} else {
					$cell = $this->translateWikiText( $cell );
				}
			}
			return "|" . implode( "||", $cells );
		}
	}

	private function translateTable( $tableContent ) {
		$translated_content = "";
		$line_type = 'TABLE_START';
		$pos = 0;
		$state_pos = 0;
		while( $pos < strlen( $tableContent ) ) {
			$pos++;
			if ( $pos == strlen( $tableContent ) ) {
				$translated_content .= $this->translateTableLine( substr( $tableContent, $state_pos, $pos - $state_pos ), $line_type );
				break;
			}
			if ( $tableContent[$pos] == "\n" ) {
				continue;
			}
			if ( $tableContent[$pos-1] == "\n" ) {
				if ( $tableContent[$pos] == "|" && $tableContent[$pos+1] == "+" ) {
					$translated_content .= $this->translateTableLine( substr( $tableContent, $state_pos, $pos - $state_pos ), $line_type );
					$line_type = 'CAPTION_START';
					$state_pos = $pos + 2;
				} else if ( $tableContent[$pos] == "|" && $tableContent[$pos+1] == "-" ) {
					$translated_content .= $this->translateTableLine( substr( $tableContent, $state_pos, $pos - $state_pos ), $line_type );
					$line_type = 'ROW_START';
					$state_pos = $pos + 2;
				} else if ( $tableContent[$pos] == "!" ) {
					$translated_content .= $this->translateTableLine( substr( $tableContent, $state_pos, $pos - $state_pos ), $line_type );
					$line_type = 'HEADER_START';
					$state_pos = $pos + 1;
				} else if ( $tableContent[$pos] == "|" ) {
					$translated_content .= $this->translateTableLine( substr( $tableContent, $state_pos, $pos - $state_pos ), $line_type );
					$line_type = 'DATA_CELL_START';
					$state_pos = $pos + 1;
				}
			}
		}
//		dieq( $tableContent, $translated_content );
		return $translated_content;
	}

	private function translateTemplateContents( $templateContent ) {
		$pos = strpos( $templateContent, '|' );
		$templateName = substr( $templateContent, 0, $pos );
		$templateParametersContent = substr( $templateContent, $pos + 1, strlen( $templateContent ) - ( $pos + 1 ) );

		$translatedTemplateContent = $templateName . '|' . $this->translateWikiText( $templateParametersContent, true );
		return $translatedTemplateContent;
	}

	private function innerHTML(DOMNode $elm) {
		$innerHTML = '';
		$children  = $elm->childNodes;

		foreach($children as $child) {
			$innerHTML .= $elm->ownerDocument->saveHTML($child);
		}

		return $innerHTML;
	}

	private function postProcessFragment( $translated_content, $translate_titles = false ) {
		$dom = new DomDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?><p>' . $translated_content . '</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		$span_items = $dom->getElementsByTagName('span');
		while( $span_items->length ) {
			$span_item = $span_items->item(0);
 			$type = $span_item->getAttribute("class");
			$link = $span_item->getAttribute("data-link");
			$value = $this->postProcessFragment( $this->innerHTML( $span_item ), true );
			$new_element = null;
			if ( $type == "link" ) {
				if ( $translate_titles ) {
					$translated_link = $this->translateText( $link, true );
					if ( $translated_link != $value ) {
						$new_element = $dom->createElement( 'PLACEHOLDER', "[[$translated_link|$value]]" );
					} else {
						$new_element = $dom->createElement( 'PLACEHOLDER', "[[$translated_link]]" );
					}
				} else {
					if ( $link != $value ) {
						$new_element = $dom->createElement( 'PLACEHOLDER', "[[$link|$value]]" );
					} else {
						$new_element = $dom->createElement( 'PLACEHOLDER', "[[$link]]" );
					}
				}
			} else if ( $type == "external_link" ) {
				$new_element = $dom->createElement( 'PLACEHOLDER', "[$link $value]" );
			} else if ( $type == "bolditalic" ) {
				$new_element = $dom->createElement( 'PLACEHOLDER', "'''''$value'''''" );
			} else if ( $type == "bold" ) {
				$new_element = $dom->createElement( 'PLACEHOLDER', "'''$value'''" );
			} else if ( $type == "italic" ) {
				$new_element = $dom->createElement( 'PLACEHOLDER', "''value''" );
			}
			$span_item->parentNode->replaceChild( $new_element, $span_item );
		}
		$translated_content = $dom->saveHTML( $dom->documentElement );
		$translated_content = str_replace( "<PLACEHOLDER>", "", $translated_content );
		$translated_content = str_replace( "</PLACEHOLDER>", "", $translated_content );
		$translated_content = str_replace( "<p>", "", $translated_content );
		$translated_content = str_replace( "</p>", "", $translated_content );
		return $translated_content;
	}

	private function translateText( $text, $isTitle = false ) {
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

		# The target language
		$target = $this->translateTo;

		$translated_string = '';
		$cache = $this->translationCache->getCache( $rtrimmed );
		if ( $cache ) {
			$translated_string = $cache;
		} else {

			$translate = new TranslateClient([
				'keyFilePath' => $this->googleTranslateKeyFilePath
			]);

			# Translates some text into Russian
			$translation = $translate->translate($rtrimmed, [
				'target' => $target,
				'format' => 'html'
			]);

			$translated_string = $translation['text'];
			$translated_string = $this->postProcessFragment( $translated_string, true );
			if ( !$isTitle ) {
				$this->translationCache->setCache( $target, $rtrimmed, $translated_string );
			} else {
				// save titles with their own pageid
				$title = Title::newFromText( $rtrimmed );
				if ( $title->exists() ) {
					$page = new WikiPage( $title );
					$pageId = $page->getId();
					$this->translationCache->setCache( $target, $rtrimmed, $translated_string, $pageId );
				}
			}
		}
		$translated_string = $this->translationCorrections->applyCorrections( $translated_string );
		$this->translationFragments[ md5( $rtrimmed ) ] = array( $this->postProcessFragment( $rtrimmed ), $translated_string );
		return $ltrim . $translated_string . $rtrim;
	}

	// TODO: DISPLAYTITLE, <includeonly>, etc

	// $templateContent: true if $content provided is content inside a template and parameter names should not be translated

	function translateWikiText( $content, $templateContent = false ) {
		$translated_content = '';

		$len = strlen( $content );
		$pre_cur_str = array();
		$curr_str = '';
		$state_deep = 0;
		$state_arr = array( 'CONTENT' );

		for ( $i = 0; $i < $len; $i++ ){

			if ( $content[$i] == PHP_EOL && $state_arr[$state_deep] == 'CONTENT' ) {
				$translated_content .= $this->translateText( $curr_str ) . PHP_EOL;
				$curr_str = '';
				continue;
			}

			if ( ( $content[$i] == "*" || $content[$i] == "#" ) && $state_arr[$state_deep] == 'CONTENT' ) {
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';
				$translated_content .= $content[$i];
				continue;
			}

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
				array_push( $pre_cur_str, $curr_str );
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
				$curr_str = array_pop( $pre_cur_str ) . '<span class="bolditalic">'. $curr_str .'</span>';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 4;
				continue;
			}
			if ( $content[$i] == "'" && $content[$i+1] == "'" && $state_arr[$state_deep] == 'BOLDBEGIN' ) {
				$curr_str = array_pop( $pre_cur_str ) . '<span class="bold">'. $curr_str .'</span>';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 2;
				continue;
			}
			if ( $content[$i] == "'" && $content[$i+1] == "'" && $state_arr[$state_deep] == 'ITALICBEGIN' ) {
				$curr_str = array_pop( $pre_cur_str ) . '<span class="italic">'. $curr_str .'</span>';

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

			if ( $content[$i] == '[' && in_array( $state_arr[$state_deep], [ 'CONTENT', 'BOLDBEGIN', 'BOLDITALICBEGIN', 'ITALICBEGIN' ] ) ) {

				array_push( $pre_cur_str, $curr_str );
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
			if ( $content[$i] == ']' && $state_arr[$state_deep] == 'LINKBEGIN' ) {
				array_pop( $state_arr );
				$state_deep--;
				if ( ( $pos = strpos( $curr_str, " " ) ) !== FALSE ) { 
					$curr_str = array_pop( $pre_cur_str ) . '<span class="external_link" data-link="'. substr( $curr_str, 0, $pos ) .'">'. substr( $curr_str, $pos + 1 ) .'</span>';
				} else {
					$curr_str = array_pop( $pre_cur_str ) . '<span class="external_link" data-link="'. $curr_str .'"></span>';
				}
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

				$link_parts = explode( '|', $curr_str );

				if ( count( $link_parts ) == 2 ) {
					$curr_str = array_pop( $pre_cur_str ) . '<span class="link" data-link="'. trim( $link_parts[0] ) .'">'. $link_parts[1] .'</span>';
				} else {
					$curr_str = array_pop( $pre_cur_str ) . '<span class="link" data-link="'. trim( $link_parts[0] ) .'">'. trim( $link_parts[0] ) .'</span>';
				}
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

			if ( $content[$i] == '|' && $state_arr[$state_deep] == 'CURLYBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'TABLEBEGIN';
				continue;
			}

			if ( $content[$i] == '|' && $content[$i+1] == '}' && $state_arr[$state_deep] == 'TABLEBEGIN' ) {
				array_pop( $state_arr );
				$state_deep--;

				$translated_content .= "{|" . $this->translateTable( $curr_str ) . "|}";
				$i++;
				$curr_str = '';
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

			// Handle nested templates - this is required just to ignore them for now so they can be handled recursively.
			if ( $content[$i] == '{' && in_array( $state_arr[$state_deep], array( 'PARSERFUNCBEGIN', 'TEMPLATEBEGIN', 'NESTEDTEMPLATE' ) ) ) {
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

			if ( $templateContent && $state_arr[$state_deep] == 'CONTENT' && in_array( $content[$i], array( "|", "=" ) ) ) {
				if ( $content[$i] == '=' ) { //Its a parameter name of a template
					$translated_content .= $curr_str . '=';
					$curr_str = '';
				} else if ( $content[$i] == '|' ) {
					$translated_content .= $this->translateWikiText( $curr_str ) . '|';
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