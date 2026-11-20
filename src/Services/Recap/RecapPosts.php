<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Models\IntegrationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads the site's existing recap posts and blog tags from the host's blog
 * tables.
 *
 * A post is a recap when it is in the **Updates** category and its title
 * starts with the title pattern's text before `{date}` ("This Week in
 * ArtisanPack UI" by default), so the recaps written before this feature
 * count without being marked. Trashed posts never count.
 *
 * Read with the query builder rather than cms-framework's models, so this
 * runs (and is tested) without the host. Dates are compared in the app's
 * timezone, which is what Laravel stores them in.
 *
 * @since 1.6.0
 */
final class RecapPosts
{
    /**
     * The category every recap post is filed under.
     */
    public const CATEGORY_NAME = 'Updates';

    public const CATEGORY_SLUG = 'updates';

    /**
     * The longest plain-text body an example recap is cut to, so a handful
     * of examples can't crowd out the week's material in the prompt.
     */
    private const EXAMPLE_TEXT_LIMIT = 6000;

    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * The latest published or scheduled recap dated before `$publishDate`'s
     * day, or null when there is none.
     *
     * @return array{id: int, title: string, publishedAt: string}|null
     */
    public function latestBefore(CarbonImmutable $publishDate): ?array
    {
        $row = $this->recaps()
            ->whereIn('posts.status', ['published', 'scheduled'])
            ->where('posts.published_at', '<', $publishDate->startOfDay()->toDateTimeString())
            ->orderByDesc('posts.published_at')
            ->first(['posts.id', 'posts.title', 'posts.published_at']);

        if (null === $row) {
            return null;
        }

        return [
            'id'          => (int) $row->id,
            'title'       => (string) $row->title,
            'publishedAt' => CarbonImmutable::parse((string) $row->published_at, (string) config('app.timezone'))->toIso8601String(),
        ];
    }

    /**
     * The newest published recaps as plain text, for the AI to match their
     * voice and structure.
     *
     * @return list<array{title: string, text: string}>
     */
    public function styleExamples(int $limit = 3): array
    {
        return $this->recaps()
            ->where('posts.status', 'published')
            ->orderByDesc('posts.published_at')
            ->limit($limit)
            ->get(['posts.title', 'posts.block_content', 'posts.content'])
            ->map(static fn (object $row): array => [
                'title' => (string) $row->title,
                'text'  => mb_substr(self::plainText($row->block_content, $row->content), 0, self::EXAMPLE_TEXT_LIMIT),
            ])
            ->filter(static fn (array $example): bool => '' !== $example['text'])
            ->values()
            ->all();
    }

    /**
     * Every blog tag's name, A to Z.
     *
     * @return list<string>
     */
    public function tagNames(): array
    {
        return DB::table('post_tags')->orderBy('name')->pluck('name')->map(static fn (mixed $name): string => (string) $name)->all();
    }

    /**
     * A post's body as plain text: the visual editor blocks when it has
     * them (headings as `##` lines, list items as `-` lines), otherwise its
     * HTML content.
     */
    public static function plainText(mixed $blockContent, mixed $content = null): string
    {
        $blocks = is_string($blockContent) ? json_decode($blockContent, true) : $blockContent;

        if (is_array($blocks) && [] !== $blocks) {
            $lines = [];
            self::collectText($blocks, $lines);

            return trim(implode("\n\n", array_filter($lines, static fn (string $line): bool => '' !== $line)));
        }

        return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $content)));
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  list<string>  $lines
     */
    private static function collectText(array $blocks, array &$lines): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $name = (string) ($block['name'] ?? '');
            $text = trim(html_entity_decode(strip_tags((string) ($block['attributes']['content'] ?? '')), ENT_QUOTES | ENT_HTML5));

            $lines[] = match (true) {
                '' === $text                         => '',
                str_ends_with($name, '/heading')   => '## ' . $text,
                str_ends_with($name, '/list-item') => '- ' . $text,
                default                            => $text,
            };

            if (is_array($block['innerBlocks'] ?? null)) {
                self::collectText($block['innerBlocks'], $lines);
            }
        }
    }

    /**
     * Recap posts: in the Updates category, titled with the pattern's
     * prefix, not trashed.
     */
    private function recaps(): Builder
    {
        // Not escaped: the prefix is admin-written, a stray `%` or `_` only
        // loosens the match, and SQLite's LIKE has no default escape.
        $like = $this->settings->recapTitlePrefix() . '%';

        return DB::table('posts')
            ->whereNull('posts.deleted_at')
            ->whereNotNull('posts.published_at')
            ->where('posts.title', 'like', $like)
            ->whereExists(static function (Builder $query): void {
                $query->select(DB::raw(1))
                    ->from('post_category_pivots')
                    ->join('post_categories', 'post_categories.id', '=', 'post_category_pivots.post_category_id')
                    ->whereColumn('post_category_pivots.post_id', 'posts.id')
                    ->where('post_categories.slug', self::CATEGORY_SLUG);
            });
    }
}
