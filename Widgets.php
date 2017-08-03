<?php
/**
 * Widgest - Allows adding free-type widgets to the wiki by editing pages
 * in Widget namespace
 *
 * @link https://www.mediawiki.org/wiki/Extension:NumberFormat Documentation
 * @link https://www.mediawikiwidgets.org/ Collection of available widgets
 *
 * @author Sergey Chernyshev
 * @author Yaron Koren
 *
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License 2.0 or later
 */

// Ensure that the script cannot be executed outside of MediaWiki.
if ( !defined( 'MEDIAWIKI' ) ) {
    die( 'This is an extension to MediaWiki and cannot be run standalone.' );
}

// Display extension properties on MediaWiki.
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'Widgets',
	'descriptionmsg' => 'widgets-desc',
	'version' => '1.3.0',
	'author' => array(
		'[https://www.sergeychernyshev.com Sergey Chernyshev]',
		'Yaron Koren',
		'...'
	),
	'url' => 'https://www.mediawiki.org/wiki/Extension:Widgets',
	'license-name' => 'GPL-2.0+'
);

/**
 * Set this to the index of the Widget namespace
 */
if ( !defined( 'NS_WIDGET' ) ) {
	define( 'NS_WIDGET', 274 );
}
if ( !defined( 'NS_WIDGET_TALK' ) ) {
	define( 'NS_WIDGET_TALK', NS_WIDGET + 1 );
} elseif ( NS_WIDGET_TALK != NS_WIDGET + 1 ) {
	throw new MWException( 'Configuration error. Do not define NS_WIDGET_TALK, it is automatically set based on NS_WIDGET.' );
}

// Support subpages only for talk pages by default
$wgNamespacesWithSubpages[NS_WIDGET_TALK] = true;

// Define new right
$wgAvailableRights[] = 'editwidgets';

// Assign editing to widgeteditor and sysop groups only (widgets can be dangerous so we do it here, not in LocalSettings)
$wgGroupPermissions['*']['editwidgets'] = false;
$wgGroupPermissions['widgeteditor']['editwidgets'] = true;
$wgGroupPermissions['sysop']['editwidgets'] = true;

// Set this to true to use FlaggedRevs extension's stable version for widget security
$wgWidgetsUseFlaggedRevs = false;

// Set a default directory for storage of compiled templates
$wgWidgetsCompileDir = "$IP/extensions/Widgets/compiled_templates/";

// Initialize Smarty
require_once( __DIR__ . '/smarty/libs/Smarty.class.php' );

// Load extension's classes.
$wgAutoloadClasses['WidgetRenderer'] = __DIR__ . '/WidgetRenderer.php';

// Register extension messages and other localisation.
$wgMessagesDirs['Widgets'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['WidgetsMagic'] = __DIR__ . '/Widgets.i18n.magic.php';
$wgExtensionMessagesFiles['WidgetsNamespaces'] = __DIR__ . '/Widgets.i18n.namespaces.php';

// Parser function registration
$wgExtensionFunctions[] = 'widgetNamespacesInit';
$wgExtensionFunctions[] = 'WidgetRenderer::initRandomString';

// Register extension hooks.
$wgHooks['ParserFirstCallInit'][] = 'widgetParserFunctions';
$wgHooks['ParserAfterTidy'][] = 'WidgetRenderer::outputCompiledWidget';
$wgHooks['CanonicalNamespaces'][] = 'widgetsAddNamespaces';

/**
 * @param $parser Parser
 * @return bool
 */
function widgetParserFunctions( &$parser ) {
	$parser->setFunctionHook( 'widget', 'WidgetRenderer::renderWidget' );

	return true;
}

// Define new namespaces
function widgetsAddNamespaces( &$list ) {
	$list[NS_WIDGET] = 'Widget';
	$list[NS_WIDGET_TALK] = 'Widget_talk';
	return true;
}

function widgetNamespacesInit() {
	global $wgNamespaceProtection, $wgWidgetsUseFlaggedRevs;

	if ( !$wgWidgetsUseFlaggedRevs ) {
		// Setting required namespace permission rights
		$wgNamespaceProtection[NS_WIDGET] = array( 'editwidgets' );
	}
}
