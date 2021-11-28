<?php

$namespaceNames = [];

$namespaceAliases = [];

// For wikis without Widgets installed.
if ( !defined( 'NS_WIDGET' ) ) {
	define( 'NS_WIDGET', 274 );
	define( 'NS_WIDGET_TALK', 275 );
}

$namespaceNames['en'] = [
	NS_WIDGET       => 'Widget',
	NS_WIDGET_TALK  => 'Widget_talk',
];

$namespaceNames['de'] = [
	NS_WIDGET_TALK  => 'Widget_Diskussion',
];

$namespaceNames['ko'] = [
	NS_WIDGET       => '위젯',
	NS_WIDGET_TALK  => '위젯토론',
];

$namespaceNames['pl'] = [
	NS_WIDGET       => 'Widżet',
	NS_WIDGET_TALK  => 'Dyskusja_widżetu',
];

$namespaceNames['zh'] = [
	NS_WIDGET       => 'Widget',
	NS_WIDGET_TALK  => 'Widget_talk',
];

$namespaceNames['zh-hans'] = [
	NS_WIDGET       => '微件',
	NS_WIDGET_TALK  => '微件讨论',
];

$namespaceAliases['zh-hans'] = [
	'微件' => NS_WIDGET,
	'小部件' => NS_WIDGET,
	'小组件' => NS_WIDGET,
	'小元件' => NS_WIDGET,
	'微件讨论' => NS_WIDGET_TALK,
	'小部件讨论' => NS_WIDGET_TALK,
	'小组件讨论' => NS_WIDGET_TALK,
	'小元件讨论' => NS_WIDGET_TALK,
];

$namespaceNames['zh-hant'] = [
	NS_WIDGET       => '微件',
	NS_WIDGET_TALK  => '微件討論',
];

$namespaceAliases['zh-hant'] = [
	'微件' => NS_WIDGET,
	'小元件' => NS_WIDGET,
	'小部件' => NS_WIDGET,
	'小組件' => NS_WIDGET,
	'微件討論' => NS_WIDGET_TALK,
	'小元件討論' => NS_WIDGET_TALK,
	'小部件討論' => NS_WIDGET_TALK,
	'小組件討論' => NS_WIDGET_TALK,
];
