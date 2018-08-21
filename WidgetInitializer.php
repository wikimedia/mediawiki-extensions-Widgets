<?php
class WidgetInitializer {

	public static function init() {
		// Initialize Smarty
		require_once( __DIR__ . '/smarty/libs/Smarty.class.php' );

		// Unsetting required namespace permission rights if using FlaggedRevs
		global $wgNamespaceProtection, $wgWidgetsUseFlaggedRevs;
		if ( $wgWidgetsUseFlaggedRevs ) {
			$wgNamespaceProtection[NS_WIDGET] = [];
		}
	}

	/**
	* @param &$parser Parser
	* @return bool
	*/
	public static function initParserFunctions( &$parser ) {
		$parser->setFunctionHook( 'widget', 'WidgetRenderer::renderWidget' );

		return true;
	}

}
