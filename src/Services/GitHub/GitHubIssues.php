<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\GitHub;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use Illuminate\Support\Str;

/**
 * Reads and edits one GitHub issue for the board's issue modal (roadmap
 * 5.3): the issue and its comment thread, the repo's labels, milestones and
 * assignable users to edit it with, and the edits themselves.
 *
 * Every repo is a repo *in the configured org*: callers pass only the repo
 * name, so the modal can't be pointed at a repo outside the org the App is
 * installed on.
 *
 * Markdown is rendered here with CommonMark (GitHub-flavoured), with raw
 * HTML escaped and unsafe links dropped, so the modal can show it as HTML
 * without trusting the issue's author.
 *
 * Writes are made by the GitHub App, so GitHub attributes them to the App's
 * bot account rather than the admin (roadmap open question 3).
 *
 * @phpstan-type User array{login: string, avatarUrl: string|null}
 *
 * @since 0.5.0
 */
class GitHubIssues
{
    /** The most comments the modal shows; GitHub's per-page maximum. */
    public const MAX_COMMENTS = 100;

    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly GitHubAppClient $github,
    ) {}

    /**
     * Whether `$name` is a valid GitHub repo name.
     */
    public static function isValidRepoName(string $name): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9._-]{1,100}$/', $name) && '.' !== $name && '..' !== $name;
    }

    /**
     * The issue and its comments.
     *
     * @return array<string, mixed>
     */
    public function show(string $repo, int $number): array
    {
        $path   = $this->issuePath($repo, $number);
        $issue  = $this->github->rest('GET', $path)->data;
        $thread = $this->github->rest('GET', $path . '/comments', ['per_page' => self::MAX_COMMENTS])->data;

        return [
            ...$this->presentIssue(is_array($issue) ? $issue : [], $repo),
            'comments' => array_values(array_map(
                $this->presentComment(...),
                array_filter(is_array($thread) ? $thread : [], is_array(...)),
            )),
        ];
    }

    /**
     * Apply `$changes` (any of title, body, labels, milestone, assignees,
     * state, state_reason, named as GitHub's REST API names them) and
     * return the updated issue, without its comments.
     *
     * @param  array<string, mixed>  $changes
     *
     * @return array<string, mixed>
     */
    public function update(string $repo, int $number, array $changes): array
    {
        $issue = $this->github->rest('PATCH', $this->issuePath($repo, $number), $changes)->data;

        return $this->presentIssue(is_array($issue) ? $issue : [], $repo);
    }

    /**
     * Add a comment and return it.
     *
     * @return array{id: int, author: User|null, bodyHtml: string, createdAt: string|null, url: string}
     */
    public function comment(string $repo, int $number, string $body): array
    {
        $comment = $this->github->rest('POST', $this->issuePath($repo, $number) . '/comments', ['body' => $body])->data;

        return $this->presentComment(is_array($comment) ? $comment : []);
    }

    /**
     * The repo's labels, open milestones and assignable users, for the
     * modal's editors.
     *
     * @return array{labels: list<array{name: string, color: string}>, milestones: list<array{number: int, title: string}>, assignees: list<User>}
     */
    public function options(string $repo): array
    {
        $base = $this->repoPath($repo);

        $list = fn (string $path, array $query = []): array => array_values(array_filter(
            (array) $this->github->rest('GET', $base . $path, ['per_page' => 100, ...$query])->data,
            is_array(...),
        ));

        return [
            'labels' => array_map(static fn (array $label): array => [
                'name'  => (string) ($label['name'] ?? ''),
                'color' => (string) ($label['color'] ?? ''),
            ], $list('/labels')),
            'milestones' => array_map(static fn (array $milestone): array => [
                'number' => (int) ($milestone['number'] ?? 0),
                'title'  => (string) ($milestone['title'] ?? ''),
            ], $list('/milestones', ['state' => 'open'])),
            'assignees' => array_map(self::presentUser(...), $list('/assignees')),
        ];
    }

    /**
     * Render issue markdown safely: raw HTML is escaped, not passed through.
     */
    public static function renderMarkdown(?string $markdown): string
    {
        if (null === $markdown || '' === trim($markdown)) {
            return '';
        }

        return (string) Str::markdown($markdown, [
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level'  => 50,
        ]);
    }

    /**
     * @param  array<string, mixed>  $issue
     *
     * @return array<string, mixed>
     */
    private function presentIssue(array $issue, string $repo): array
    {
        $milestone = is_array($issue['milestone'] ?? null) ? $issue['milestone'] : null;

        return [
            'repo'        => $this->settings->githubOrganization() . '/' . $repo,
            'number'      => (int) ($issue['number'] ?? 0),
            'title'       => (string) ($issue['title'] ?? ''),
            'body'        => (string) ($issue['body'] ?? ''),
            'bodyHtml'    => self::renderMarkdown(is_string($issue['body'] ?? null) ? $issue['body'] : null),
            'url'         => (string) ($issue['html_url'] ?? ''),
            'state'       => strtoupper((string) ($issue['state'] ?? 'open')),
            'stateReason' => is_string($issue['state_reason'] ?? null) ? $issue['state_reason'] : null,
            'labels'      => array_values(array_map(static fn (mixed $label): array => [
                'name'  => is_array($label) ? (string) ($label['name'] ?? '') : (string) $label,
                'color' => is_array($label) ? (string) ($label['color'] ?? '') : '',
            ], is_array($issue['labels'] ?? null) ? $issue['labels'] : [])),
            'milestone' => null === $milestone ? null : [
                'number' => (int) ($milestone['number'] ?? 0),
                'title'  => (string) ($milestone['title'] ?? ''),
            ],
            'assignees' => array_values(array_map(
                self::presentUser(...),
                array_filter(is_array($issue['assignees'] ?? null) ? $issue['assignees'] : [], is_array(...)),
            )),
            'author'    => is_array($issue['user'] ?? null) ? self::presentUser($issue['user']) : null,
            'createdAt' => is_string($issue['created_at'] ?? null) ? $issue['created_at'] : null,
            'updatedAt' => is_string($issue['updated_at'] ?? null) ? $issue['updated_at'] : null,
            'closedAt'  => is_string($issue['closed_at'] ?? null) ? $issue['closed_at'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $comment
     *
     * @return array{id: int, author: User|null, bodyHtml: string, createdAt: string|null, url: string}
     */
    private function presentComment(array $comment): array
    {
        return [
            'id'        => (int) ($comment['id'] ?? 0),
            'author'    => is_array($comment['user'] ?? null) ? self::presentUser($comment['user']) : null,
            'bodyHtml'  => self::renderMarkdown(is_string($comment['body'] ?? null) ? $comment['body'] : null),
            'createdAt' => is_string($comment['created_at'] ?? null) ? $comment['created_at'] : null,
            'url'       => (string) ($comment['html_url'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $user
     *
     * @return User
     */
    private static function presentUser(array $user): array
    {
        return [
            'login'     => (string) ($user['login'] ?? ''),
            'avatarUrl' => is_string($user['avatar_url'] ?? null) ? $user['avatar_url'] : null,
        ];
    }

    private function issuePath(string $repo, int $number): string
    {
        return $this->repoPath($repo) . '/issues/' . $number;
    }

    /**
     * @throws IntegrationNotConfiguredException
     * @throws GitHubException When `$repo` isn't a valid repo name.
     */
    private function repoPath(string $repo): string
    {
        if (! $this->settings->hasGitHubApp()) {
            throw IntegrationNotConfiguredException::gitHubApp();
        }

        if (! self::isValidRepoName($repo)) {
            throw new GitHubException(__('":repo" isn\'t a valid GitHub repo name.', ['repo' => $repo]), 422);
        }

        return '/repos/' . rawurlencode($this->settings->githubOrganization()) . '/' . rawurlencode($repo);
    }
}
