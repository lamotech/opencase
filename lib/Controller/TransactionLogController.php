<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\TransactionLogService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class TransactionLogController extends ApiController {

    public function __construct(
        string $appName,
        IRequest $request,
        private TransactionLogService $transactionLogService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/transaction-log/address-protection')]
    public function logAddressProtection(): DataResponse {
        $body    = $this->request->getParams();
        $cpr     = trim((string)($body['cpr'] ?? ''));
        $address = trim((string)($body['address'] ?? ''));

        if ($cpr === '' || $this->userId === null) {
            return new DataResponse(['ok' => false], 400);
        }

        $this->transactionLogService->log($this->userId, 'show_address_protection', [
            'cpr'     => $cpr,
            'address' => $address,
        ]);

        return new DataResponse(['ok' => true]);
    }
}
