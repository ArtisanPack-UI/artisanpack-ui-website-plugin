<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use Closure;

/**
 * Decides whether an admin-entered URL is safe for the server to call.
 *
 * The docs site base URL is typed in on the Settings page, and the server
 * then sends the docs API token to it. Without a policy, anyone who can
 * edit settings could aim those requests (and the token) at the server's
 * own network: loopback, private ranges, or a cloud metadata endpoint.
 *
 * Outside local development the URL must be https, and its host must
 * resolve (A and AAAA records) to public addresses only: private,
 * reserved, carrier-grade NAT (`100.64.0.0/10`), NAT64 (`64:ff9b::/96`) and
 * IPv4-mapped (`::ffff:0:0/96`) addresses are refused, and so is a host
 * that doesn't resolve at all. Local development allows http and any host,
 * so a `.test` docs site on loopback works.
 *
 * The check runs when the URL is saved and again before every request:
 * {@see \ArtisanPackUI\Site\Services\Docs\DocsSiteClient} pins the
 * connection to an address {@see self::resolve()} vetted, so a DNS answer
 * that changes between the check and the request (DNS rebinding) can't
 * redirect it. The client also refuses redirects, so an allowed host can't
 * bounce a request somewhere this check never saw.
 *
 * @since 1.0.0
 */
final class OutboundUrlPolicy
{
    /**
     * Ranges PHP's `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`
     * lets through but which reach private networks.
     *
     * @var list<string>
     */
    private const BLOCKED_RANGES = ['100.64.0.0/10', '64:ff9b::/96', '::ffff:0:0/96'];

    /**
     * Replaces DNS lookups, for tests.
     *
     * @var (Closure(string): list<string>)|null
     */
    private static ?Closure $resolver = null;

    /**
     * Why the URL may not be called, or null when it may.
     *
     * @param  bool  $allowPrivate  Allow http and private hosts (local development).
     */
    public static function problem(string $url, bool $allowPrivate): ?string
    {
        return self::resolve($url, $allowPrivate)['problem'];
    }

    /**
     * Check the URL and resolve its host. `ips` lists the vetted addresses
     * a request may be pinned to; it is empty when there is a problem, and
     * in local development, where nothing is pinned.
     *
     * @param  bool  $allowPrivate  Allow http and private hosts (local development).
     *
     * @return array{problem: string|null, ips: list<string>}
     */
    public static function resolve(string $url, bool $allowPrivate): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = (string) parse_url($url, PHP_URL_HOST);

        if ('' === $host || ! in_array($scheme, ['http', 'https'], true)) {
            return ['problem' => __('The URL must be a full http(s) address.'), 'ips' => []];
        }

        if ($allowPrivate) {
            return ['problem' => null, 'ips' => []];
        }

        if ('https' !== $scheme) {
            return ['problem' => __('The URL must use https.'), 'ips' => []];
        }

        $host = trim($host, '[]');
        $ips  = false !== filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::lookup($host);

        if ([] === $ips) {
            return ['problem' => __('The URL\'s host doesn\'t resolve to an address.'), 'ips' => []];
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return ['problem' => __('The URL points at a private or reserved network address.'), 'ips' => []];
            }
        }

        return ['problem' => null, 'ips' => $ips];
    }

    /**
     * Resolve hosts with `$resolver` instead of DNS, or restore DNS with
     * null. For tests only.
     *
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveHostsUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * The host's A and AAAA addresses.
     *
     * @return list<string>
     */
    private static function lookup(string $host): array
    {
        if (null !== self::$resolver) {
            return array_values((self::$resolver)($host));
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips     = [];

        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && false !== filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isPublic(string $ip): bool
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `$ip` falls inside the CIDR `$range` (same address family).
     */
    private static function inRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = explode('/', $range, 2);

        $address = inet_pton($ip);
        $network = inet_pton($subnet);

        if (false === $address || false === $network || strlen($address) !== strlen($network)) {
            return false;
        }

        $bits      = (int) $bits;
        $fullBytes = intdiv($bits, 8);

        if (0 !== strncmp($address, $network, $fullBytes)) {
            return false;
        }

        $remaining = $bits % 8;

        if (0 === $remaining) {
            return true;
        }

        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (ord($address[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }
}
