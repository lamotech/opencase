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

		$this->loadCertificate($record['filepath'], $record['password']);
	}

	private function loadCertificate(string $filepath, ?string $password): void {
		if (!file_exists($filepath)) {
			throw new Exception("Certificate file not found: {$filepath}");
		}

		$certContent = file_get_contents($filepath);
		if ($certContent === false) {
			throw new Exception("Failed to read certificate file: {$filepath}");
		}

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
	 * Parse a PKCS#12 certificate file and return its subject, serial number and
	 * expiry, without requiring a registered CertificateType/repository record.
	 *
	 * Response (success): ['valid' => true, 'subject', 'serialNumber', 'expiresAt']
	 * Response (failure): ['valid' => false, 'error' => 'not_found' | 'cannot_read']
	 */
	public static function parseCertificateFile(string $filepath, ?string $password): array {
		if (!file_exists($filepath)) {
			return ['valid' => false, 'error' => 'not_found'];
		}

		$certContent = file_get_contents($filepath);
		if ($certContent === false) {
			return ['valid' => false, 'error' => 'cannot_read'];
		}

		$pkcs12 = [];
		if (!openssl_pkcs12_read($certContent, $pkcs12, $password ?? '')) {
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
		];
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
