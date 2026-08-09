<?php

namespace OCA\OpenCase\Service\Serviceplatformen;

class OrganisationConfiguration extends ServiceplatformenConfiguration
{
    private string $organisationServiceCertificatePath;

    public function getOrganisationServiceCertificatePath(): string
    {
        return $this->organisationServiceCertificatePath;
    }

    public function setOrganisationServiceCertificatePath(string $organisationServiceCertificatePath): static
    {
        $this->organisationServiceCertificatePath = $organisationServiceCertificatePath;
        return $this;
    }
}
