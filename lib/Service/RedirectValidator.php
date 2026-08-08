<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service;

use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Reduces a caller-supplied return target to a URL on this instance.
 *
 * The SAML endpoints take a return URL from the query string (redirect_url)
 * and carry it through the IdP round-trip as RelayState, then redirect to it
 * once the user is authenticated. That makes it attacker-reachable: a link to
 * our own /saml/login carrying someone else's URL sends the user there wearing
 * a freshly minted session, which is what makes an open redirect on a login
 * flow worth more than one on an ordinary page — it lends the attacker's
 * destination the credibility of a real login on the real domain.
 *
 * Everything here fails closed: a target that cannot be shown to live on this
 * instance is replaced by the caller's fallback.
 */
class RedirectValidator {

    public function __construct(
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {}

    /**
     * Return an absolute URL on this instance, or $fallback if $candidate
     * points anywhere else (or cannot be understood).
     */
    public function toLocalUrl(?string $candidate, string $fallback): string {
        $resolved = $this->resolve($candidate);

        if ($resolved === null) {
            if ($candidate !== null && trim($candidate) !== '') {
                $this->logger->warning(
                    'OpenCase: refused an off-site redirect target, falling back to a local page',
                    ['app' => 'opencase', 'candidate' => $candidate],
                );
            }
            return $fallback;
        }

        return $resolved;
    }

    /** True when $candidate resolves to a URL on this instance. */
    public function isLocal(?string $candidate): bool {
        return $this->resolve($candidate) !== null;
    }

    private function resolve(?string $candidate): ?string {
        if ($candidate === null) {
            return null;
        }

        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        // Control characters and newlines have no business in a URL and are a
        // standard way of smuggling past checks that only look at a prefix.
        if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }

        // Browsers normalise backslashes to forward slashes, so "/\evil.example"
        // navigates off-site in practice even though it reads as a local path.
        if (str_contains($candidate, '\\')) {
            return null;
        }

        $parts = parse_url($candidate);
        if ($parts === false) {
            return null;
        }

        if (isset($parts['host'])) {
            // A host with no scheme is protocol-relative ("//evil.example"),
            // which browsers follow off-site.
            if (!isset($parts['scheme'])
                || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                return null;
            }

            // "https://our.host@evil.example/" has host evil.example; the
            // origin check below already catches it, but userinfo in a return
            // URL is never legitimate here.
            if (isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }

            $resolved = $candidate;
        } else {
            // A scheme with no host is javascript:, data:, mailto: …
            if (isset($parts['scheme'])) {
                return null;
            }

            $resolved = $this->urlGenerator->getAbsoluteURL($candidate);
        }

        // One final assertion covering both branches: whatever we ended up
        // with has to sit on this instance's own origin.
        return $this->originOf($resolved) === $this->ownOrigin() ? $resolved : null;
    }

    /** scheme://host[:port] of an absolute URL, or null if it isn't one. */
    private function originOf(string $url): ?string {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $origin = $scheme . '://' . strtolower($parts['host']);

        $default = $scheme === 'https' ? 443 : 80;
        if (isset($parts['port']) && (int)$parts['port'] !== $default) {
            $origin .= ':' . (int)$parts['port'];
        }

        return $origin;
    }

    private function ownOrigin(): ?string {
        return $this->originOf($this->urlGenerator->getAbsoluteURL('/'));
    }
}
