<?php

use MediaWiki\MediaWikiServices;

/**
 * Class holding functions for displaying widgets.
 */
class SmartyResourceWiki extends Smarty_Resource_Custom {

	/** @var Parser */
	private Parser $parser;

	/**
	 * @param Parser $parser
	 */
	public function __construct( Parser $parser ) {
		$this->parser = $parser;
	}

	/**
	 * @param string $widgetName
	 *
	 * @return Title
	 */
	private function makeWidgetTitle( $widgetName ) {
		if ( class_exists( 'MediaWiki\Title\Title' ) ) {
			// MW 1.40+
			$titleClass = 'MediaWiki\Title\Title';
		} else {
			$titleClass = 'Title';
		}
		return $titleClass::makeTitleSafe( NS_WIDGET, $widgetName );
	}

	/**
	 * @param string $widgetName
	 * @param string &$widgetCode Set to null to mean not found
	 * @param int &$mtime Unix timestamp of last mod. Set to null for not found.
	 *
	 * @return void
	 */
	public function fetch( $widgetName, &$widgetCode, &$mtime ) {
		global $wgWidgetsUseFlaggedRevs;

		$widgetTitle = $this->makeWidgetTitle( $widgetName );

		if ( $widgetTitle && $widgetTitle->exists() ) {
			if ( $wgWidgetsUseFlaggedRevs ) {
				$flaggedWidgetArticle = FlaggableWikiPage::getTitleInstance( $widgetTitle );
				$flaggedWidgetArticleRevision = $flaggedWidgetArticle->getStableRev();

				if ( $flaggedWidgetArticleRevision ) {
					$widgetCode = $flaggedWidgetArticleRevision->getRevText();
					// Unclear if ->getTimestamp() or ->getRevTimestamp() makes more sense.
					$mtime = wfTimestamp( TS_UNIX, $flaggedWidgetArticleRevision->getTimestamp() );
				} else {
					$widgetCode = '';
				}
			} else {
				$widgetWikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()
					->newFromTitle( $widgetTitle );
				$widgetCode = $widgetWikiPage->getContent()->getText();
				$mtime = wfTimestamp( TS_UNIX, $widgetWikiPage->getTouched() );
			}

			// Remove <noinclude> sections and <includeonly> tags from form definition
			$widgetCode = StringUtils::delimiterReplace( '<noinclude>', '</noinclude>', '', $widgetCode );
			$widgetCode = strtr( $widgetCode, [ '<includeonly>' => '', '</includeonly>' => '' ] );
		} else {
			$widgetCode = null;
			$mtime = null;
		}
	}

	/**
	 * @param string $widgetName
	 *
	 * @return bool|int
	 */
	protected function fetchTimestamp( $widgetName ) {
		$widgetTitle = $this->makeWidgetTitle( $widgetName );

		if ( $widgetTitle && $widgetTitle->exists() ) {
			$widgetArticle = new Article( $widgetTitle, 0 );
			$widgetTimestamp = wfTimestamp( TS_UNIX, $widgetArticle->getPage()->getTouched() );
			return $widgetTimestamp;
		} else {
			return false;
		}
	}
}
