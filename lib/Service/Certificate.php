<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service;

use Exception;
use OCA\OpenCase\Db\CertificateRepository;
use OCA\OpenCase\Enum\CertificateType;
use OpenSSLCertificate;

class Certificate {
	private string $publicKey;
	private string $privateKey;
	private string $subject;
	private \DateTimeImmutable $expiresAt;

	public function __construct(
		CertificateType $type,
		CertificateRepository $repository,
	) {
		$record = $repository->find($type->value);

		if ($record === null && $type !== CertificateType::Primary) {
			$record = $repository->find(CertificateType::Primary->value);
		}

		if ($record === null) {
			throw new Exception("Certificate not found: Primary certificate must be configured");
		}

		$this->loadCertificate($repository->readContent($record), $record['password']);
	}

	private function loadCertificate(string $certContent, ?string $password): void {
		$pkcs12 = [];
		if (!openssl_pkcs12_read($certContent, $pkcs12, $password ?? '')) {
			throw new Exception("Failed to parse PKCS#12 certificate: " . openssl_error_string());
		}

		$this->privateKey = $pkcs12['pkey'];
		$this->publicKey = $pkcs12['cert'];

		$certResource = openssl_x509_read($pkcs12['cert']);
		if ($certResource === false) {
			throw new Exception("Failed to read X.509 certificate: " . openssl_error_string());
		}

		$certInfo = openssl_x509_parse($certResource);
		if ($certInfo === false) {
			throw new Exception("Failed to parse X.509 certificate: " . openssl_error_string());
		}

		$this->subject = $certInfo['subject']['CN'] ?? $certInfo['name'] ?? '';
		$this->expiresAt = (new \DateTimeImmutable())->setTimestamp($certInfo['validTo_time_t']);
	}

	public function getPublicKey(): string {
		return $this->publicKey;
	}

	public function getPublicKeyBase64(): string {
		return $this->extractPemBody($this->publicKey);
	}

	public function getPrivateKey(): string {
		return $this->privateKey;
	}

	public function getPrivateKeyBase64(): string {
		return $this->extractPemBody($this->privateKey);
	}

	public function getSubject(): string {
		return $this->subject;
	}

	public function getExpiresAt(): \DateTimeImmutable {
		return $this->expiresAt;
	}

	public function isExpired(): bool {
		return $this->expiresAt < new \DateTimeImmutable();
	}

	private function extractPemBody(string $pem): string {
		$pem = preg_replace('/-----BEGIN [^-]+-----/', '', $pem);
		$pem = preg_replace('/-----END [^-]+-----/', '', $pem);
		return preg_replace('/\s+/', '', $pem);
	}

	/**
	 * Describe a registered certificate (exact type, no fallback to Primary) for
	 * display in the admin settings. Reads the file and password server-side; the
	 * password and storage location are never part of the result.
	 *
	 * Response (success): ['valid' => true, 'subject', 'serialNumber', 'expiresAt', 'legacyStorage']
	 * Response (failure): ['valid' => false, 'error' => 'not_configured' | 'cannot_read']
	 */
	public static function describeRegistered(CertificateRepository $repository, CertificateType $type): array {
		try {
			$record = $repository->find($type->value);
			if ($record === null) {
				return ['valid' => false, 'error' => 'not_configured'];
			}
			$content = $repository->readContent($record);
		} catch (\Throwable) {
			return ['valid' => false, 'error' => 'cannot_read'];
		}

		$pkcs12 = [];
		if (!openssl_pkcs12_read($content, $pkcs12, $record['password'] ?? '')) {
			return ['valid' => false, 'error' => 'cannot_read'];
		}

		$certResource = openssl_x509_read($pkcs12['cert']);
		if ($certResource === false) {
			return ['valid' => false, 'error' => 'cannot_read'];
		}

		$certInfo = openssl_x509_parse($certResource);
		if ($certInfo === false) {
			return ['valid' => false, 'error' => 'cannot_read'];
		}

		return [
			'valid' => true,
			'subject' => $certInfo['subject']['CN'] ?? $certInfo['name'] ?? '',
			'serialNumber' => $certInfo['serialNumberHex'] ?? (string)($certInfo['serialNumber'] ?? ''),
			'expiresAt' => $certInfo['validTo_time_t'],
			'legacyStorage' => ($record['storage'] ?? '') !== CertificateRepository::STORAGE_APPDATA,
		];
	}

	/**
	 * Parse a stored X.509 PEM certificate (as saved by
	 * opencase:register-api-client) into display-friendly fields for the
	 * admin settings API-client tables/popup.
	 *
	 * Response (success): ['valid' => true, 'subject', 'subjectCn', 'issuer',
	 *   'issuerCn', 'serialNumber', 'validFrom', 'validTo', 'signatureType']
	 * Response (failure): ['valid' => false]
	 */
	public static function parseX509Pem(string $pem): array {
		$certResource = openssl_x509_read($pem);
		if ($certResource === false) {
			return ['valid' => false];
		}

		$certInfo = openssl_x509_parse($certResource);
		if ($certInfo === false) {
			return ['valid' => false];
		}

		return [
			'valid'         => true,
			'subject'       => self::formatDn($certInfo['subject'] ?? []),
			'subjectCn'     => $certInfo['subject']['CN'] ?? '',
			'issuer'        => self::formatDn($certInfo['issuer'] ?? []),
			'issuerCn'      => $certInfo['issuer']['CN'] ?? '',
			'serialNumber'  => $certInfo['serialNumberHex'] ?? (string)($certInfo['serialNumber'] ?? ''),
			'validFrom'     => $certInfo['validFrom_time_t'] ?? null,
			'validTo'       => $certInfo['validTo_time_t'] ?? null,
			'signatureType' => $certInfo['signatureTypeSN'] ?? '',
		];
	}

	/** @param array<string, string|array<string>> $dn */
	private static function formatDn(array $dn): string {
		$parts = [];
		foreach ($dn as $key => $value) {
			$parts[] = $key . '=' . (is_array($value) ? implode('+', $value) : $value);
		}
		return implode(', ', $parts);
	}

	/**
	 * Extract just the CN from a DN string in either the OpenSSL "/CN=x/O=y"
	 * form or the "CN=x, O=y" form produced by formatDn().
	 */
	public static function extractCn(?string $dn): string {
		if ($dn === null || $dn === '') {
			return '';
		}
		if (preg_match('/CN=([^\/,]+)/', $dn, $matches)) {
			return trim($matches[1]);
		}
		return '';
	}

	public static function formatAsPem(string $base64String, string $type): string {
		if (strpos($base64String, "-----BEGIN") !== false) {
			return $base64String;
		}

		if (empty($base64String)) {
			throw new \InvalidArgumentException("Empty base64 string provided for PEM formatting");
		}

		$base64String = preg_replace('/\s+/', '', $base64String);

		if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $base64String)) {
			throw new \InvalidArgumentException("Invalid base64 string format");
		}

		$formatted = chunk_split($base64String, 64, "\n");
		$formatted = rtrim($formatted, "\n");

		return "-----BEGIN {$type}-----\n{$formatted}\n-----END {$type}-----\n";
	}
}
