<?php
/**
 * Credential and settings storage.
 *
 * Every value can come from one of two places, and a constant in wp-config.php
 * always beats the value stored in the database. That lets a site keep its
 * secrets out of the database entirely, and lets staging override production
 * without touching the admin screens.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves, stores, and encrypts plugin credentials.
 */
class MBM_LF_Credentials {

	/**
	 * Option holding the encrypted secrets. Never autoloaded.
	 */
	const OPTION_SECRETS = 'mbm_lf_secrets';

	/**
	 * Option holding non-sensitive values. Never autoloaded.
	 */
	const OPTION_VALUES = 'mbm_lf_values';

	/**
	 * Every supported key mapped to its wp-config.php constant.
	 *
	 * @var array<string, string>
	 */
	private static $constants = [
		'api_key'          => 'MBM_LF_API_KEY',
		'public_key'       => 'MBM_LF_PUBLIC_KEY',
		'private_key'      => 'MBM_LF_PRIVATE_KEY',
		'private_key_path' => 'MBM_LF_PRIVATE_KEY_PATH',
		'workspace_id'     => 'MBM_LF_WORKSPACE_ID',
		'installation_id'  => 'MBM_LF_INSTALLATION_ID',
		'webhook_secret'   => 'MBM_LF_WEBHOOK_SECRET',
		'iss'              => 'MBM_LF_ISS',
		'aud'              => 'MBM_LF_AUD',
	];

	/**
	 * Keys encrypted at rest and never echoed back to the browser.
	 *
	 * @var string[]
	 */
	private static $secret_keys = [ 'api_key', 'private_key', 'webhook_secret' ];

	/**
	 * Resolve a value. A wp-config.php constant always wins.
	 *
	 * @param string $key     One of the supported keys.
	 * @param string $default Returned when nothing is configured.
	 * @return string
	 */
	public static function get( $key, $default = '' ) {
		if ( self::is_locked( $key ) ) {
			return (string) constant( self::$constants[ $key ] );
		}

		if ( self::is_secret( $key ) ) {
			$secrets = get_option( self::OPTION_SECRETS, [] );
			$stored  = isset( $secrets[ $key ] ) ? $secrets[ $key ] : '';

			return '' === $stored ? $default : self::decrypt( $stored );
		}

		$values = get_option( self::OPTION_VALUES, [] );

		return isset( $values[ $key ] ) && '' !== $values[ $key ] ? (string) $values[ $key ] : $default;
	}

	/**
	 * Store a value in the database.
	 *
	 * A locked key is skipped — writing would be misleading, since get() would
	 * keep returning the constant.
	 *
	 * @param string $key   One of the supported keys.
	 * @param string $value Value to store. Empty string clears it.
	 * @return bool True when the value was stored.
	 */
	public static function set( $key, $value ) {
		if ( ! isset( self::$constants[ $key ] ) || self::is_locked( $key ) ) {
			return false;
		}

		$option = self::is_secret( $key ) ? self::OPTION_SECRETS : self::OPTION_VALUES;
		$stored = get_option( $option, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		if ( '' === $value ) {
			unset( $stored[ $key ] );
		} else {
			$stored[ $key ] = self::is_secret( $key ) ? self::encrypt( $value ) : $value;
		}

		// Explicit false for autoload — these are only needed on our own screens
		// and on requests that actually talk to the API.
		return update_option( $option, $stored, false );
	}

	/**
	 * Whether a wp-config.php constant is controlling this key.
	 *
	 * @param string $key Key to check.
	 * @return bool
	 */
	public static function is_locked( $key ) {
		return isset( self::$constants[ $key ] )
			&& defined( self::$constants[ $key ] )
			&& '' !== (string) constant( self::$constants[ $key ] );
	}

	/**
	 * The wp-config.php constant name for a key.
	 *
	 * @param string $key Key to look up.
	 * @return string
	 */
	public static function constant_name( $key ) {
		return isset( self::$constants[ $key ] ) ? self::$constants[ $key ] : '';
	}

	/**
	 * Whether a key holds a secret.
	 *
	 * @param string $key Key to check.
	 * @return bool
	 */
	public static function is_secret( $key ) {
		return in_array( $key, self::$secret_keys, true );
	}

	/**
	 * Whether a key has any value at all.
	 *
	 * @param string $key Key to check.
	 * @return bool
	 */
	public static function has( $key ) {
		return '' !== self::get( $key );
	}

	/**
	 * Read the RSA signing key used for signing visitor tokens.
	 *
	 * A path is preferred over an inline value: keeping the key in a file
	 * outside the web root is the stronger option.
	 *
	 * @return string PEM contents, or an empty string when unavailable.
	 */
	public static function private_key() {
		$path = self::get( 'private_key_path' );

		if ( '' !== $path ) {
			if ( ! is_readable( $path ) ) {
				return '';
			}

			$pem = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			return is_string( $pem ) ? trim( $pem ) : '';
		}

		return trim( self::get( 'private_key' ) );
	}

	/**
	 * Remove everything this plugin stores.
	 */
	public static function delete_all() {
		delete_option( self::OPTION_SECRETS );
		delete_option( self::OPTION_VALUES );
	}

	/* ---------------------------------------------------------------------
	 * Encryption at rest
	 *
	 * This protects against a leaked database dump, not against someone who
	 * already has the filesystem — the key is derived from the site salts, so
	 * anyone holding wp-config.php can also derive it. wp-config.php constants
	 * remain the stronger choice, which is why the settings screen says so.
	 * ------------------------------------------------------------------ */

	/**
	 * Encrypt a value for storage.
	 *
	 * @param string $value Plain text.
	 * @return string Portable ciphertext string.
	 */
	private static function encrypt( $value ) {
		$key = self::derive_key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $value, $nonce, $key );

			return 'sodium:' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return '';
		}

		$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );

		return 'openssl:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored value.
	 *
	 * Returns an empty string when the value cannot be read — which is what
	 * happens if the site salts were rotated after the value was saved.
	 *
	 * @param string $stored Ciphertext produced by encrypt().
	 * @return string
	 */
	private static function decrypt( $stored ) {
		$key   = self::derive_key();
		$parts = explode( ':', $stored, 2 );

		if ( 2 !== count( $parts ) ) {
			return '';
		}

		list( $scheme, $payload ) = $parts;

		$raw = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw ) {
			return '';
		}

		if ( 'sodium' === $scheme ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' )
				|| strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}

			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

			return false === $plain ? '' : $plain;
		}

		if ( 'openssl' === $scheme ) {
			if ( strlen( $raw ) <= 48 ) {
				return '';
			}

			$iv     = substr( $raw, 0, 16 );
			$mac    = substr( $raw, 16, 32 );
			$cipher = substr( $raw, 48 );

			if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
				return '';
			}

			$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			return false === $plain ? '' : $plain;
		}

		return '';
	}

	/**
	 * Derive a 32-byte key from the site's own salts.
	 *
	 * @return string
	 */
	private static function derive_key() {
		$material = '';

		foreach ( [ 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'NONCE_SALT' ] as $constant ) {
			if ( defined( $constant ) ) {
				$material .= (string) constant( $constant );
			}
		}

		if ( '' === $material ) {
			// A site with default salts is already in trouble, but never derive
			// from an empty string.
			$material = ABSPATH . ( defined( 'DB_NAME' ) ? DB_NAME : '' );
		}

		return hash_hkdf( 'sha256', $material, 32, 'mbm-lf-credentials-v1' );
	}
}
