<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller\PublicApi;

/**
 * Marker interface for controllers that serve the mTLS-authenticated public API.
 *
 * The PublicApiMiddleware uses this interface to identify which controllers
 * it should protect. Any controller that implements this interface:
 *  - must carry #[PublicPage] and #[NoCSRFRequired] on its action methods
 *  - will have every request validated against the opencase_api_client registry
 *    before the action method is called
 */
interface PublicApiController {
}
