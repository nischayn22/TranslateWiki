# TranslateWiki
A MediaWiki extension that helps you translate a wiki from one language to another.


# Installation

	Download this repo on your extensions folder
	Add the following on your LocalSettings.php: wfLoadExtension( 'TranslateWiki' );
    Run the following command on your main directory: "composer require google/cloud-translate"
	Set environment variables for the Cloud Translate API. See https://cloud.google.com/translate/docs/reference/libraries#client-libraries-install-php

# Usage

	Run the maintenance script autoTranslateWiki.php to automatically translate the wiki pages to another language
	Correct or approve the auto translations from Special:ApproveTranslations
	To maintain a wiki in another language use extension Sync and enable translate feature.
