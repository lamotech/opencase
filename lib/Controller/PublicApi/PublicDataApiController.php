<?php

declare(strict_types=1);

namespace OCA\OpenCase\Controller\PublicApi;

/**
 * Marker interface for controllers that serve the public data REST API
 * (search/retrieve/create/update of OpenCase data).
 *
 * The PublicDataApiMiddleware uses this interface to identify which
 * controllers it should protect. Any controller that implements this
 * interface:
 *  - must carry #[PublicPage] and #[NoCSRFRequired] on its action methods
 *  - will have every request authenticated via a bearer SAML assertion
 *    token before the action method is called, and will run as the
 *    Nextcloud user mapped to the matched API client
 */
interface PublicDataApiController {
}
