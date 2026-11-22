<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\Security\ICrypto;
use RuntimeException;

/**
 * Encrypts secrets stored in OpenCase's own tables (opencase_config and
 * opencase_importlocations.mailbox_password) with Nextcloud's ICrypto. The key
 * is derived from the instance secret in config.php.
 *
 * Values that are not in ICrypto's format are treated as legacy plaintext and
 * returned unchanged, so a value inserted directly in the database keeps
 * working until it is saved again (or the upgrade migration encrypts it).
 */
class SecretCrypto {
	/** opencase_config keys whose values are secrets. */
	public const CONFIG_KEYS = [
		'process_engine_password',
		'redaction_service_api_key',
		'sftp_ssh_key_pass_phrase',
		'datafordeler_apikey_cvr',
		'datafordeler_apikey_estate',
	];

	public function __construct(
		private ICrypto $crypto,
	) {
	}

	public static function isSecretConfigKey(string $key): bool {
		return in_array($key, self::CONFIG_KEYS, true);
	}

	/**
	 * ICrypto output: hex(ciphertext)|hex(iv)|hex(hmac)[|version]
	 */
	public static function looksEncrypted(?string $value): bool {
		return $value !== null && preg_match('/^[0-9a-f]+\|[0-9a-f]+\|[0-9a-f]+(\|\d+)?$/', $value) === 1;
	}

	public function encrypt(?string $value): ?string {
		if ($value === null || $value === '') {
			return $value;
		}
		return $this->crypto->encrypt($value);
	}

	public function decrypt(?string $value): ?string {
		if ($value === null || $value === '' || !self::looksEncrypted($value)) {
			return $value;
		}
		try {
			return $this->crypto->decrypt($value);
		} catch (\Exception $e) {
			throw new RuntimeException(
				'Failed to decrypt a stored OpenCase secret. If the instance secret in config.php has changed, enter the value again.',
				0,
				$e,
			);
		}
	}
}
