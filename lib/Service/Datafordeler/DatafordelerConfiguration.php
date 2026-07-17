<?php

namespace OCA\OpenCase\Service\Datafordeler;

/**
 * DatafordelerConfiguration - Shared configuration for Datafordeler services
 *
 * This class holds common configuration settings needed to connect
 * to Datafordeler GraphQL services.
 */
class DatafordelerConfiguration
{
    private string $endpoint;
    private string $apikey;

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getApikey(): string
    {
        return $this->apikey;
    }

    public function setApikey(string $apikey): static
    {
        $this->apikey = $apikey;
        return $this;
    }
}
