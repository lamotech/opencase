<?php

declare(strict_types=1);

namespace OCA\OpenCase\AppInfo;

use OCA\OpenCase\BackgroundJob\BulkReindexJob;
use OCA\OpenCase\BackgroundJob\DispatchDigitalPostJob;
use OCA\OpenCase\BackgroundJob\ExportClosedCasesJob;
use OCA\OpenCase\BackgroundJob\ProcessDigitalPostReceivementsJob;
use OCA\OpenCase\BackgroundJob\ProcessDistributionReceivementsJob;
use OCA\OpenCase\BackgroundJob\ProcessMessageReceivementsJob;
use OCA\OpenCase\BackgroundJob\IndexFileJob;
use OCA\OpenCase\BackgroundJob\SyncFilecache;
use OCA\OpenCase\Dashboard\FavoritesWidget;
use OCA\OpenCase\Dashboard\InboundDocumentsWidget;
use OCA\OpenCase\Dashboard\MyCasesWidget;
use OCA\OpenCase\Dashboard\RecentWidget;
use OCA\OpenCase\Db\CaseMapper;
use OCA\OpenCase\Db\FileMapper;
use OCA\OpenCase\Event\FileUpdatedEvent;
use OCA\OpenCase\Listener\FileUpdatedListener;
use OCA\OpenCase\Listener\FilesIntegrationListener;
use OCA\OpenCase\Listener\TalkIntegrationListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent as FilesLoadAdditionalScriptsEvent;
use OCP\Collaboration\Resources\LoadAdditionalScriptsEvent;
use OCA\OpenCase\Controller\PublicApi\PublicCaseApiController;
use OCA\OpenCase\Controller\PublicApi\PublicDocumentApiController;
use OCA\OpenCase\Controller\PublicApi\PublicFileApiController;
use OCA\OpenCase\Controller\PublicApi\PublicReferenceApiController;
use OCA\OpenCase\Controller\PublicApi\PublicSearchApiController;
use OCA\OpenCase\Controller\PublicApi\PublicDigitalPostReceiverController;
use OCA\OpenCase\Controller\PublicApi\PublicDistributionReceiverController;
use OCA\OpenCase\Controller\PublicApi\PublicMessageReceiverController;
use OCA\OpenCase\Middleware\PublicApiMiddleware;
use OCA\OpenCase\Middleware\PublicDataApiMiddleware;
use OCA\OpenCase\Middleware\RoleMiddleware;
use OCA\OpenCase\Mount\OpenCaseMountProvider;
use OCA\OpenCase\Notification\FileShareNotifier;
use OCA\OpenCase\Search\OpenCaseCasesSearchProvider;
use OCA\OpenCase\Search\OpenCaseDocumentsSearchProvider;
use OCA\OpenCase\Search\OpenCaseFilesSearchProvider;
use OCA\OpenCase\Service\ElasticsearchService;
use OCA\OpenCase\Service\RoleService;
use OCA\OpenCase\BackgroundJob\SyncOrganisationsJob;
use OCA\OpenCase\BackgroundJob\SyncClassificationsJob;
use OCA\OpenCase\Service\Configuration;
use OCA\OpenCase\Service\TraceLogger;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Config\IMountProviderCollection;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IDBConnection;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class Application extends App implements IBootstrap {

    public const APP_ID = 'opencase';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);

        $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($vendorAutoload)) {
            require_once $vendorAutoload;
        }
    }

    public function register(IRegistrationContext $context): void {

        $context->registerMiddleware(PublicApiMiddleware::class);
        $context->registerMiddleware(PublicDataApiMiddleware::class);
        $context->registerServiceAlias('PublicCaseApiController', PublicCaseApiController::class);
        $context->registerServiceAlias('PublicDocumentApiController', PublicDocumentApiController::class);
        $context->registerServiceAlias('PublicFileApiController', PublicFileApiController::class);
        $context->registerServiceAlias('PublicReferenceApiController', PublicReferenceApiController::class);
        $context->registerServiceAlias('PublicSearchApiController', PublicSearchApiController::class);

        // PublicMessageReceiverController, PublicDistributionReceiverController,
        // and PublicDigitalPostReceiverController are Enterprise-only
        // components: they are not shipped in the public App Store package,
        // so guard against the classes simply not being present on disk.
        if (class_exists(PublicMessageReceiverController::class)) {
            $context->registerServiceAlias('PublicMessageReceiverController', PublicMessageReceiverController::class);
        }
        if (class_exists(PublicDistributionReceiverController::class)) {
            $context->registerServiceAlias('PublicDistributionReceiverController', PublicDistributionReceiverController::class);
        }
        if (class_exists(PublicDigitalPostReceiverController::class)) {
            $context->registerServiceAlias('PublicDigitalPostReceiverController', PublicDigitalPostReceiverController::class);
        }
        $context->registerMiddleware(RoleMiddleware::class);

        $context->registerDashboardWidget(MyCasesWidget::class);
        $context->registerDashboardWidget(InboundDocumentsWidget::class);
        $context->registerDashboardWidget(FavoritesWidget::class);
        $context->registerDashboardWidget(RecentWidget::class);

        $context->registerNotifierService(FileShareNotifier::class);

        $context->registerSearchProvider(OpenCaseCasesSearchProvider::class);
        $context->registerSearchProvider(OpenCaseDocumentsSearchProvider::class);
        $context->registerSearchProvider(OpenCaseFilesSearchProvider::class);

        // Listen for file update events to trigger ES re-indexing.
        $context->registerEventListener(
            FileUpdatedEvent::class,
            FileUpdatedListener::class
        );

        // Inject the Talk integration script whenever Talk renders its page.
        $context->registerEventListener(
            LoadAdditionalScriptsEvent::class,
            TalkIntegrationListener::class
        );

        // Inject the Files integration script whenever the Files app renders its page.
        $context->registerEventListener(
            FilesLoadAdditionalScriptsEvent::class,
            FilesIntegrationListener::class
        );

        // Explicitly register background jobs in the app container so that
        // JobList::buildJob() can resolve them via Server::get() with full DI.
        // (IRegistrationContext::registerBackgroundJob() does not exist in NC 34.)
        $context->registerService(SyncFilecache::class, function ($c) {
            return new SyncFilecache(
                $c->get(ITimeFactory::class),
                $c->get(IDBConnection::class),
                $c->get(LoggerInterface::class),
            );
        });

        $context->registerService(IndexFileJob::class, function ($c) {
            return new IndexFileJob(
                $c->get(ITimeFactory::class),
                $c->get(FileMapper::class),
                $c->get(CaseMapper::class),
                new ElasticsearchService(
                    $c->get(IClientService::class),
                    $c->get(IConfig::class),
                    $c->get(LoggerInterface::class),
                ),
                $c->get(IConfig::class),
                $c->get(LoggerInterface::class),
            );
        });

        $context->registerService(BulkReindexJob::class, function ($c) {
            return new BulkReindexJob(
                $c->get(ITimeFactory::class),
                $c->get(FileMapper::class),
                $c->get(IJobList::class),
                $c->get(LoggerInterface::class),
            );
        });
    }

    public function boot(IBootContext $context): void {
        $server = $context->getServerContainer();

        // Add the sync job to oc_jobs (idempotent — skipped if already present).
        $jobList = $server->get(IJobList::class);
        if (!$jobList->has(SyncFilecache::class, null)) {
            $jobList->add(SyncFilecache::class);
        }
		if (!$jobList->has(SyncOrganisationsJob::class, null)) {
			$jobList->add(SyncOrganisationsJob::class);
		}
		// IndexFileJob and BulkReindexJob are QueuedJobs: they are enqueued on
		// demand with a payload (fileId / caseId) and must never be registered
		// as standing jobs here — a null-argument instance just runs and warns.
		// Do not call IJobList::remove() for them either: with a null argument
		// it drops the argument filter and deletes *every* queued job of that
		// class, which would wipe pending index jobs on each request. Stale
		// null-argument rows clean themselves up, since QueuedJob::start()
		// removes the row by id before running.
		// ProcessDistributionReceivementsJob, ProcessMessageReceivementsJob,
		// DispatchDigitalPostJob, ProcessDigitalPostReceivementsJob, and
		// SyncClassificationsJob are Enterprise-only components (not present
		// in the public App Store package). Only schedule them when the
		// Enterprise package is installed AND licensed, and remove them from
		// the job list otherwise so a disabled/downgraded instance doesn't
		// keep trying (and failing) to run them.
		$enterpriseVersionEnabled = $server->get(Configuration::class)->getConfigValue('enterprise_version', '0') === '1';

		if ($enterpriseVersionEnabled && class_exists(ProcessDistributionReceivementsJob::class)) {
			if (!$jobList->has(ProcessDistributionReceivementsJob::class, null)) {
				$jobList->add(ProcessDistributionReceivementsJob::class);
			}
		} elseif ($jobList->has(ProcessDistributionReceivementsJob::class, null)) {
			$jobList->remove(ProcessDistributionReceivementsJob::class);
		}

		if ($enterpriseVersionEnabled && class_exists(ProcessMessageReceivementsJob::class)) {
			if (!$jobList->has(ProcessMessageReceivementsJob::class, null)) {
				$jobList->add(ProcessMessageReceivementsJob::class);
			}
		} elseif ($jobList->has(ProcessMessageReceivementsJob::class, null)) {
			$jobList->remove(ProcessMessageReceivementsJob::class);
		}

		if ($enterpriseVersionEnabled && class_exists(DispatchDigitalPostJob::class)) {
			if (!$jobList->has(DispatchDigitalPostJob::class, null)) {
				$jobList->add(DispatchDigitalPostJob::class);
			}
		} elseif ($jobList->has(DispatchDigitalPostJob::class, null)) {
			$jobList->remove(DispatchDigitalPostJob::class);
		}

		if ($enterpriseVersionEnabled && class_exists(ProcessDigitalPostReceivementsJob::class)) {
			if (!$jobList->has(ProcessDigitalPostReceivementsJob::class, null)) {
				$jobList->add(ProcessDigitalPostReceivementsJob::class);
			}
		} elseif ($jobList->has(ProcessDigitalPostReceivementsJob::class, null)) {
			$jobList->remove(ProcessDigitalPostReceivementsJob::class);
		}

		if ($enterpriseVersionEnabled && class_exists(SyncClassificationsJob::class)) {
			if (!$jobList->has(SyncClassificationsJob::class, null)) {
				$jobList->add(SyncClassificationsJob::class);
			}
		} elseif ($jobList->has(SyncClassificationsJob::class, null)) {
			$jobList->remove(SyncClassificationsJob::class);
		}
		if (!$jobList->has(ExportClosedCasesJob::class, null)) {
			$jobList->add(ExportClosedCasesJob::class);
		}

        // Register the virtual mount provider so "Sager/" appears in every
        // authorised user's file tree.
        //
        // The <filesystem/> type in info.xml ensures this app is loaded before
        // Nextcloud initialises the filesystem (same pattern as files_sharing).
        // injectFn() resolves dependencies via DI — same as files_sharing does.
        $context->injectFn([$this, 'registerMountProvider']);

        // Show the OpenCase icon in the top navigation only for users who have
        // the 'User' role. The <navigations> block has been removed from
        // info.xml so that we can gate it here at runtime.
        $user = $server->get(IUserSession::class)->getUser();
        if ($user !== null) {
            $roleService = $server->get(RoleService::class);
            if ($roleService->userHasRole($user->getUID(), 'User')) {
                $urlGenerator = $server->get(IURLGenerator::class);
                $server->get(INavigationManager::class)->add(function () use ($urlGenerator) {
                    return [
                        'id'    => 'opencase',
                        'order' => 10,
                        'href'  => $urlGenerator->linkToRoute('opencase.page.index'),
                        'icon'  => $urlGenerator->imagePath('opencase', 'app.svg'),
                        'name'  => 'OpenCase',
                        'type'  => 'link',
                    ];
                });
            }
        }

		try {
			$context->injectFn(function (
				IRequest $request,
				IUserSession $userSession,
				IURLGenerator $urlGenerator,
				IConfig $config,
				ISession $session,
				LoggerInterface $logger,
				Configuration $configuration,
				ICacheFactory $cacheFactory,
				TraceLogger $traceLogger,
				bool $isCLI,
			): void {
				if ($isCLI) {
					return;
				}

				// Only handle browser HTML GET requests (avoid breaking DAV/OCS clients)
				if (strtoupper($request->getMethod()) !== 'GET') {
					return;
				}
				$accept = (string)$request->getHeader('Accept');
				if ($accept !== '' && stripos($accept, 'text/html') === false) {
					/*
					$traceLogger->trace('boot_skip_non_html', [
						'accept' => $accept,
					]);
					*/
					return;
				}

				// Get path early
				$path = $request->getPathInfo();

				$traceLogger->trace('boot_path', [
					'path' => $path,
					'requestUri' => $request->getRequestUri(),
					'isLoggedIn' => $userSession->isLoggedIn(),
					'hasSamlMarker' => $session->exists('opencase.saml_login'),
				]);

				// Handle logout - redirect SAML users to SAML logout
				// This MUST be checked BEFORE the isLoggedIn check, because logout happens while logged in
				if ($path === '/logout') {
					// Check if this user logged in via SAML
					if ($session->exists('opencase.saml_login')) {
						$traceLogger->trace('saml_logout_redirect', [
							'userId' => $userSession->getUser() ? $userSession->getUser()->getUID() : null,
						]);

						$logger->debug('Redirecting SAML user to SAML logout', [
							'app' => self::APP_ID,
						]);

						// Build SAML logout URL
						$logoutUrl = $urlGenerator->linkToRouteAbsolute('opencase.saml.logout');

						header('Location: ' . $logoutUrl, true, 302);
						exit();
					}
					return;
				}

				// For login page handling, only act when not logged in
				if ($userSession->isLoggedIn()) {
					$traceLogger->trace('boot_skip_already_logged_in', [
						'path' => $path,
						'userId' => $userSession->getUser() ? $userSession->getUser()->getUID() : null,
					]);
					return;
				}

				// Prevent infinite loop: if we just completed SAML login, don't redirect again
				if ($session->exists('opencase.saml_login')) {
					$traceLogger->trace('boot_skip_saml_marker', [
						'path' => $path,
					]);
					$logger->debug('SAML login marker found, not redirecting', [
						'app' => self::APP_ID,
					]);
					return;
				}

				// Act on /login as well as the Login Flow v2 picker page
				// (used by 3rd-party clients such as the Outlook add-in),
				// so those clients also get redirected to the SAML IdP
				// instead of seeing Nextcloud's built-in login form.
				$isLoginFlowV2 = $path === '/login/v2/flow' || str_starts_with($path, '/login/v2/flow/');
				if ($path !== '/login' && !$isLoginFlowV2) {
					$traceLogger->trace('boot_skip_path_not_handled', [
						'path' => $path,
						'isLoginFlowV2' => $isLoginFlowV2,
					]);
					return;
				}

				$traceLogger->trace('boot_path_matched', [
					'path' => $path,
					'isLoginFlowV2' => $isLoginFlowV2,
				]);

				// Where to send the user back to once SAML login succeeds.
				// For /login, Nextcloud passes the original target as redirect_url.
				// For the Login Flow v2 picker, there's no such param — send the
				// user back to the picker page itself so the flow can complete.
				if ($isLoginFlowV2) {
					$requestUri = $request->getRequestUri();
					$flowRedirectTarget = str_starts_with($requestUri, 'http://') || str_starts_with($requestUri, 'https://')
						? $requestUri
						: $urlGenerator->getAbsoluteURL($requestUri);
				} else {
					$flowRedirectTarget = '';
				}

				$traceLogger->trace('boot_flow_redirect_target', [
					'flowRedirectTarget' => $flowRedirectTarget,
				]);

				// Check if remember-me cookies are present and VALID
				$ncToken = $request->getCookie('nc_token');
				$ncSessionId = $request->getCookie('nc_session_id');
				$ncUsername = $request->getCookie('nc_username');
				$hasRememberCookies = ($ncToken !== null && $ncSessionId !== null && $ncUsername !== null);

				// If cookies exist, verify the token is actually valid before trusting them
				$cookiesAreValid = false;
				if ($hasRememberCookies && $ncUsername !== null && $ncToken !== null) {
					// Check if token exists in stored tokens for this user
					$storedTokens = $config->getUserKeys($ncUsername, 'login_token');
					$cookiesAreValid = in_array($ncToken, $storedTokens, true);

					if (!$cookiesAreValid && count($storedTokens) > 0) {
						// Cookies exist but token is stale - clear them so user can do fresh SAML login
						$traceLogger->trace('stale_cookies_detected', [
							'nc_username' => $ncUsername,
							'tokenInCookie' => substr($ncToken, 0, 10) . '...',
							'storedTokensCount' => count($storedTokens),
						]);

						// Clear the stale cookies
						$webroot = \OC::$WEBROOT ?: '/';
						setcookie('nc_username', '', time() - 3600, $webroot);
						setcookie('nc_token', '', time() - 3600, $webroot);
						setcookie('nc_session_id', '', time() - 3600, $webroot);

						// Continue to SAML redirect below
						$hasRememberCookies = false;
					}
				}

				// Debug logging for session state
				$traceLogger->trace('boot_check', [
					'path' => $path,
					'isLoggedIn' => $userSession->isLoggedIn(),
					'hasRememberCookies' => $hasRememberCookies,
					'cookiesAreValid' => $cookiesAreValid,
					'sessionId' => session_id(),
					'ncSessionId' => $session->getId(),
					'hasSamlMarker' => $session->exists('opencase.saml_login'),
					'userId' => $userSession->getUser() ? $userSession->getUser()->getUID() : null,
				]);

				// If user has VALID remember-me cookies, try to log them in with cookies
				// and redirect to dashboard - don't just show the login page
				if ($hasRememberCookies && $cookiesAreValid) {
					// Use a lock to prevent parallel cookie login attempts from racing
					$cache = $cacheFactory->createDistributed('opencase');
					$lockKey = 'cookie_login_lock_' . $ncUsername;

					// Check if another request is already processing this login
					if ($cache->get($lockKey) !== null) {
						$traceLogger->trace('cookie_login_locked_skip', [
							'nc_username' => $ncUsername,
						]);
						// Another request is handling login, just redirect back
						$dashboardUrl = $flowRedirectTarget !== ''
							? $flowRedirectTarget
							: $urlGenerator->linkToRouteAbsolute('dashboard.dashboard.index');
						header('Location: ' . $dashboardUrl, true, 302);
						exit();
					}

					// Acquire lock (5 second TTL)
					$cache->set($lockKey, time(), 5);

					$traceLogger->trace('attempting_cookie_login', [
						'nc_username' => $ncUsername,
					]);

					// Try to login with the valid cookie
					if ($userSession instanceof \OC\User\Session) {
						$loginResult = $userSession->loginWithCookie($ncUsername, $ncToken, $ncSessionId);

						if ($loginResult) {
							// Successfully logged in - mark as SAML user
							$session->set('opencase.saml_login', true);
							$session->set('opencase.user_id', $ncUsername);

							// NOTE: loginWithCookie already creates a new remember-me token
							// Do NOT call createRememberMeToken here - it would create duplicates

							$traceLogger->trace('cookie_login_success', [
								'nc_username' => $ncUsername,
							]);

							// Release lock
							$cache->remove($lockKey);

							// Redirect back to the original target (or dashboard)
							$dashboardUrl = $flowRedirectTarget !== ''
								? $flowRedirectTarget
								: $urlGenerator->linkToRouteAbsolute('dashboard.dashboard.index');
							header('Location: ' . $dashboardUrl, true, 302);
							exit();
						} else {
							$traceLogger->trace('cookie_login_failed', [
								'nc_username' => $ncUsername,
							]);

							// Release lock
							$cache->remove($lockKey);

							// Cookie login failed - clear cookies and continue to SAML
							$webroot = \OC::$WEBROOT ?: '/';
							setcookie('nc_username', '', time() - 3600, $webroot);
							setcookie('nc_token', '', time() - 3600, $webroot);
							setcookie('nc_session_id', '', time() - 3600, $webroot);
						}
					} else {
						// Can't access Session class, just return and let NC handle it
						$cache->remove($lockKey);
						return;
					}
				}

                $traceLogger->trace('User is not logged in and has not cookies');
				// Bypass for admins (your requirement)
				// Note: getParams() can throw LogicException for some methods, but we are GET so OK.
				$params = $request->getParams();
				if (isset($params['direct']) && ($params['direct'] === 1 || $params['direct'] === '1')) {
                    $traceLogger->trace('Redirects for Admin bypassed due to direct=1 param', [
                        'path' => $path,
                        'params' => $params,
                    ]);
					return;
				}

				// Only redirect to SAML login when access control is enabled
				$accessControlEnabled = $configuration->getConfigValue('access_control_enable', '0');
				if ($accessControlEnabled !== '1') {
					$traceLogger->trace('boot_skip_access_control_disabled', [
						'path' => $path,
						'access_control_enable' => $accessControlEnabled,
					]);
					return;
				}
                $traceLogger->trace('SAML login when access control is enabled', [
                    'access_control_enabled' => $accessControlEnabled,
                ]);

				// Preserve redirect_url if present (Nextcloud uses this for /login),
				// or send the user back to the Login Flow v2 picker page so the
				// flow can complete for 3rd-party clients.
				$redirectUrl = $flowRedirectTarget;
				if ($redirectUrl === '' && isset($params['redirect_url']) && is_string($params['redirect_url'])) {
					$redirectUrl = $params['redirect_url'];
				}

				// For a simple GET redirect to your /saml/login, CSRF token is not strictly necessary.
				// If needed later, it can be obtained from the server container.
				$targetUrl = $urlGenerator->linkToRouteAbsolute(
					'opencase.saml.login', // <— matches your controller route name (see below)
					[
						'redirect_url' => $redirectUrl,
					]
				);
                $traceLogger->trace('Redirecting to SAML login', [
                    'redirect_url' => $redirectUrl,
                    'targetUrl' => $targetUrl,
                ]);
                
				$logger->debug('Auto-redirecting /login to SAML login', [
					'app' => self::APP_ID,
					'path' => $path,
				]);

				header('Location: ' . $targetUrl, true, 302);
				exit();
			});
		} catch (Throwable $e) {
			// Never take the whole instance down if your app fails
			\OC::$server->get(LoggerInterface::class)->critical('Error when loading opencase app', [
				'exception' => $e,
			]);
		}        
    }

    public function registerMountProvider(
        IMountProviderCollection $mountProviderCollection,
        OpenCaseMountProvider $mountProvider,
    ): void {
        $mountProviderCollection->registerProvider($mountProvider);
    }
}
