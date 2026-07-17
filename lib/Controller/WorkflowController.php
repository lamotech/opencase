<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller;

use OCA\OpenCase\Service\WorkflowService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * REST endpoints for document review/approval workflows.
 *
 * Routes:
 *   POST   /api/v1/documents/{documentId}/workflows          — create a workflow
 *   GET    /api/v1/documents/{documentId}/workflows/active   — get active workflow + steps
 *   POST   /api/v1/workflows/{workflowId}/action             — submit an action on the current step
 *   DELETE /api/v1/workflows/{workflowId}                    — cancel a workflow
 */
class WorkflowController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private WorkflowService $workflowService,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Create a new workflow on a document.
     *
     * Body: { "type": "review"|"approval", "deadline": "ISO8601"|null, "user_ids": string[] }
     *
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/documents/{documentId}/workflows')]
    public function create(int $documentId): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $type       = $this->request->getParam('type', '');
        $deadlineStr = $this->request->getParam('deadline');
        $userIds    = $this->request->getParam('user_ids', []);

        if (!is_array($userIds) || empty($userIds)) {
            return new DataResponse(['message' => 'user_ids must be a non-empty array'], Http::STATUS_BAD_REQUEST);
        }

        $deadline = null;
        if (!empty($deadlineStr)) {
            try {
                $deadline = new \DateTime($deadlineStr);
            } catch (\Exception) {
                return new DataResponse(['message' => 'Invalid deadline date'], Http::STATUS_BAD_REQUEST);
            }
        }

        try {
            $workflow = $this->workflowService->create($documentId, $type, $deadline, $userIds, $user->getUID());
            $steps    = $this->workflowService->getSteps($workflow->getId());
            return new DataResponse(['workflow' => $this->workflowService->workflowToArray($workflow, $steps)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
        }
    }

    /**
     * Get the current user's pending workflow tasks (steps where it is their turn).
     *
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/me/workflow-tasks')]
    public function myTasks(): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new DataResponse(['tasks' => $this->workflowService->getMyPendingTasks($user->getUID())]);
    }

    /**
     * Get all workflows (history) for a document, newest first.
     *
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents/{documentId}/workflows')]
    public function history(int $documentId): DataResponse {
        return new DataResponse(['workflows' => $this->workflowService->getHistoryForDocument($documentId)]);
    }

    /**
     * Get the active workflow (with steps) for a document.
     *
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'GET', url: '/api/v1/documents/{documentId}/workflows/active')]
    public function active(int $documentId): DataResponse {
        $workflow = $this->workflowService->getActiveForDocument($documentId);
        if ($workflow === null) {
            return new DataResponse(['workflow' => null]);
        }
        $steps = $this->workflowService->getSteps($workflow->getId());
        return new DataResponse(['workflow' => $this->workflowService->workflowToArray($workflow, $steps)]);
    }

    /**
     * Submit an action on the current pending step.
     *
     * Body: { "action": "reviewed"|"approved"|"rejected", "comment": string|null }
     *
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'POST', url: '/api/v1/workflows/{workflowId}/action')]
    public function submitAction(int $workflowId): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $action  = $this->request->getParam('action', '');
        $comment = $this->request->getParam('comment');

        try {
            $step  = $this->workflowService->submitAction($workflowId, $user->getUID(), $action, $comment ?: null);
            $steps = $this->workflowService->getSteps($workflowId);
            return new DataResponse(['step' => $this->workflowService->stepToArray($step), 'steps' => array_map([$this->workflowService, 'stepToArray'], $steps)]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * Cancel an active workflow.
     *
     */
    #[NoAdminRequired]
    #[ApiRoute(verb: 'DELETE', url: '/api/v1/workflows/{workflowId}')]
    public function cancel(int $workflowId): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->workflowService->cancel($workflowId, $user->getUID());
            return new DataResponse([]);
        } catch (\RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }
}
