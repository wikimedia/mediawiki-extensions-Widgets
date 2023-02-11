<?php
/**
 * Smarty plugin
 * -------------------------------------------------------------
 * File:     modifier.validate.php
 * Type:     modifier
 * Name:     validate
 * Purpose:  Validates parameter format ('url' by default).
 *           Useful when you need to validate but not escape.
 * -------------------------------------------------------------
 */
function smarty_modifier_validate( $string, $type='url' ) {

	// treat url validation differently. PHP validation doesn't allow
	// protocol relative urls, well at the same time not blocking
	// urls like javascript://%0aalert(1);//#"><script>alert(2)</script>

	if ( $type === 'url' ) {
		// Transform the url into an equivalent form without problematic
		// characters. In theory people should sanitize separately. In
		// practise they do not. Replacing these should not change the meaning
		// of the url.
		$string = str_replace( [ "'", '"', '<' ], [ '%27', '%22', '%3C' ], $string );
		if ( substr( $string, -1 ) === "\\" ) {
			// Last character \ can be problematic in JS contexts
			// How to convert depends on if before or after the ?
			$replacement = strpos( $string, '?' ) === false ? '/' : '%5C';
			$string = substr( $string, 0, -1 ) . $replacement;
		}

		// Note, this matches protocol relative, but not relative urls
		// and only allows whatever is in $wgUrlProtocols.
		if ( preg_match( '/^(?i:' . wfUrlProtocols() . ')\S+$/', $string ) ) {
			return $string;
		}
		return '';
	}

	// mapping for PHP filters (http://us2.php.net/manual/en/filter.constants.php)
	$filters = array(
		'url-php' => FILTER_VALIDATE_URL,
		'int' => FILTER_VALIDATE_INT,
		'boolean' => FILTER_VALIDATE_BOOLEAN,
		'float' => FILTER_VALIDATE_FLOAT,
		'email' => FILTER_VALIDATE_EMAIL,
		'ip' => FILTER_VALIDATE_IP
	);

	if ( array_key_exists($type, $filters) && filter_var($string, $filters[$type]) !== FALSE ) {
		return $string;
	}

	// unless it matched some validation rule, it's not valid
	return '';
}
