<?php

namespace OCA\OpenCase\Service\Serviceplatformen;
use OCA\OpenCase\Service\Certificate;

/**
 * ServiceplatformenConfiguration - Shared configuration for Serviceplatformen services
 *
 * This class holds common configuration settings needed to connect
 * to Serviceplatformen SOAP services.
 */
class ServiceplatformenConfiguration
{
    private string $endpoint;
    private Certificate $clientCertificate;
    private string $clientCertificatePath;
    private string $clientCertificatePassword;

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getClientCertificate(): Certificate
    {
        return $this->clientCertificate;
    }

    public function setClientCertificate(Certificate $clientCertificate): static
    {
        $this->clientCertificate = $clientCertificate;
        return $this;
    }

    public function getClientCertificatePath(): string
    {
        return $this->clientCertificatePath;
    }

    public function setClientCertificatePath(string $clientCertificatePath): static
    {
        $this->clientCertificatePath = $clientCertificatePath;
        return $this;
    }

    public function getClientCertificatePassword(): string
    {
        return $this->clientCertificatePassword;
    }

    public function setClientCertificatePassword(string $clientCertificatePassword): static
    {
        $this->clientCertificatePassword = $clientCertificatePassword;
        return $this;
    }
}
