<?php

use MediaWiki\MediaWikiServices;

/**
 * Class holding functions for displaying widgets.
 */
class WidgetRenderer {

	/**
	 * @var string The prefix for the widget strip marker.
	 *
	 * \xEF\xBF\xBC = U+FFFC (Object replacement) which is unlikely to be in text.
	 */
	private static $markerPrefix = "\xEF\xBF\xBCSTART_WIDGET";

	/**
	 * @var string The suffix for the widget strip marker.
	 */
	private static $markerSuffix = "END_WIDGET";

	/**
	 * @param Parser &$parser
	 * @param string $widgetName
	 *
	 * @return string
	 */
	public static function renderWidget( &$parser, $widgetName ) {
		global $wgWidgetsCompileDir;

		$smarty = new Smarty;
		$smarty->left_delimiter = '<!--{';
		$smarty->right_delimiter = '}-->';
		$smarty->compile_dir = $wgWidgetsCompileDir;

		// registering custom Smarty plugins
		$smarty->addPluginsDir( __DIR__ . "/smarty_plugins/" );

		$smarty->enableSecurity( 'WidgetSecurity' );

		// Register the Widgets extension functions.
		$wikiResource = new SmartyResourceWiki( $parser );
		$smarty->registerResource(
			'wiki',
			$wikiResource
		);

		$params = func_get_args();
		// The first and second params are the parser and the widget
		// name - we already have both.
		array_shift( $params );
		array_shift( $params );

		$params_tree = [];

		foreach ( $params as $param ) {
			$pair = explode( '=', $param, 2 );

			if ( count( $pair ) == 2 ) {
				$key = trim( $pair[0] );
				$val = trim( $pair[1] );
			} else {
				$key = $param;
				$val = true;
			}

			if ( $val == 'false' ) {
				$val = false;
			}

			/* If the name of the parameter has object notation

				a.b.c.d

			   then we assign stuff to hash of hashes, not scalar

			*/
			$keys = explode( '.', $key );

			// $subtree will be moved from top to the bottom and
			// at the end will point to the last level.
			$subtree =& $params_tree;

			// Go through all the keys but the last one.
			$last_key = array_pop( $keys );

			foreach ( $keys as $subkey ) {
				// If next level of subtree doesn't exist yet,
				// create an empty one.
				if ( !array_key_exists( $subkey, $subtree ) ) {
					$subtree[$subkey] = [];
				}

				// move to the lower level
				$subtree =& $subtree[$subkey];
			}

			// last portion of the key points to itself
			if ( isset( $subtree[$last_key] ) ) {
				// If this is already an array, push into it;
				// otherwise, convert into an array first.
				if ( !is_array( $subtree[$last_key] ) ) {
					$subtree[$last_key] = [ $subtree[$last_key] ];
				}
				$subtree[$last_key][] = $val;
			} else {
				// doesn't exist yet, just setting a value
				$subtree[$last_key] = $val;
			}
		}

		$smarty->assign( $params_tree );

		try {
			$output = $smarty->fetch( "wiki:$widgetName" );
		} catch ( Exception $e ) {
			wfDebugLog( "Widgets", "Smarty exception while parsing '$widgetName': " . $e->getMessage() );
			return Html::element( 'div', [ 'class' => 'error' ],
				wfMessage( 'widgets-error', $widgetName )->text() . ': ' . $e->getMessage() );
		}

		$services = MediaWikiServices::getInstance();
		if ( method_exists( $services, 'getLanguageConverterFactory' ) ) {
			// MW 1.35+
			$languageConverter = $services
				->getLanguageConverterFactory()
				->getLanguageConverter( $services->getContentLanguage() );
			$output = $languageConverter->convert( $output );
		} else {
			$parser = $services->getParser();
			$output = $parser->getTargetLanguage()->convert( $output );
		}

		// To prevent the widget output from being tampered with, the
		// compiled HTML is stored and a strip marker with an index to
		// retrieve it later is returned.

		// More reliable replacement. See T149488.
		$dash = strpos( $output, '"' ) !== false ? "\"'-" : '-';
		$marker = $dash . wfRandomString( 16 );

		$widgets = (array)$parser->getOutput()->getExtensionData( 'widgetReplacements' );
		$widgets[$marker] = $output;
		$parser->getOutput()->setExtensionData( 'widgetReplacements', $widgets );
		return self::$markerPrefix . $marker . self::$markerSuffix;
	}

	/**
	 * @param Parser $parser
	 * @param string &$text
	 */
	public static function outputCompiledWidget( $parser, &$text ) {
		$replacements = $parser->getOutput()->getExtensionData( 'widgetReplacements' );
		if ( !is_array( $replacements ) ) {
			return;
		}
		$text = preg_replace_callback(
			'/' . self::$markerPrefix . "(\"?'?-[a-z0-9]{16})" . self::$markerSuffix . '/S',
			static function ( $matches ) use ( $replacements ) {
				return $replacements[$matches[1]];
			},
			$text
		);
	}
}
