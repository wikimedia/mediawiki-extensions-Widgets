<?php

/**
 * Smarty plugin
 * -------------------------------------------------------------
 * File:     modifier.allowedprefix.php
 * Type:     modifier
 * Name:     allowedprefix
 * Purpose:  Validates the parameter format by checking whether
 *           it has a prefix from the given list of values.
 * -------------------------------------------------------------
 */
function smarty_modifier_allowedprefix( $string, $allowedValues = '' ) {
	if ( !is_string( $string ) ) {
		return '<div class="error">Expects parameter 1 to be string, ' . gettype( $string ) . ' given</div>';
	}

	if ( is_string( $allowedValues ) ) {
		$allowedValues = explode( ',', $allowedValues );
	}

	foreach ( $allowedValues as $value ) {
		if ( strncmp( $string, $value, strlen( $value ) ) === 0 ) {
			return $string;
		}
	}

	return '';
}
