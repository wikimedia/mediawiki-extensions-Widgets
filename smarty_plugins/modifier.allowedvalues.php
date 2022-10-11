<?php

/**
 * Smarty plugin
 * -------------------------------------------------------------
 * File:     modifier.allowedvalues.php
 * Type:     modifier
 * Name:     allowedvalues
 * Purpose:  Validates the parameter format by checking whether
 *           it exists in the given list of allowed values.
 * -------------------------------------------------------------
 */
function smarty_modifier_allowedvalues( $string, $allowedValues = '' ) {
	if ( !is_string( $string ) ) {
		return '<div class="error">Expects parameter 1 to be string, ' . gettype( $string ) . ' given</div>';
	}

	if ( is_string( $allowedValues ) ) {
		$allowedValues = explode( ',', $allowedValues );
	}

	if ( in_array( $string, $allowedValues ) ) {
		return $string;
	}

	return '';
}
