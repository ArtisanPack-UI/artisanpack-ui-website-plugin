<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Exceptions\RecapException;
use Carbon\CarbonImmutable;
use JsonException;
use Throwable;

/**
 * The weekly recap's prompts: the instructions and output schemas for the
 * outline and draft agents, the input each one is sent, and the checks
 * their answers pass before the plugin uses them.
 *
 * Kept apart from the agent classes (which need Keystone's AI package) so
 * the prompt contents are testable in the plugin's own suite.
 *
 * A recap context is what a recap session knows:
 *
 * ```
 * [
 *   'title'         => string,                 // fixed, from the title pattern
 *   'publishDate'   => 'Y-m-d',
 *   'window'        => RecapWindow::toArray(),
 *   'notes'         => string,
 *   'releases'      => list<RecapRelease>,     // see RecapReleaseCollector
 *   'warnings'      => list<string>,
 *   'pullRequests'  => list<MergedPullRequest>,
 *   'sources'       => list<RecapSource>,      // fetched links
 *   'styleGuide'    => string|null,
 *   'styleExamples' => list<array{title, text}>,
 *   'packages'      => list<string>,           // every package's name
 *   'tags'          => list<string>,           // every existing blog tag
 *   'messages'      => list<array{role: 'user'|'assistant', content: string}>,
 *   'outline'       => string|null,
 * ]
 * ```
 *
 * @since 1.6.0
 */
final class RecapPrompts
{
    /**
     * The most tags a draft may propose that don't exist yet.
     */
    public const MAX_NEW_TAGS = 3;

    /**
     * The most tags a draft may give a post in all.
     */
    public const MAX_TAGS = 8;

    /**
     * The longest meta description kept.
     */
    public const META_DESCRIPTION_LIMIT = 160;

    public static function outlineInstructions(): string
    {
        return <<<'PROMPT'
You help the maintainer of ArtisanPack UI, an open-source ecosystem of Laravel packages, plan the weekly "This Week in ArtisanPack UI" blog post. The post covers what happened in the ecosystem during the recap window.

You are given the recap material as JSON: the publish date and fixed post title, the window, every stable release published in the window (with its release notes), pull requests merged in the window, pages fetched from links in the maintainer's notes, the maintainer's notes, a style guide, recent recap posts as examples, and the conversation so far.

Write an outline for the post, in Markdown:
- Use `##` headings for the sections and bullet points under each for what the section will say. Keep bullets short; the outline is a plan, not the post.
- Every release in `releases` must appear in the outline, by package name and version. Group them in whatever way the notes ask for, otherwise in the way the example posts do.
- Follow the maintainer's notes on what to include, what to emphasize and how to organize the post. The notes take priority over the examples.
- Mention merged pull requests only when they're notable on their own (a new feature, a fix users will notice, work the notes call out), not as a changelog.
- Don't invent changes, versions or features. Everything in the outline must come from the material. If something the notes ask for isn't in the material, say so in your reply rather than guessing.
- Don't write a title; the title is fixed.

When the conversation has a request from the maintainer after an earlier outline, revise the current outline to do what they asked and keep everything else as it was.

You may use the tools to read a GitHub pull request, issue, commit or web page the maintainer mentions that isn't already in `sources`, or to search the merged pull requests for something specific.

Return JSON with:
- `outline`: the full outline in Markdown.
- `reply`: one to three sentences to the maintainer: what the outline does, or what you changed, and anything you couldn't find.
- `packages`: the names of the packages the outline covers, spelled exactly as in `packages`.
PROMPT;
    }

