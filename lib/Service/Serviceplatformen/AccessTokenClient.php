<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

use Exception;
use OCA\OpenCase\Service\Certificate;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\TraceLogger;

class AccessTokenClient {
	public function __construct(
		private Configuration $configuration,
		private TraceLogger $traceLogger,
	) {}

	public function exchangeToAccessToken(SAMLToken $token, Certificate $certificate): AccessTokenData {
		$endpoint = $this->configuration->getConfigValue(
			'endpoint_accesstoken',
			'https://exttest.serviceplatformen.dk/service/AccessTokenService_1/token'
		);

		$encodedToken = urlencode(base64_encode($token->getAssertion()));
		$postData = 'saml-token=' . $encodedToken;

		$certFile = $this->writeTempFile($certificate->getPublicKey());
		$keyFile  = $this->writeTempFile($certificate->getPrivateKey());

		try {
			$ch = curl_init($endpoint);
			curl_setopt_array($ch, [
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $postData,
				CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
				CURLOPT_SSLCERT        => $certFile,
				CURLOPT_SSLKEY         => $keyFile,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FAILONERROR    => false,
			]);

			$responseData = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curlError = curl_error($ch);
			curl_close($ch);

			if ($responseData === false) {
				throw new Exception("cURL error: {$curlError}");
			}

			if ($httpCode < 200 || $httpCode >= 300) {
				throw new Exception("Access token request failed with HTTP {$httpCode}: {$responseData}");
			}

			$json = json_decode($responseData, true);
			if ($json === null) {
				throw new Exception("Failed to parse access token response: {$responseData}");
			}

			return new AccessTokenData(
				value: $json['access_token'],
				type: $json['token_type'],
				expiresInSeconds: (int)$json['expires_in'],
				retrievedAtUtc: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
				json: $responseData,
			);
		} finally {
			@unlink($certFile);
			@unlink($keyFile);
		}
	}

	private function writeTempFile(string $content): string {
		$path = tempnam(sys_get_temp_dir(), 'oc_cert_');
		if ($path === false || file_put_contents($path, $content) === false) {
			throw new Exception("Failed to write temporary certificate file");
		}
		return $path;
	}
}
