<?php

/**
 * Smarty plugin
 * -------------------------------------------------------------
 * File:     modifier.sanitize.php
 * Type:     modifier
 * Name:     sanitize
 * Purpose:  Escapes parameter using MediaWiki parser.
 * -------------------------------------------------------------
 */
function smarty_modifier_sanitize( $string ) {
	if ( !is_string( $string ) ) {
		return '<div class="error">Expects parameter 1 to be string, ' . gettype( $string ) . ' given</div>';
	}

	return Sanitizer::removeSomeTags( $string );
}
