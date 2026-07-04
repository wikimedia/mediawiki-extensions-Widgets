<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;

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
	 * @var string Placeholder inserted as the nonce attribute value on widget
	 *   <script>/<style> tags at parse time. It is replaced with the real,
	 *   per-request CSP nonce (or stripped) at output time by
	 *   ::onOutputPageBeforeHTML().
	 *
	 * The nonce cannot be inserted directly during parsing because widget
	 * output is stored in the parser cache, whereas the nonce changes on every
	 * request (see $wgCSPHeader). Using a stable placeholder in the cached HTML
	 * and resolving it post-cache keeps the nonce valid on cache hits.
	 */
	private const NONCE_PLACEHOLDER = "\x7fWIDGETS_CSP_NONCE\x7f";

	/**
	 * Map of CSP directive name to the ContentSecurityPolicy setter that adds a
	 * source to it. Used for both auto-detected and admin-configured sources.
	 *
	 * @var array<string,string>
	 */
	private const CSP_DIRECTIVE_METHODS = [
		'default-src' => 'addDefaultSrc',
		'script-src' => 'addScriptSrc',
		'style-src' => 'addStyleSrc',
		'connect-src' => 'addConnectSrc',
		'font-src' => 'addFontSrc',
		'media-src' => 'addMediaSrc',
		'frame-src' => 'addFrameSrc',
		'child-src' => 'addChildSrc',
		'worker-src' => 'addWorkerSrc',
		'manifest-src' => 'addManifestSrc',
	];

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
		// Avoid displaying warnings, which show up with more frequency with PHP 8.
		$smarty->error_reporting = E_ERROR;

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
			if ( class_exists( 'MediaWiki\Html\Html' ) ) {
				// MW 1.40+
				$htmlClass = 'MediaWiki\Html\Html';
			} else {
				$htmlClass = 'Html';
			}
			return $htmlClass::element( 'div', [ 'class' => 'error' ],
				wfMessage( 'widgets-error', $widgetName )->text() . ': ' . $e->getMessage() );
		}

		$services = MediaWikiServices::getInstance();
		$languageConverter = $services->getLanguageConverterFactory()
			->getLanguageConverter( $services->getContentLanguage() );
		$output = $languageConverter->convert( $output );

		// Tag any inline <script>/<style> the widget emits with a CSP nonce
		// placeholder so they are not blocked when $wgCSPHeader is enabled and
		// a nonce is in use. The placeholder is resolved to the real nonce at
		// output time; see ::onOutputPageBeforeHTML().
		$output = self::addNoncePlaceholder( $output );

		// Collect the external hosts this widget references so they can be
		// added to the page's Content-Security-Policy at output time (see
		// ::onOutputPageParserOutput()). Doing it here, and stashing the result
		// in the ParserOutput, keeps it consistent with the parser cache.
		global $wgWidgetsAutoRegisterCSPSources;
		$parserOutput = $parser->getOutput();
		if ( $wgWidgetsAutoRegisterCSPSources ) {
			$detected = self::collectExternalCSPSources( $output );
			if ( $detected ) {
				$stored = (array)$parserOutput->getExtensionData( 'widgetCSPSources' );
				foreach ( $detected as $directive => $srcs ) {
					$stored[$directive] = array_values( array_unique(
						array_merge( $stored[$directive] ?? [], $srcs )
					) );
				}
				$parserOutput->setExtensionData( 'widgetCSPSources', $stored );
			}
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

	/**
	 * Add a CSP nonce placeholder attribute to every inline <script> and
	 * <style> tag in the given widget HTML.
	 *
	 * Only opening tags are matched (the lookahead requires whitespace, '>' or
	 * '/' to immediately follow the tag name), so closing tags and tags such as
	 * <scripting> are left untouched. Tags that already carry a nonce attribute
	 * are skipped.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function addNoncePlaceholder( $html ) {
		return preg_replace(
			'/<(script|style)(?![^>]*\snonce=)(?=[\s>\/])/i',
			'<$1 nonce="' . self::NONCE_PLACEHOLDER . '"',
			$html
		);
	}

	/**
	 * Replace the widget CSP nonce placeholder with the real, per-request nonce.
	 *
	 * Runs at output time (after the parser cache), where the live nonce is
	 * available. If no nonce is in use (e.g. MediaWiki 1.41+, which relies on
	 * 'unsafe-inline' instead), the placeholder attribute is simply removed.
	 *
	 * @param OutputPage $out
	 * @param string &$text
	 */
	public static function onOutputPageBeforeHTML( $out, &$text ) {
		if ( strpos( $text, self::NONCE_PLACEHOLDER ) === false ) {
			return;
		}

		$nonce = $out->getCSP()->getNonce();
		if ( $nonce === false || $nonce === null || $nonce === '' ) {
			// No nonce in use; drop the placeholder attribute entirely.
			$text = str_replace( ' nonce="' . self::NONCE_PLACEHOLDER . '"', '', $text );
			return;
		}

		$text = str_replace( self::NONCE_PLACEHOLDER, htmlspecialchars( $nonce, ENT_QUOTES ), $text );
	}

	/**
	 * Scan widget HTML for external resources and map their hosts to the CSP
	 * directive that governs them.
	 *
	 * Only static references that appear in the markup can be detected here;
	 * resources a widget's own JavaScript loads at runtime (XHR/fetch endpoints,
	 * web fonts, workers, ...) cannot, and should be declared via
	 * $wgWidgetsCSPSources instead. Same-origin and relative references are left
	 * out, as they are already covered by the 'self' source.
	 *
	 * Note: <object>/<embed> are intentionally ignored, since core's object-src
	 * defaults to 'none' and exposes no runtime setter to relax it.
	 *
	 * @param string $html
	 * @return array<string,string[]> Directive => list of source hosts
	 */
	private static function collectExternalCSPSources( $html ) {
		$sources = [];
		if ( !preg_match_all(
			'/<(script|link|iframe|frame|audio|video|source|track)\b([^>]*)>/i',
			$html,
			$tags,
			PREG_SET_ORDER
		) ) {
			return $sources;
		}

		foreach ( $tags as $tag ) {
			$name = strtolower( $tag[1] );
			$attrs = self::parseAttributes( $tag[2] );

			switch ( $name ) {
				case 'script':
					self::addExternalSource( $sources, 'script-src', $attrs['src'] ?? null );
					break;
				case 'link':
					$rel = strtolower( $attrs['rel'] ?? '' );
					if ( strpos( $rel, 'stylesheet' ) !== false ) {
						self::addExternalSource( $sources, 'style-src', $attrs['href'] ?? null );
					} elseif ( strpos( $rel, 'manifest' ) !== false ) {
						self::addExternalSource( $sources, 'manifest-src', $attrs['href'] ?? null );
					}
					break;
				case 'iframe':
				case 'frame':
					self::addExternalSource( $sources, 'frame-src', $attrs['src'] ?? null );
					break;
				case 'audio':
				case 'video':
				case 'source':
				case 'track':
					self::addExternalSource( $sources, 'media-src', $attrs['src'] ?? null );
					if ( $name === 'video' ) {
						self::addExternalSource( $sources, 'media-src', $attrs['poster'] ?? null );
					}
					break;
			}
		}

		return $sources;
	}

	/**
	 * Parse an HTML start-tag attribute string into a lower-cased name => value
	 * map. Values are HTML-entity decoded. Good enough for extracting resource
	 * URLs from the trusted, admin-authored markup widgets produce.
	 *
	 * @param string $attrString The text between the tag name and the closing '>'
	 * @return array<string,string>
	 */
	private static function parseAttributes( $attrString ) {
		$attrs = [];
		if ( !preg_match_all(
			'/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/',
			$attrString,
			$matches,
			PREG_SET_ORDER
		) ) {
			return $attrs;
		}
		foreach ( $matches as $match ) {
			$value = $match[3];
			if ( $value === '' ) {
				$value = $match[4] !== '' ? $match[4] : ( $match[5] ?? '' );
			}
			$attrs[strtolower( $match[1] )] = html_entity_decode( $value, ENT_QUOTES );
		}
		return $attrs;
	}

	/**
	 * Add the host of an external resource URL to the given directive bucket.
	 * Relative, fragment and same-scheme-less references without a host are
	 * skipped; only absolute (or protocol-relative) URLs contribute a source.
	 *
	 * @param array<string,string[]> &$sources
	 * @param string $directive
	 * @param string|null $url
	 */
	private static function addExternalSource( array &$sources, $directive, $url ) {
		if ( !is_string( $url ) || trim( $url ) === '' ) {
			return;
		}
		$url = trim( $url );
		// Only external references need allowlisting; anything else is 'self'.
		if ( !preg_match( '#^(https?:)?//#i', $url ) ) {
			return;
		}
		$bits = parse_url( $url );
		if ( !$bits || empty( $bits['host'] ) ) {
			return;
		}
		$source = isset( $bits['scheme'] ) && $bits['scheme'] !== ''
			? $bits['scheme'] . '://' . $bits['host']
			: '//' . $bits['host'];
		if ( isset( $bits['port'] ) ) {
			$source .= ':' . $bits['port'];
		}
		if ( !in_array( $source, $sources[$directive] ?? [], true ) ) {
			$sources[$directive][] = $source;
		}
	}

	/**
	 * Register the CSP sources a page's widgets need with the page's
	 * Content-Security-Policy.
	 *
	 * Runs at output time, before the CSP headers are sent, so add*Src() calls
	 * still take effect. Two source sets are applied whenever the page contains
	 * at least one widget:
	 *  - the hosts auto-detected from widget markup (if
	 *    $wgWidgetsAutoRegisterCSPSources is enabled), and
	 *  - the admin-configured $wgWidgetsCSPSources allowlist.
	 *
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 */
	public static function onOutputPageParserOutput( $out, $parserOutput ) {
		// Nothing to do unless a widget was actually rendered on this page.
		if ( $parserOutput->getExtensionData( 'widgetReplacements' ) === null ) {
			return;
		}

		$csp = $out->getCSP();

		$detected = (array)$parserOutput->getExtensionData( 'widgetCSPSources' );

		global $wgWidgetsCSPSources;
		$configured = is_array( $wgWidgetsCSPSources ) ? $wgWidgetsCSPSources : [];

		foreach ( [ $detected, $configured ] as $sourceSet ) {
			foreach ( $sourceSet as $directive => $srcs ) {
				$method = self::CSP_DIRECTIVE_METHODS[$directive] ?? null;
				if ( $method === null ) {
					continue;
				}
				foreach ( (array)$srcs as $src ) {
					if ( is_string( $src ) && $src !== '' ) {
						$csp->$method( $src );
					}
				}
			}
		}
	}
}
