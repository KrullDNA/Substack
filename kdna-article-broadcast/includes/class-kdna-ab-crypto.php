<?php
/**
 * Credential encryption helper.
 *
 * The Campaign Monitor API key, and later the reCAPTCHA secret, are stored
 * encrypted at rest rather than as plain text in the options table. The
 * encryption key is derived from the site authentication salt, so the stored
 * value is useless if the database is copied without the wp-config salts.
 *
 * @package KDNA_Article_Broadcast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KDNA_AB_Crypto
 */
class KDNA_AB_Crypto {

	/**
	 * Cipher method.
	 */
	const METHOD = 'aes-256-cbc';

	/**
	 * Marker prefix so we can tell an encrypted value from a legacy plain one.
	 */
	const PREFIX = 'kdnaenc:';

	/**
	 * Derives the binary encryption key from the site authentication salt.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	/**
	 * Reports whether strong encryption is available on this host.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'random_bytes' );
	}

	/**
	 * Encrypts a plain string for storage.
	 *
	 * If OpenSSL is unavailable the value is returned unchanged rather than
	 * failing, so the plugin still works on a minimal host. The PREFIX marker
	 * is only added to genuinely encrypted values.
	 *
	 * @param string $plain Plain text value.
	 * @return string Storable value.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain ) {
			return '';
		}

		if ( ! self::is_available() ) {
			return $plain;
		}

		try {
			$iv = random_bytes( 16 );
		} catch ( Exception $e ) {
			return $plain;
		}

		$cipher = openssl_encrypt( $plain, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return $plain;
		}

		return self::PREFIX . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypts a stored value.
	 *
	 * A value without the PREFIX marker is assumed to be plain text and is
	 * returned unchanged, which keeps older or manually entered values working.
	 *
	 * @param string $stored Stored value.
	 * @return string Plain text value.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;

		if ( '' === $stored ) {
			return '';
		}

		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			// Not encrypted, return as is.
			return $stored;
		}

		if ( ! self::is_available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );

		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );

		$plain = openssl_decrypt( $cipher, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv );

		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Masks a secret for display, showing only the last few characters.
	 *
	 * @param string $secret Plain secret.
	 * @param int    $visible Number of trailing characters to show.
	 * @return string
	 */
	public static function mask( $secret, $visible = 4 ) {
		$secret = (string) $secret;
		$length = strlen( $secret );

		if ( 0 === $length ) {
			return '';
		}

		if ( $length <= $visible ) {
			return str_repeat( "\xe2\x80\xa2", $length );
		}

		return str_repeat( "\xe2\x80\xa2", 8 ) . substr( $secret, -$visible );
	}
}
