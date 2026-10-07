<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

/**
 * Decides whether an admin-entered URL is safe for the server to call.
 *
 * The docs site base URL is typed in on the Settings page, and the server
 * then sends the docs API token to it. Without a policy, anyone who can
 * edit settings could aim those requests (and the token) at the server's
 * own network: loopback, private ranges, or a cloud metadata endpoint.
 *
 * Outside local development the URL must be https, and its host must not
 * resolve to a private or reserved address. A host that doesn't resolve
 * is allowed, since it can't be called anyway and a transient DNS failure
 * shouldn't block a save. Local development allows both, so a `.test`
 * docs site on loopback works.
 *
 * The client also refuses redirects, so an allowed host can't bounce a
 * request somewhere this check never saw. DNS rebinding between this check
 * and the request is not covered; the URL is only editable by trusted
 * admins, so this guards against mistakes and a compromised admin account
 * probing the network, not a hostile DNS server.
 *
 * @since 0.2.0
 */
final class OutboundUrlPolicy
{
    /**
     * Why the URL may not be called, or null when it may.
     *
     * @param  bool  $allowPrivate  Allow http and private hosts (local development).
     */
    public static function problem(string $url, bool $allowPrivate): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = (string) parse_url($url, PHP_URL_HOST);

        if ('' === $host || ! in_array($scheme, ['http', 'https'], true)) {
            return __('The URL must be a full http(s) address.');
        }

        if ($allowPrivate) {
            return null;
        }

        if ('https' !== $scheme) {
            return __('The URL must use https.');
        }

        $host = trim($host, '[]');
        $ips  = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return __('The URL points at a private or reserved network address.');
            }
        }

        return null;
    }
}