    public static function draftInstructions(): string
    {
        return <<<'PROMPT'
You write the weekly "This Week in ArtisanPack UI" blog post for ArtisanPack UI, an open-source ecosystem of Laravel packages, from an outline the maintainer has approved.

You are given the recap material as JSON: the approved outline, the fixed post title, the publish date, every stable release published in the recap window (with its release notes), merged pull requests, pages fetched from the maintainer's links, the maintainer's notes, a style guide, recent recap posts as examples, the existing blog tags and the package names.

Write the post body in Markdown:
- Follow the outline's sections and order. Use `##` for section headings (and `###` below them if needed). Don't repeat the title as a heading.
- Open with a short introduction before the first heading.
- Write in the voice of the example posts and follow the style guide. Be specific: say what changed and why it matters to someone using the package. Link each release to its `url` the first time it's mentioned, and use inline code for package names, commands, class and method names.
- Use only facts from the material. Don't invent features, versions, numbers or quotes.

Also return:
- `excerpt`: one or two sentences summarizing the week, at most 300 characters, plain text.
- `focus_keyword`: the short phrase (two to four words) the post should rank for. Use it naturally in the introduction.
- `meta_description`: at most 155 characters, plain text, including the focus keyword.
- `tags`: up to five tags from `tags`, spelled exactly as listed, that fit the post.
- `new_tags`: up to three new tags, only for topics central to the post that no existing tag covers. Usually empty.
- `packages`: the names of the packages the post covers, spelled exactly as in `packages`.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public static function outlineSchema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['outline', 'reply', 'packages'],
            'properties'           => [
                'outline'  => ['type' => 'string'],
                'reply'    => ['type' => 'string'],
                'packages' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function draftSchema(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['body', 'excerpt', 'focus_keyword', 'meta_description', 'tags', 'new_tags', 'packages'],
            'properties'           => [
                'body'             => ['type' => 'string'],
                'excerpt'          => ['type' => 'string'],
                'focus_keyword'    => ['type' => 'string'],
                'meta_description' => ['type' => 'string'],
                'tags'             => ['type' => 'array', 'items' => ['type' => 'string']],
                'new_tags'         => ['type' => 'array', 'items' => ['type' => 'string']],
                'packages'         => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * What the outline agent is sent for a recap context.
     *
     * @param  array<string, mixed>  $context
     *
     * @return array<string, mixed>
     */
    public static function outlineInput(array $context): array
    {
        return [
            ...self::material($context),
            'current_outline' => self::stringOrNull($context['outline'] ?? null),
            'conversation'    => self::conversation($context['messages'] ?? []),
        ];
    }

    /**
     * What the draft agent is sent for a recap context.
     *
     * @param  array<string, mixed>  $context
     *
     * @return array<string, mixed>
     */
    public static function draftInput(array $context): array
    {
        return [
            ...self::material($context),
            'approved_outline' => (string) ($context['outline'] ?? ''),
            'tags'             => self::strings($context['tags'] ?? []),
        ];
    }

    /**
     * The user message for an agent input: the material as JSON, and what
     * to do with it.
     *
     * @param  array<string, mixed>  $input
     *
     * @return list<array{type: string, text: string}>
     */
    public static function message(array $input, string $task): array
    {
        try {
            $json = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw RecapException::malformedOutput('the recap material couldn\'t be encoded: ' . $exception->getMessage());
        }

        return [['type' => 'text', 'text' => "Recap material:\n" . $json . "\n\n" . $task]];
    }

    /**
     * The task line for an outline turn.
     *
     * @param  array<string, mixed>  $input
     */
    public static function outlineTask(array $input): string
    {
        return null === $input['current_outline']
            ? 'Write the first outline.'
            : 'Revise the current outline as the maintainer\'s latest message asks.';
    }

    public static function draftTask(): string
    {
        return 'Write the post from the approved outline.';
    }

    /**
     * Check and tidy the outline agent's answer.
     *
     * @param  list<array{url: string, kind: string, title: string, text: string}>  $sources
     *
     * @return array{outline: string, reply: string, packages: list<string>, sources: list<array{url: string, kind: string, title: string, text: string}>}
     *
     * @throws RecapException When there is no outline.
     */
    public static function normalizeOutline(mixed $raw, array $sources = []): array
    {
        $outline = is_array($raw) ? trim((string) ($raw['outline'] ?? '')) : '';

        if ('' === $outline) {
            throw RecapException::malformedOutput('no outline');
        }

        return [
            'outline'  => $outline,
            'reply'    => trim((string) ($raw['reply'] ?? '')),
            'packages' => self::strings($raw['packages'] ?? []),
            'sources'  => $sources,
        ];
    }

    /**
     * Check and tidy the draft agent's answer: the meta description is cut
     * to {@see self::META_DESCRIPTION_LIMIT} characters at a word, and the
     * tag lists are trimmed and de-duplicated (the caps are applied when
     * the post is built, against the real tags).
     *
     * @param  list<array{url: string, kind: string, title: string, text: string}>  $sources
     *
     * @return array{body: string, excerpt: string, focusKeyword: string, metaDescription: string, tags: list<string>, newTags: list<string>, packages: list<string>, sources: list<array{url: string, kind: string, title: string, text: string}>}
     *
     * @throws RecapException When there is no body.
     */
    public static function normalizeDraft(mixed $raw, array $sources = []): array
    {
        $body = is_array($raw) ? trim((string) ($raw['body'] ?? '')) : '';

        if ('' === $body) {
            throw RecapException::malformedOutput('no post body');
        }

        return [
            'body'            => $body,
            'excerpt'         => mb_substr(trim((string) ($raw['excerpt'] ?? '')), 0, 1000),
            'focusKeyword'    => mb_substr(trim((string) ($raw['focus_keyword'] ?? '')), 0, 255),
            'metaDescription' => self::cutAtWord(trim((string) ($raw['meta_description'] ?? '')), self::META_DESCRIPTION_LIMIT),
            'tags'            => self::strings($raw['tags'] ?? []),
            'newTags'         => self::strings($raw['new_tags'] ?? []),
            'packages'        => self::strings($raw['packages'] ?? []),
            'sources'         => $sources,
        ];
    }

    /**
     * The material both agents share.
     *
     * @param  array<string, mixed>  $context
     *
     * @return array<string, mixed>
     */
    private static function material(array $context): array
    {
        $window = is_array($context['window'] ?? null) ? $context['window'] : [];

        return [
            'title'                 => (string) ($context['title'] ?? ''),
            'publish_date'          => self::longDate((string) ($context['publishDate'] ?? '')),
            'window'                => [
                'from'           => $window['start'] ?? null,
                'to'             => $window['end'] ?? null,
                'previous_recap' => $window['previousRecap']['title'] ?? null,
            ],
            'notes'                 => trim((string) ($context['notes'] ?? '')),
            'releases'              => array_values(array_map(static fn (array $release): array => [
                'package'      => $release['package'] ?? '',
                'version'      => $release['tag'] ?? '',
                'name'         => $release['name'] ?? '',
                'url'          => $release['url'] ?? '',
                'published_at' => $release['publishedAt'] ?? '',
                'notes'        => $release['notes'] ?? '',
            ], array_filter(is_array($context['releases'] ?? null) ? $context['releases'] : [], 'is_array'))),
            'release_lookup_errors' => self::strings($context['warnings'] ?? []),
            'merged_pull_requests'  => is_array($context['pullRequests'] ?? null) ? array_values($context['pullRequests']) : [],
            'sources'               => is_array($context['sources'] ?? null) ? array_values($context['sources']) : [],
            'style_guide'           => self::stringOrNull($context['styleGuide'] ?? null),
            'example_posts'         => is_array($context['styleExamples'] ?? null) ? array_values($context['styleExamples']) : [],
            'packages'              => self::strings($context['packages'] ?? []),
        ];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private static function conversation(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }

        $turns = [];

        foreach ($messages as $message) {
            if (is_array($message) && in_array($message['role'] ?? null, ['user', 'assistant'], true)) {
                $turns[] = ['role' => $message['role'], 'content' => (string) ($message['content'] ?? '')];
            }
        }

        return $turns;
    }

    /**
     * Trimmed, non-empty, unique strings, compared case-insensitively.
     *
     * @return list<string>
     */
    public static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $kept = [];

        foreach ($values as $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ('' !== $value && ! isset($kept[mb_strtolower($value)])) {
                $kept[mb_strtolower($value)] = $value;
            }
        }

        return array_values($kept);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return '' === $value ? null : $value;
    }

    private static function longDate(string $date): string
    {
        try {
            return '' === $date ? '' : CarbonImmutable::parse($date)->format('l, F j, Y');
        } catch (Throwable) {
            return $date;
        }
    }

    private static function cutAtWord(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut   = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');

        return (string) preg_replace('/[\s,;:\-–—]+$/u', '', false === $space ? $cut : mb_substr($cut, 0, $space));
    }
}
