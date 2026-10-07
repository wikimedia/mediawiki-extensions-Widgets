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
	 * Map of CSP directive name, as used in $wgWidgetsCSPSources, to the
	 * ContentSecurityPolicy setter that adds a source to it.
	 *
	 * Core only lets extensions extend script-src, style-src and default-src.
	 * The other fetch directives listed here are not sent by core, so browsers
	 * fall back to default-src for them, and the source is added there instead.
	 * Note that this also allows the source for every other directive that
	 * falls back to default-src (and for style-src, which core builds from it).
	 *
	 * @var array<string,string>
	 */
	private const CSP_DIRECTIVE_METHODS = [
		'default-src' => 'addDefaultSrc',
		'script-src' => 'addScriptSrc',
		'style-src' => 'addStyleSrc',
		'connect-src' => 'addDefaultSrc',
		'font-src' => 'addDefaultSrc',
		'img-src' => 'addDefaultSrc',
		'media-src' => 'addDefaultSrc',
		'frame-src' => 'addDefaultSrc',
		'child-src' => 'addDefaultSrc',
		'worker-src' => 'addDefaultSrc',
		'manifest-src' => 'addDefaultSrc',
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

		global $wgWidgetsAutoRegisterCSPSources;
		if ( $wgWidgetsAutoRegisterCSPSources ) {
			self::registerWidgetCSPSources( $parser->getOutput(), $wikiResource, $widgetName );
		}
		self::addInlineScriptHashes( $parser->getOutput(), $output );

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
	 * Allow the external hosts referenced by a widget's source code in the
	 * Content-Security-Policy of the page the widget is rendered on.
	 *
	 * The widget source (as written by users with the editwidgets right) is
	 * scanned rather than the rendered output, so that parameter values, which
	 * any editor of the page can supply, cannot add sources to the policy.
	 * Smarty tags are blanked before scanning; a URL whose host comes from a
	 * template variable is therefore not detected.
	 *
	 * The sources are stored in the ParserOutput, which is what OutputPage
	 * applies to the policy, so they stay correct on parser cache hits.
	 *
	 * @param ParserOutput $parserOutput
	 * @param SmartyResourceWiki $wikiResource
	 * @param string $widgetName
	 */
	private static function registerWidgetCSPSources( $parserOutput, $wikiResource, $widgetName ) {
		// Only scan each widget once per parse, however often it is used.
		$scanned = (array)$parserOutput->getExtensionData( 'widgetCSPScanned' );
		if ( isset( $scanned[$widgetName] ) ) {
			return;
		}
		$scanned[$widgetName] = true;
		$parserOutput->setExtensionData( 'widgetCSPScanned', $scanned );

		$wikiResource->fetch( $widgetName, $widgetCode, $mtime );
		if ( !is_string( $widgetCode ) || $widgetCode === '' ) {
			return;
		}
		$widgetCode = preg_replace( '/<!--\{.*?\}-->/s', '{}', $widgetCode );

		$sources = self::collectExternalCSPSources( $widgetCode );
		foreach ( $sources['script-src'] ?? [] as $src ) {
			$parserOutput->addExtraCSPScriptSrc( $src );
		}
		foreach ( $sources['style-src'] ?? [] as $src ) {
			$parserOutput->addExtraCSPStyleSrc( $src );
		}
		foreach ( $sources['default-src'] ?? [] as $src ) {
			$parserOutput->addExtraCSPDefaultSrc( $src );
		}
	}

	/**
	 * Remember the CSP hash sources of the inline scripts in the widget output.
	 *
	 * They are only added to the policy when it uses nonces (MediaWiki 1.39 and
	 * 1.40, see ::onContentSecurityPolicyScriptSource()); there, 'unsafe-inline'
	 * is ignored, so inline widget scripts would be blocked otherwise. Only
	 * <script> elements are covered: inline event handler attributes and
	 * javascript: URLs cannot be allowed by hash.
	 *
	 * @param ParserOutput $parserOutput
	 * @param string $html Final widget output, exactly as it will be served
	 */
	private static function addInlineScriptHashes( $parserOutput, $html ) {
		if ( !preg_match_all( '/<script\b([^>]*)>(.*?)<\/script\s*>/is', $html, $scripts, PREG_SET_ORDER ) ) {
			return;
		}
		$hashes = (array)$parserOutput->getExtensionData( 'widgetCSPScriptHashes' );
		foreach ( $scripts as $script ) {
			if ( isset( self::parseAttributes( $script[1] )['src'] ) || trim( $script[2] ) === '' ) {
				continue;
			}
			// Browsers normalize newlines before hashing the script text.
			$code = str_replace( [ "\r\n", "\r" ], "\n", $script[2] );
			$hash = "'sha256-" . base64_encode( hash( 'sha256', $code, true ) ) . "'";
			$hashes[$hash] = true;
		}
		$parserOutput->setExtensionData( 'widgetCSPScriptHashes', $hashes );
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
	 * Frames and media go to default-src, since core does not send frame-src or
	 * media-src and browsers fall back to default-src for them.
	 *
	 * Note: <object>/<embed> are intentionally ignored, since core's object-src
	 * defaults to 'none' and exposes no runtime setter to relax it.
	 *
	 * @param string $html
	 * @return array<string,string[]> Directive (script-src, style-src or
	 *   default-src) => list of source hosts
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
						self::addExternalSource( $sources, 'default-src', $attrs['href'] ?? null );
					}
					break;
				case 'iframe':
				case 'frame':
				case 'audio':
				case 'video':
				case 'source':
				case 'track':
					self::addExternalSource( $sources, 'default-src', $attrs['src'] ?? null );
					if ( $name === 'video' ) {
						self::addExternalSource( $sources, 'default-src', $attrs['poster'] ?? null );
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
	 * URLs pointing at this wiki's own server are skipped as well, since they
	 * are covered by 'self'.
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
		$host = strtolower( $bits['host'] );
		// Reject anything that is not a plain host name, e.g. a host built
		// from a (blanked) template variable.
		if ( !preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) ) {
			return;
		}
		$serverName = MediaWikiServices::getInstance()->getMainConfig()->get( 'ServerName' );
		if ( $host === strtolower( (string)$serverName ) ) {
			return;
		}
		$source = isset( $bits['scheme'] ) && $bits['scheme'] !== ''
			? strtolower( $bits['scheme'] ) . '://' . $host
			: '//' . $host;
		if ( isset( $bits['port'] ) ) {
			$source .= ':' . $bits['port'];
		}
		if ( !in_array( $source, $sources[$directive] ?? [], true ) ) {
			$sources[$directive][] = $source;
		}
	}

	/**
	 * Apply the CSP settings of the widgets on a page.
	 *
	 * Adds the admin-configured $wgWidgetsCSPSources to the policy of pages
	 * that contain at least one widget. This runs at output time rather than
	 * parse time so that configuration changes take effect without purging the
	 * parser cache. Sources detected from widget code are stored in the
	 * ParserOutput instead; see ::registerWidgetCSPSources().
	 *
	 * Also collects the hashes of inline widget scripts, which are added to
	 * script-src by ::onContentSecurityPolicyScriptSource().
	 *
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 */
	public static function onOutputPageParserOutput( $out, $parserOutput ) {
		global $wgWidgetsCSPSources;

		// Nothing to do unless a widget was actually rendered on this page.
		if ( $parserOutput->getExtensionData( 'widgetReplacements' ) === null ) {
			return;
		}

		$hashes = $parserOutput->getExtensionData( 'widgetCSPScriptHashes' );
		if ( $hashes ) {
			$out->setProperty(
				'widgetCSPScriptHashes',
				array_merge( (array)$out->getProperty( 'widgetCSPScriptHashes' ), $hashes )
			);
		}

		$csp = $out->getCSP();
		foreach ( (array)$wgWidgetsCSPSources as $directive => $srcs ) {
			$method = self::CSP_DIRECTIVE_METHODS[$directive] ?? null;
			if ( $method === null ) {
				wfDebugLog( 'Widgets', "Ignoring unsupported CSP directive '$directive' in \$wgWidgetsCSPSources" );
				continue;
			}
			foreach ( (array)$srcs as $src ) {
				if ( is_string( $src ) && $src !== '' ) {
					$csp->$method( $src );
				}
			}
		}
	}

	/**
	 * Allow the inline scripts of the widgets on the page by their hash, when
	 * the policy uses a nonce.
	 *
	 * MediaWiki 1.39 and 1.40 send a nonce by default when $wgCSPHeader is
	 * enabled, and browsers then ignore 'unsafe-inline'. Widget output is
	 * parser-cached, so it cannot carry the per-request nonce; hashes do not
	 * change between requests. Without a nonce (MediaWiki 1.41+, or
	 * 'useNonces' => false), nothing is added: 'unsafe-inline' already allows
	 * inline scripts, and adding a hash would make browsers ignore it, blocking
	 * core's own inline scripts.
	 *
	 * ContentSecurityPolicy::addScriptSrc() cannot be used, as it only accepts
	 * URLs.
	 *
	 * @param string[] &$scriptSrc
	 * @param array $policyConfig
	 * @param int $mode
	 */
	public static function onContentSecurityPolicyScriptSource( &$scriptSrc, $policyConfig, $mode ) {
		if ( !preg_grep( "/^'nonce-[A-Za-z0-9+\/_=-]+'$/", $scriptSrc ) ) {
			return;
		}
		$contextClass = class_exists( 'MediaWiki\Context\RequestContext' )
			// MW 1.41+
			? 'MediaWiki\Context\RequestContext'
			: 'RequestContext';
		$hashes = $contextClass::getMain()->getOutput()->getProperty( 'widgetCSPScriptHashes' );
		if ( $hashes ) {
			$scriptSrc = array_merge( $scriptSrc, array_keys( $hashes ) );
		}
	}
}
