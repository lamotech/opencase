<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\IUserManager;

/**
 * Enriches raw opencase_api_client rows with display-friendly fields for
 * the admin settings UI: the certificate's CN (for table columns), the
 * mapped user's display name, and the full parsed certificate info used by
 * the "full certificate info" popup dialog.
 *
 * Shared between AdminSettings (initial page load) and SettingsController
 * (the active/expires/delete AJAX endpoints, which must return the same
 * enriched shape so the frontend list stays consistent after an action).
 */
class ApiClientPresenterService {

    public function __construct(
        private IUserManager $userManager,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $clients
     * @return list<array<string, mixed>>
     */
    public function enrich(array $clients): array {
        return array_map(function (array $client): array {
            $certificateInfo = null;
            if (!empty($client['certificate'])) {
                $parsed = Certificate::parseX509Pem($client['certificate']);
                if ($parsed['valid']) {
                    $certificateInfo = $parsed;
                }
            }

            $client['subject_cn'] = $certificateInfo['subjectCn'] ?? Certificate::extractCn($client['subject_dn'] ?? null);
            $client['certificate_info'] = $certificateInfo;

            $client['mapped_to_user_name'] = null;
            if (!empty($client['mapped_to_user'])) {
                $user = $this->userManager->get($client['mapped_to_user']);
                $client['mapped_to_user_name'] = $user?->getDisplayName() ?? $client['mapped_to_user'];
            }

            return $client;
        }, $clients);
    }
}
