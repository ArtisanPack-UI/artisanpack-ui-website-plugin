<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Social;

/**
 * One GitHub release, as the announcement posts describe it.
 *
 * Built from a `release` webhook payload by {@see self::fromPayload()}; the
 * post text is {@see self::text()}, shared by every network.
 *
 * @since 1.4.0
 */
final readonly class ReleaseAnnouncement
{
    /**
     * Longest release title, in characters, kept in a post. Bluesky allows
     * 300 graphemes in all, and the URL and headline take part of that.
     */
    public const TITLE_LIMIT = 100;

    /**
     * Bluesky's limit on a post's length, in graphemes. The title is left
     * out of a post that would run over it.
     */
    public const POST_LIMIT = 300;

    /**
     * Hashtags added to every post, without the `#`.
     *
     * @var list<string>
     */
    public const HASHTAGS = ['Laravel', 'OpenSource'];

    /**
     * The alphabet of an AT Protocol TID (base32, sortable).
     */
    private const TID_ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';

    /**
     * A version tag: `1.2.3` or `v1.2.3`, with an optional short suffix.
     * Anything else (a branch name, a sentence, a URL) isn't announced.
     */
    public const TAG_PATTERN = '/^v?\d+\.\d+\.\d+[0-9A-Za-z.+-]{0,40}$/';

    /**
     * A zero-width space, inserted after `@` and `#` and inside `://` so a
     * release title can't mention anyone, join a hashtag or add a link.
     */
    private const BREAK = "\u{200B}";

    public function __construct(
        public int $releaseId,
        public string $repository,
        public string $tag,
        public string $title,
        public string $url,
        public int $publishedAt = 0,
    ) {}

    /**
     * The release in a `release` webhook payload, or null when the payload
     * is missing what a post needs, the tag isn't a version, or the URL
     * isn't the repository's own release page on GitHub.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $release    = is_array($payload['release'] ?? null) ? $payload['release'] : [];
        $repository = is_array($payload['repository'] ?? null) ? $payload['repository'] : [];

        $id   = $release['id'] ?? null;
        $tag  = trim((string) ($release['tag_name'] ?? ''));
        $url  = (string) ($release['html_url'] ?? '');
        $name = trim((string) ($repository['name'] ?? ''));
        $full = trim((string) ($repository['full_name'] ?? ''));

        if (! is_int($id) || 1 !== preg_match(self::TAG_PATTERN, $tag) || '' === $name || '' === $full) {
            return null;
        }

        if (! str_starts_with(strtolower($url), strtolower('https://github.com/' . $full . '/releases/'))) {
            return null;
        }

        return new self(
            releaseId: $id,
            repository: $name,
            tag: $tag,
            title: trim((string) ($release['name'] ?? '')),
            url: $url,
            publishedAt: self::timestamp($release['published_at'] ?? null),
        );
    }

    /**
     * The headline, e.g. "seo v1.9.0 is out!".
     */
    public function headline(?string $repository = null): string
    {
        return __(':repository :version is out!', ['repository' => $repository ?? $this->repository, 'version' => $this->tag]);
    }

    /**
     * The release title when it says more than the tag, cut to
     * {@see self::TITLE_LIMIT} characters, with mentions, hashtags and
     * links defused; otherwise an empty string.
     */
    public function summary(): string
    {
        $title = (string) preg_replace('/\s+/u', ' ', $this->title);

        if ('' === $title || 0 === strcasecmp($title, $this->tag) || 0 === strcasecmp($title, $this->repository . ' ' . $this->tag)) {
            return '';
        }

        // Defused before cutting, so the breaks count toward the limit.
        $title = str_replace(['@', '#', '://'], ['@' . self::BREAK, '#' . self::BREAK, ':' . self::BREAK . '//'], $title);

        return mb_strlen($title) > self::TITLE_LIMIT ? rtrim(mb_substr($title, 0, self::TITLE_LIMIT - 1)) . '…' : $title;
    }

    /**
     * The post: the headline, the title when it adds anything, the
     * hashtags, and the release URL as the last line (Bluesky's link facet
     * relies on that).
     */
    public function text(): string
    {
        $text = $this->compose($this->summary());

        if (self::graphemes($text) <= self::POST_LIMIT) {
            return $text;
        }

        $text = $this->compose('');

        if (self::graphemes($text) <= self::POST_LIMIT) {
            return $text;
        }

        // Still too long: a very long repository name (it's in the URL
        // too). Shorten the name in the headline; the URL stays whole.
        $over = self::graphemes($text) - self::POST_LIMIT;
        $keep = max(1, mb_strlen($this->repository) - $over - 1);

        return $this->compose('', rtrim(mb_substr($this->repository, 0, $keep)) . '…');
    }

    /**
     * The Bluesky record key for this release's post: a TID built from the
     * publish time and the release id rather than the clock, so every
     * attempt at the same post uses the same key and a retry can find the
     * post an earlier attempt already made.
     */
    public function blueskyRecordKey(): string
    {
        $microseconds = max(0, $this->publishedAt) * 1_000_000 + $this->releaseId % 1_000_000;
        $value        = (($microseconds & 0x1F_FFFF_FFFF_FFFF) << 10) | ($this->releaseId % 1024);
        $key          = '';

        for ($index = 0; $index < 13; $index++) {
            $key   = self::TID_ALPHABET[$value & 31] . $key;
            $value >>= 5;
        }

        return $key;
    }

    /**
     * The hashtags line, e.g. `#Laravel #OpenSource`.
     */
    public function hashtagLine(): string
    {
        return implode(' ', array_map(static fn (string $tag): string => '#' . $tag, self::HASHTAGS));
    }

    /**
     * Where each hashtag sits in `$text`, as UTF-8 byte offsets (what
     * Bluesky's facets index by), keyed by the tag without its `#`.
     *
     * Found from the end of the text, where {@see self::compose()} puts the
     * hashtags line: the title can't hold a matching tag, since its `#`s are
     * defused.
     *
     * @return array<string, array{byteStart: int, byteEnd: int}>
     */
    public function hashtagOffsets(string $text): array
    {
        $line = $this->hashtagLine();
        $at   = strrpos($text, $line);

        if (false === $at) {
            return [];
        }

        $offsets = [];

        foreach (self::HASHTAGS as $tag) {
            $start = strpos($text, '#' . $tag, $at);

            if (false !== $start) {
                $offsets[$tag] = ['byteStart' => $start, 'byteEnd' => $start + strlen('#' . $tag)];
            }
        }

        return $offsets;
    }

    private function compose(string $summary, ?string $repository = null): string
    {
        $lines = [$this->headline($repository)];

        if ('' !== $summary) {
            $lines[] = $summary;
        }

        $lines[] = $this->hashtagLine();

        return implode("\n", $lines) . "\n\n" . $this->url;
    }

    private static function graphemes(string $text): int
    {
        $count = function_exists('grapheme_strlen') ? grapheme_strlen($text) : false;

        return is_int($count) ? $count : mb_strlen($text);
    }

    /**
     * The Unix time of an ISO 8601 timestamp, or 0 when there is none.
     */
    private static function timestamp(mixed $value): int
    {
        $time = is_string($value) ? strtotime($value) : false;

        return false === $time ? 0 : $time;
    }
}
