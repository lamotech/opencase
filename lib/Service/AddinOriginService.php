<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\IConfig;
use OCP\IRequest;

/**
 * Resolves this instance's own host and the host the MS Office add-in is
 * served from, and decides which origins the Login Flow v2 helper page is
 * allowed to hand credentials back to.
 *
 * SECURITY: the login helper (templates/login-helper.php) ends up holding a
 * Nextcloud app password. It must only ever pass that to an origin an admin
 * has effectively vouched for — otherwise a crafted
 * ?returnRelayUrl=https://attacker.example/ link on the real Nextcloud origin
 * turns the genuine login flow into a credential-harvesting page. Everything
 * here fails closed: an origin that cannot be resolved or does not match is
 * simply not allowed.
 */
class AddinOriginService {

    public function __construct(
        private Configuration $configuration,
        private IConfig $systemConfig,
        private IRequest $request,
    ) {}

    /**
     * Resolve this Nextcloud instance's own domain — the OPENCASE_HOST
     * placeholder in the MS Office manifest templates, used for <AppDomains>
     * since the login dialog navigates there. Mirrors
     * msoffice/nginx/deploy.sh's detect_opencase_host(): overwrite.cli.url,
     * falling back to the first trusted domain, falling back to the host of
     * the current request.
     */
    public function getOpencaseHost(): string {
        $host = '';

        $overwriteUrl = $this->systemConfig->getSystemValueString('overwrite.cli.url', '');
        if ($overwriteUrl !== '') {
            $host = preg_replace('#^https?://#', '', $overwriteUrl);
            $host = explode('/', $host)[0];
        }

        if ($host === '') {
            $trustedDomains = $this->systemConfig->getSystemValue('trusted_domains', []);
            $host = $trustedDomains[0] ?? '';
        }

        if ($host === '') {
            $host = $this->request->getServerHost();
        }

        return $host;
    }

    /**
     * Resolve the ADDIN_HOST[:port] placeholder used in the MS Office manifest
     * templates. Port comes from the msoffice_addin_port config value
     * (default 1443), omitted when 443.
     */
    public function getAddinHost(?string $opencaseHost = null): string {
        $opencaseHost ??= $this->getOpencaseHost();

        $port = $this->configuration->getConfigValue('msoffice_addin_port', '1443');
        if ($port === '' || $port === '443') {
            return $opencaseHost;
        }

        return $opencaseHost . ':' . $port;
    }

    /**
     * Origins the login helper may hand credentials to.
     *
     * By default that is exactly the add-in's own origin (same host as this
     * instance, on the add-in port — the host baked into the generated
     * manifests). Deployments that serve the add-in from somewhere else can
     * add origins via the msoffice_addin_origins config value, as a
     * comma-separated list of full origins ("https://addin.example:1443").
     *
     * @return list<string> Normalised origins, lowercase, no trailing slash.
     */
    public function getAllowedRelayOrigins(): array {
        $origins = [];

        $addinHost = $this->getAddinHost();
        if ($addinHost !== '') {
            $origins[] = 'https://' . $addinHost;
        }

        $configured = (string)$this->configuration->getConfigValue('msoffice_addin_origins', '');
        foreach (explode(',', $configured) as $candidate) {
            $normalised = $this->normaliseOrigin(trim($candidate));
            if ($normalised !== null) {
                $origins[] = $normalised;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $origin): ?string => $this->normaliseOrigin($origin),
            $origins,
        ))));
    }

    /**
     * Return $url unchanged if its origin is allowlisted, otherwise null.
     *
     * Only absolute https URLs are considered — anything else (a relative
     * path, a javascript: or data: URI, a scheme-relative "//evil.example")
     * cannot produce a matching origin and is rejected.
     */
    public function resolveRelayUrl(?string $url): ?string {
        if ($url === null || $url === '') {
            return null;
        }

        $origin = $this->originOf($url);
        if ($origin === null) {
            return null;
        }

        return in_array($origin, $this->getAllowedRelayOrigins(), true) ? $url : null;
    }

    /**
     * Extract the scheme://host[:port] origin of an absolute https URL,
     * or null if it isn't one.
     */
    private function originOf(string $url): ?string {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        // A userinfo component ("https://allowed.example@evil.example/") would
        // make the URL point somewhere other than the origin we'd compute if
        // we ignored it. parse_url() splits it out correctly, but reject it
        // outright rather than rely on every consumer doing the same.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $origin = 'https://' . strtolower($parts['host']);
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            $origin .= ':' . (int)$parts['port'];
        }

        return $origin;
    }

    /**
     * Normalise a bare origin string ("https://host:1443") for comparison,
     * dropping an explicit :443 and lowercasing the host.
     */
    private function normaliseOrigin(string $origin): ?string {
        if ($origin === '') {
            return null;
        }

        return $this->originOf(rtrim($origin, '/'));
    }
}
