<?php

namespace OCA\OpenCase\Service\Serviceplatformen;

class ClassificationConfiguration extends ServiceplatformenConfiguration
{
    private string $classificationServiceCertificatePath;

    public function getClassificationServiceCertificatePath(): string
    {
        return $this->classificationServiceCertificatePath;
    }

    public function setClassificationServiceCertificatePath(string $classificationServiceCertificatePath): static
    {
        $this->classificationServiceCertificatePath = $classificationServiceCertificatePath;
        return $this;
    }
}
