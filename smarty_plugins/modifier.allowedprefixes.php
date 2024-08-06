<?php

/**
 * Smarty plugin
 * -------------------------------------------------------------
 * File:     modifier.allowedprefixes.php
 * Type:     modifier
 * Name:     allowedprefixes
 * Purpose:  Validates the parameter format by checking whether
 *           it has a prefix from the given list of values.
 * -------------------------------------------------------------
 */
function smarty_modifier_allowedprefixes( $string, $allowedPrefixes = '' ) {
	if ( !is_string( $string ) ) {
		return '<div class="error">Expects parameter 1 to be string, ' . gettype( $string ) . ' given</div>';
	}

	if ( is_string( $allowedPrefixes ) ) {
		$allowedPrefixes = explode( ',', $allowedPrefixes );
	}

	foreach ( $allowedPrefixes as $prefix ) {
		if ( $prefix == '' ) {
			continue;
                }
		if ( strncmp( $string, $prefix, strlen( $prefix ) ) === 0 ) {
			return $string;
		}
	}

	return '';
}
