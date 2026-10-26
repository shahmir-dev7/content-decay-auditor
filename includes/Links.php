<?php
/**
 * Internal link checking.
 *
 * @package DecayAuditor
 */

namespace DecayAuditor;

defined( 'ABSPATH' ) || exit;

/**
 * Links.
 */
class Links {

	/**
	 * All href values in a chunk of HTML (pure).
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public static function extract( $html ) {
		if ( ! preg_match_all( '/<a\s[^>]*?href\s*=\s*(["\'])(.*?)\1/is', (string) $html, $m ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'html_entity_decode', $m[2] ) ) );
	}

	/**
	 * Count broken internal links in post content. External links are never fetched.
	 *
	 * @param string $html Post content.
	 * @return int
	 */
	public static function count_broken( $html ) {
		$broken = 0;
		foreach ( self::extract( $html ) as $url ) {
			if ( self::is_broken( $url ) ) {
				++$broken;
			}
		}
		return $broken;
	}

	/**
	 * Does an internal URL point at nothing?
	 *
	 * A link counts as broken only when it maps to no post, uploaded file, term slug or author slug.
	 * It is a heuristic and makes no HTTP request; upgrade path is loopback HEAD requests.
	 *
	 * @param string $url Link target.
	 * @return bool
	 */
	public static function is_broken( $url ) {
		$home  = wp_parse_url( home_url() );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( ! empty( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false; // Non-web schemes such as email and phone links.
		}
		if ( ! empty( $parts['host'] ) && 0 !== strcasecmp( $parts['host'], isset( $home['host'] ) ? $home['host'] : '' ) ) {
			return false; // External.
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		$base = isset( $home['path'] ) ? rtrim( $home['path'], '/' ) : '';
		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$path = '/' . trim( $path, '/' );
		if ( '/' === $path ) {
			return false;
		}
		// Feeds, REST, admin, login and date archives always resolve.
		if ( preg_match( '#^/(feed|wp-json|wp-admin|wp-login\.php|comments|\d{4})(/|$)#', $path ) ) {
			return false;
		}
		if ( url_to_postid( home_url( $path ) ) ) {
			return false;
		}
		$content_pos = strpos( $path, '/wp-content/' );
		if ( false !== $content_pos ) {
			// realpath() resolves any ../ so a link cannot probe files outside wp-content.
			$real = realpath( WP_CONTENT_DIR . substr( $path, $content_pos + strlen( '/wp-content' ) ) );
			$root = realpath( WP_CONTENT_DIR );
			return false === $real || false === $root || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR );
		}
		$slug = basename( $path );
		if ( term_exists( $slug ) || get_user_by( 'slug', $slug ) ) {
			return false;
		}
		return true;
	}
}
