<?php
namespace Sunrise;

defined( 'ABSPATH' ) || exit;

function transfer_is_serialized( $value ) {
	return function_exists( 'is_serialized' ) ? is_serialized( $value ) : (bool) preg_match( '/^(?:N;|[bidsrRSE]:.*;|[aOC]:.*\})$/sD', $value );
}

/** Pure bounded transformation; no destination writes and no PHP unserialize of transferred data. */
function transfer_rewrite( $input, $format, $urls ) {
	if ( ! is_string( $input ) || strlen( $input ) > 2097152 || ! in_array( $format, array( 'text', 'json', 'serialized' ), true ) || ! is_array( $urls ) || count( $urls ) > 8 ) { throw new \InvalidArgumentException( 'Unsupported transfer value or size.' ); }
	$map = array();
	foreach ( $urls as $source => $destination ) {
		foreach ( array( $source, $destination ) as $url ) {
			$parts = is_string( $url ) ? parse_url( $url ) : false;
			if ( ! $parts || strlen( $url ) > 2048 || ! preg_match( '#^https?://[A-Za-z0-9.-]+(?::[0-9]+)?(?:/[A-Za-z0-9_./~%+-]*)?$#D', $url ) || isset( $parts['user'], $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || false !== strpos( $url, '..' ) ) { throw new \InvalidArgumentException( 'Use explicit HTTP(S) base URLs without credentials or query strings.' ); }
		}
		$from = rtrim( $source, '/' ); $to = rtrim( $destination, '/' );
		if ( isset( $map[ $from ] ) && $map[ $from ] !== $to ) { throw new \InvalidArgumentException( 'Conflicting URL mappings.' ); }
		$map[ $from ] = $to;
		$map[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
	}
	uksort( $map, function ( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );
	$pattern = $map ? '~(?<![A-Za-z0-9_./:@%+-])(?:' . implode( '|', array_map( function ( $url ) { return preg_quote( $url, '~' ); }, array_keys( $map ) ) ) . ')(?=$|[/?#\s"\'<>()[\]{},;]|\\\\[/"\'])~' : null;
	$rewrite = function ( $text ) use ( $pattern, $map ) {
		if ( ! $pattern ) { return $text; }
		$result = preg_replace_callback( $pattern, function ( $match ) use ( $map ) { return $map[ $match[0] ]; }, $text );
		if ( null === $result || strlen( $result ) > 2097152 ) { throw new \InvalidArgumentException( 'Transfer value exceeds transformation limits.' ); }
		return $result;
	};
	return transfer_structured_rewrite( $input, $format, $rewrite );
}

/** Automatic filesystem-path replacement using the same serialization-safe parser as URL replacement. */
function transfer_path_rewrite( $input, $format, $paths ) {
	if ( ! is_string( $input ) || strlen( $input ) > 2097152 || ! in_array( $format, array( 'text', 'json', 'serialized' ), true ) || ! is_array( $paths ) || count( $paths ) > 4 ) { throw new \InvalidArgumentException( 'Unsupported transfer path or size.' ); }
	$map = array();
	foreach ( $paths as $source => $destination ) {
		foreach ( array( $source, $destination ) as $path ) { if ( ! is_string( $path ) || strlen( $path ) > 2048 || ! preg_match( '#^(?:[A-Za-z]:)?/[^\x00\r\n]*$#D', wp_normalize_path( $path ) ) || false !== strpos( '/' . trim( wp_normalize_path( $path ), '/' ) . '/', '/../' ) ) { throw new \InvalidArgumentException( 'Use explicit absolute filesystem paths.' ); } }
		$from = untrailingslashit( wp_normalize_path( $source ) ); $to = untrailingslashit( wp_normalize_path( $destination ) );
		if ( '' === $from || isset( $map[ $from ] ) && $map[ $from ] !== $to ) { throw new \InvalidArgumentException( 'Conflicting filesystem path mappings.' ); }
		$map[ $from ] = $to;
		$map[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
	}
	uksort( $map, function ( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );
	$rewrite = function ( $text ) use ( $map ) { $result = strtr( $text, $map ); if ( strlen( $result ) > 2097152 ) { throw new \InvalidArgumentException( 'Transfer value exceeds transformation limits.' ); } return $result; };
	return transfer_structured_rewrite( $input, $format, $rewrite );
}

function transfer_structured_rewrite( $input, $format, $rewrite ) {
	if ( 'text' === $format ) { return $rewrite( $input ); }
	if ( 'json' === $format ) {
		json_decode( $input, false, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() ) { throw new \InvalidArgumentException( 'Invalid or deeply nested JSON.' ); }
		// Rewrite string tokens only: number precision, whitespace and object/array types remain byte-for-byte intact.
		$result = preg_replace_callback( '/"(?:[^"\\\\]|\\\\.)*"(\s*:)?/s', function ( $match ) use ( $rewrite ) {
			$key = isset( $match[1] ) && '' !== $match[1];
			$token = $key ? substr( $match[0], 0, -strlen( $match[1] ) ) : $match[0];
			$before = json_decode( $token ); $after = $rewrite( $before );
			if ( $key && $before !== $after ) { throw new \InvalidArgumentException( 'URL-bearing keys require an explicit mapping handler.' ); }
			return $before === $after ? $match[0] : json_encode( $after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}, $input );
		if ( null === $result || strlen( $result ) > 2097152 ) { throw new \InvalidArgumentException( 'Transfer value exceeds transformation limits.' ); }
		return $result;
	}
	$budget = 10000;
	return transfer_serialized_rewrite( $input, $rewrite, 0, $budget );
}

/** Small wire parser: checked byte lengths, safe ordinary objects/references, and no autoloading. */
function transfer_serialized_rewrite( $input, $rewrite, $depth, &$budget ) {
	$offset = 0;
	$parse = function ( $level, $key = false ) use ( &$parse, $input, &$offset, $rewrite, &$budget ) {
		if ( $level > 32 || --$budget < 0 ) { throw new \InvalidArgumentException( 'Serialized value exceeds traversal limits.' ); }
		$tag = substr( $input, $offset, 1 );
		if ( $key && ! in_array( $tag, array( 'i', 's' ), true ) ) { throw new \InvalidArgumentException( 'Invalid serialized array key.' ); }
		if ( preg_match( '/\G(?:N;|b:[01];|i:-?(?:0|[1-9][0-9]*);|d:(?:-?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[Ee][+-]?[0-9]+)?|NAN|-?INF);|[rR]:(?:[1-9][0-9]*);)/', $input, $match, 0, $offset ) ) {
			if ( 'i' === $tag && false === filter_var( substr( $match[0], 2, -1 ), FILTER_VALIDATE_INT ) ) { throw new \InvalidArgumentException( 'Serialized integer exceeds this runtime range.' ); }
			$offset += strlen( $match[0] ); return $match[0];
		}
		if ( 's' === $tag && preg_match( '/\Gs:(0|[1-9][0-9]*):"/', $input, $match, 0, $offset ) ) {
			$length = (int) $match[1]; $offset += strlen( $match[0] );
			if ( $length > strlen( $input ) - $offset || substr( $input, $offset + $length, 2 ) !== '";' ) { throw new \InvalidArgumentException( 'Invalid serialized string length.' ); }
			$value = substr( $input, $offset, $length ); $offset += $length + 2;
				if ( ! $key && transfer_is_serialized( $value ) ) { $after = transfer_serialized_rewrite( $value, $rewrite, $level + 1, $budget ); }
			else { $after = $rewrite( $value ); }
			if ( $key && $after !== $value ) { throw new \InvalidArgumentException( 'URL-bearing keys require an explicit mapping handler.' ); }
			return 's:' . strlen( $after ) . ':"' . $after . '";';
		}
		if ( 'a' === $tag && preg_match( '/\Ga:(0|[1-9][0-9]*):\{/', $input, $match, 0, $offset ) ) {
			$count = (int) $match[1]; $offset += strlen( $match[0] );
			if ( $count > $budget / 2 ) { throw new \InvalidArgumentException( 'Serialized array exceeds traversal limits.' ); }
			$output = $match[0]; $keys = array();
			for ( $i = 0; $i < $count; ++$i ) {
				$wire = $parse( $level + 1, true );
				$name = 'i' === $wire[0] ? (int) substr( $wire, 2, -1 ) : substr( $wire, strpos( $wire, ':"' ) + 2, -2 );
				if ( array_key_exists( $name, $keys ) ) { throw new \InvalidArgumentException( 'Duplicate serialized array key.' ); } $keys[ $name ] = true;
				$output .= $wire . $parse( $level + 1 ); if ( strlen( $output ) > 2097152 ) { throw new \InvalidArgumentException( 'Transfer value exceeds transformation limits.' ); }
			}
			if ( substr( $input, $offset++, 1 ) !== '}' ) { throw new \InvalidArgumentException( 'Invalid serialized array length.' ); }
			return $output . '}';
		}
		if ( 'O' === $tag && preg_match( '/\GO:(0|[1-9][0-9]*):"/', $input, $match, 0, $offset ) ) {
			$length = (int) $match[1]; $start = $offset; $offset += strlen( $match[0] ); $class = substr( $input, $offset, $length ); $offset += $length;
			if ( strlen( $class ) !== $length || ! preg_match( '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\\\\\x80-\xff]*$/D', $class ) || ! preg_match( '/\G":(0|[1-9][0-9]*):\{/', $input, $count, 0, $offset ) ) { throw new \InvalidArgumentException( 'Invalid serialized object.' ); }
			$properties = (int) $count[1]; $offset += strlen( $count[0] ); if ( $properties > $budget / 2 ) { throw new \InvalidArgumentException( 'Serialized object exceeds traversal limits.' ); }
			$output = substr( $input, $start, $offset - $start ); $keys = array();
			for ( $i = 0; $i < $properties; ++$i ) { $wire = $parse( $level + 1, true ); if ( isset( $keys[ $wire ] ) ) { throw new \InvalidArgumentException( 'Duplicate serialized property.' ); } $keys[ $wire ] = true; $output .= $wire . $parse( $level + 1 ); }
			if ( substr( $input, $offset++, 1 ) !== '}' ) { throw new \InvalidArgumentException( 'Invalid serialized object length.' ); }
			return $output . '}';
		}
		throw new \InvalidArgumentException( 'Unsupported or malformed serialized value.' );
	};
	$result = $parse( $depth );
	if ( $offset !== strlen( $input ) || strlen( $result ) > 2097152 ) { throw new \InvalidArgumentException( 'Trailing data or oversized serialized value.' ); }
	return $result;
}
