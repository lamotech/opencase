<?php
declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

use Exception;

class KombiResponseData
{
    public function __construct(
        public readonly bool $result,
        public readonly string $transmissionId,
        public readonly string $advisId,
        public readonly string $advisTekst,
        public readonly string $messageUUID,
    ) {
    }

    public static function fromXml(string $xml, string $messageUUID = ''): self
    {
        $doc = new \SimpleXMLElement($xml);

        return new self(
            result: filter_var((string)$doc->Result, FILTER_VALIDATE_BOOLEAN),
            transmissionId: (string)$doc->TransmissionID,
            advisId: (string)$doc->HovedoplysningerSvarREST->SvarReaktion->Advis->AdvisId,
            advisTekst: (string)$doc->HovedoplysningerSvarREST->SvarReaktion->Advis->AdvisTekst,
            messageUUID: $messageUUID,
        );
    }
}
