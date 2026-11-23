<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Support\OutboundUrlPolicy;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a public web page linked in the recap notes and reduces it to its
 * title and readable text, for the AI.
 *
 * The URL comes from the notes, or from the AI asking for it, so it is held
 * to {@see OutboundUrlPolicy} with private hosts refused even in local
 * development: https only, public addresses only, and the connection pinned
 * to the address that was vetted. Redirects are followed by hand, a few at
 * most, each one checked the same way. Only text responses are read, and
 * the text is cut short.
 *
 * @phpstan-type FetchedPage array{url: string, kind: string, title: string, text: string}
 *
 * @since 1.6.0
 */
final class PublicUrlReader
{
    public const MAX_REDIRECTS = 3;

    public const TIMEOUT = 10;

    /**
     * The largest response read, in bytes. A declared larger size is
     * refused up front; an undeclared one is cut off once it gets there.
     */
    public const MAX_BYTES = 2_000_000;

    /**
     * The longest text kept per page.
     */
    public const TEXT_LIMIT = 12000;

    private const TEXT_TYPES = ['text/', 'application/json', 'application/xhtml+xml', 'application/xml'];

    /**
     * @return FetchedPage
     *
     * @throws RecapException When the URL may not be called or can't be read.
     */
    public function read(string $url): array
    {
        $current = trim($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->fetch($current);

            if ($response->redirect()) {
                $current = self::resolveLocation($current, (string) $response->header('Location'));

                continue;
            }

            if (! $response->successful()) {
                throw new RecapException(__(':url answered with HTTP :status.', ['url' => self::short($current), 'status' => $response->status()]));
            }

            $type = strtolower((string) $response->header('Content-Type'));

            if ('' !== $type && ! self::isText($type)) {
                throw new RecapException(__(':url isn\'t a web page or text (it is :type).', ['url' => self::short($current), 'type' => strtok($type, ';')]));
            }

            return ['url' => $current, 'kind' => 'web_page', ...self::extract((string) $response->body(), $type)];
        }

        throw new RecapException(__(':url redirects too many times.', ['url' => self::short($url)]));
    }

    private function fetch(string $url): Response
    {
        ['problem' => $problem, 'ips' => $ips] = OutboundUrlPolicy::resolve($url, false);

        if (null !== $problem) {
            throw new RecapException(__(':url can\'t be fetched: :problem', ['url' => self::short($url), 'problem' => $problem]));
        }

        // CURLOPT_MAXFILESIZE only stops a transfer whose size is declared
        // (before libcurl 8.4), so the progress callback aborts one that
        // grows past the limit while it's read.
        $tooLarge = false;
        $options  = ['curl' => [
            CURLOPT_MAXFILESIZE      => self::MAX_BYTES,
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_XFERINFOFUNCTION => static function (mixed $handle, int $downloadTotal, int $downloaded) use (&$tooLarge): int {
                $tooLarge = $downloaded > self::MAX_BYTES;

                return $tooLarge ? 1 : 0;
            },
        ]];
        $pin = OutboundUrlPolicy::curlResolvePin($url, $ips);

        if (null !== $pin) {
            $options['curl'][CURLOPT_RESOLVE] = [$pin];
        }

        try {
            return Http::timeout(self::TIMEOUT)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'text/html,text/plain;q=0.9,application/json;q=0.8,*/*;q=0.1'])
                ->withUserAgent('ArtisanPackUI-Recap/1.0')
                ->withOptions($options)
                ->get($url);
        } catch (ConnectionException|RequestException|TransferException $exception) {
            if ($tooLarge) {
                throw new RecapException(__(':url is too large to read.', ['url' => self::short($url)]));
            }

            throw new RecapException(__(':url couldn\'t be reached.', ['url' => self::short($url)]));
        }
    }

    /**
     * The absolute URL a `Location` header points at.
     */
    private static function resolveLocation(string $from, string $location): string
    {
        $location = trim($location);

        if ('' === $location) {
            throw new RecapException(__(':url redirects without saying where.', ['url' => self::short($from)]));
        }

        if (1 === preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts  = parse_url($from);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';

        // RFC 3986 §5.2.2: a query-only reference keeps the current path, a
        // fragment-only one the path and query too.
        if (str_starts_with($location, '?')) {
            return $origin . $path . $location;
        }

        if (str_starts_with($location, '#')) {
            return $origin . $path . (isset($parts['query']) ? '?' . $parts['query'] : '') . $location;
        }

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }

    private static function isText(string $type): bool
    {
        foreach (self::TEXT_TYPES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The page's title and readable text: for HTML, the `<title>` and the
     * body without scripts, styles and page chrome; otherwise the text as
     * is.
     *
     * @return array{title: string, text: string}
     */
    private static function extract(string $body, string $type): array
    {
        if (! str_contains($type, 'html') && ! preg_match('/^\s*<(?:!doctype html|html)/i', $body)) {
            return ['title' => '', 'text' => mb_substr(trim($body), 0, self::TEXT_LIMIT)];
        }

        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $body, $match) ? self::clean($match[1]) : '';
        $html  = (string) preg_replace('#<(head|title|script|style|noscript|svg|nav|header|footer|form|iframe|template)\b[^>]*>.*?</\1>#is', ' ', $body);
        $html  = (string) preg_replace('#<(?:br|/p|/div|/li|/h[1-6]|/tr|/section|/article)\b[^>]*>#i', "\n", $html);

        return ['title' => $title, 'text' => mb_substr(self::clean($html, true), 0, self::TEXT_LIMIT)];
    }

    private static function clean(string $html, bool $keepLines = false): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);

        if (! $keepLines) {
            return trim((string) preg_replace('/\s+/', ' ', $text));
        }

        return trim((string) preg_replace("/\s*\n\s*(?:\n\s*)*/", "\n", $text));
    }

    private static function short(string $url): string
    {
        return mb_substr($url, 0, 200);
    }
}
