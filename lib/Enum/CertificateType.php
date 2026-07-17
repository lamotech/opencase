<?php
declare(strict_types=1);

namespace OCA\OpenCase\Enum;

enum CertificateType: string {
	case Primary = 'Primary';
	case FKAccess = 'FKAccess';
	case FKOrganisation = 'FKOrganisation';
	case FKClassification = 'FKClassification';
	case FKDigitalPost = 'FKDigitalPost';
	case FKDistribution = 'FKDistribution';
	case Datafordeler = 'Datafordeler';
}
