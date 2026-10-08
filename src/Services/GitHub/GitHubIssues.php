<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\GitHub;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Services\Board\ProjectBoardReader;
use Illuminate\Contracts\Cache\Repository as Cache;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Text;

/**
 * Reads and edits one GitHub issue for the board's issue modal (roadmap
 * 5.3): the issue and its comment thread, the repo's labels, milestones and
 * assignable users to edit it with, and the edits themselves.
 *
 * Every repo is a repo *in the configured org*: callers pass only the repo
 * name, so the modal can't be pointed at a repo outside the org the App is
 * installed on. Within the org, only what the board shows is reachable:
 * an issue must be an item on the configured project
 * ({@see self::assertOnProject()}), which also rules out pull requests, and
 * the edit options are only listed for repos with issues on it.
 *
 * Markdown is rendered here with CommonMark (GitHub-flavoured), with raw
 * HTML escaped and unsafe links dropped, so the modal can show it as HTML
 * without trusting the issue's author. Images become links to the image,
 * so opening an issue never loads a remote URL an outside author chose
 * (which would leak the admin's IP), and every link opens in a new tab
 * with `noopener noreferrer`, so following one never loses unsaved edits.
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

    /** Rows per page of labels, milestones and assignees; GitHub's maximum. */
    public const OPTIONS_PAGE_SIZE = 100;

    /** Pages of each option list read at most. */
    public const MAX_OPTION_PAGES = 10;

    private static ?MarkdownConverter $markdown = null;

    /**
     * Seconds a confirmed "this issue is on the project" is remembered.
     */
    public const PROJECT_MEMBERSHIP_TTL = 300;

    private const PROJECT_ISSUE_QUERY = <<<'GRAPHQL'
        query ($owner: String!, $name: String!, $number: Int!) {
          repository(owner: $owner, name: $name) {
            issue(number: $number) { projectItems(first: 50, includeArchived: true) { nodes { project { id } } } }
          }
        }
        GRAPHQL;

    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly GitHubAppClient $github,
        private readonly ProjectBoardReader $reader,
        private readonly Cache $cache,
    ) {}

    /**
     * Whether `$name` is a valid GitHub repo name.
     */
    public static function isValidRepoName(string $name): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9._-]{1,100}$/', $name) && '.' !== $name && '..' !== $name;
    }

    /**
     * The issue and its newest {@see self::MAX_COMMENTS} comments, oldest
     * first, with `commentsTotal` saying how many it has in all.
     *
     * @return array<string, mixed>
     */
    public function show(string $repo, int $number): array
    {
        $this->assertOnProject($repo, $number);

        $path  = $this->issuePath($repo, $number);
        $issue = $this->github->rest('GET', $path)->data;
        $issue = is_array($issue) ? $issue : [];
        $total = max(0, (int) ($issue['comments'] ?? 0));

        return [
            ...$this->presentIssue($issue, $repo),
            'comments' => array_map(
                $this->presentComment(...),
                $this->latestComments($path, $total),
            ),
            'commentsTotal' => $total,
        ];
    }

    /**
     * The newest {@see self::MAX_COMMENTS} comments, oldest first. GitHub
     * lists comments oldest first, so this reads the last page, plus the
     * page before it when the last one is short.
     *
     * @return list<array<string, mixed>>
     *
     * @since 1.0.0
     */
    private function latestComments(string $issuePath, int $total): array
    {
        $lastPage = max(1, (int) ceil($total / self::MAX_COMMENTS));
        $comments = $this->commentsPage($issuePath, $lastPage);

        if ($lastPage > 1 && count($comments) < self::MAX_COMMENTS) {
            $comments = [...$this->commentsPage($issuePath, $lastPage - 1), ...$comments];
        }

        return array_slice($comments, -self::MAX_COMMENTS);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commentsPage(string $issuePath, int $page): array
    {
        $thread = $this->github->rest('GET', $issuePath . '/comments', ['per_page' => self::MAX_COMMENTS, 'page' => $page])->data;

        return array_values(array_filter(is_array($thread) ? $thread : [], is_array(...)));
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
        $this->assertOnProject($repo, $number);

        $issue = $this->github->rest('PATCH', $this->issuePath($repo, $number), $changes)->data;

        $this->reader->forgetBoard();

        return $this->presentIssue(is_array($issue) ? $issue : [], $repo);
    }

    /**
     * Add a comment and return it.
     *
     * @return array{id: int, author: User|null, bodyHtml: string, createdAt: string|null, url: string}
     */
    public function comment(string $repo, int $number, string $body): array
    {
        $this->assertOnProject($repo, $number);

        $comment = $this->github->rest('POST', $this->issuePath($repo, $number) . '/comments', ['body' => $body])->data;

        return $this->presentComment(is_array($comment) ? $comment : []);
    }

    /**
     * The repo's labels, open milestones and assignable users, for the
     * modal's editors. Each list is paged through while a page comes back
     * full, up to {@see self::MAX_OPTION_PAGES} pages.
     *
     * @return array{labels: list<array{name: string, color: string}>, milestones: list<array{number: int, title: string}>, assignees: list<User>}
     */
    public function options(string $repo): array
    {
        $base = $this->repoPath($repo);

        if (! in_array(strtolower($this->settings->githubOrganization() . '/' . $repo), $this->reader->repositories(), true)) {
            throw new GitHubException(__('That repo has no issues on the org project.'), 404);
        }

        $list = function (string $path, array $query = []) use ($base): array {
            $rows = [];

            for ($page = 1; $page <= self::MAX_OPTION_PAGES; ++$page) {
                $data = $this->github->rest('GET', $base . $path, ['per_page' => self::OPTIONS_PAGE_SIZE, 'page' => $page, ...$query])->data;
                $data = is_array($data) ? $data : [];
                $rows = [...$rows, ...array_filter($data, is_array(...))];

                if (count($data) < self::OPTIONS_PAGE_SIZE) {
                    break;
                }
            }

            return array_values($rows);
        };

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
     * Refuse an issue that isn't an item on the configured org project.
     * GitHub answers `issue: null` for a pull request's number, so pull
     * requests are refused too. Only a positive answer is cached.
     *
     * @throws IntegrationNotConfiguredException
     * @throws GitHubException With status 404 when the issue isn't on the project.
     *
     * @since 1.0.0
     */
    public function assertOnProject(string $repo, int $number): void
    {
        $this->repoPath($repo);

        $projectId = $this->reader->statusField()->projectId;
        $cacheKey  = 'artisanpack-ui:issue-on-project:' . sha1(strtolower($this->settings->githubOrganization()) . "|{$repo}|{$number}|{$projectId}");

        if ($this->cache->has($cacheKey)) {
            return;
        }

        $data = $this->github->graphql(self::PROJECT_ISSUE_QUERY, [
            'owner'  => $this->settings->githubOrganization(),
            'name'   => $repo,
            'number' => $number,
        ])->data;

        $nodes = is_array($data) ? ($data['repository']['issue']['projectItems']['nodes'] ?? null) : null;

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (is_array($node) && ($node['project']['id'] ?? null) === $projectId) {
                $this->cache->put($cacheKey, true, self::PROJECT_MEMBERSHIP_TTL);

                return;
            }
        }

        throw new GitHubException(__('That issue isn\'t on the org project.'), 404);
    }

    /**
     * Render issue markdown safely: raw HTML is escaped, not passed through,
     * images are turned into links, and links open in a new tab.
     */
    public static function renderMarkdown(?string $markdown): string
    {
        if (null === $markdown || '' === trim($markdown)) {
            return '';
        }

        return (string) self::markdownConverter()->convert($markdown);
    }

    /**
     * The GitHub-flavoured converter {@see self::renderMarkdown()} uses.
     *
     * The image listener runs at the default priority, before
     * ExternalLinkExtension's (-50), so the links it creates are marked
     * external too.
     *
     * @since 1.0.0
     */
    private static function markdownConverter(): MarkdownConverter
    {
        if (null !== self::$markdown) {
            return self::$markdown;
        }

        $environment = new Environment([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level'  => 50,
            'external_link'      => [
                'internal_hosts'     => [],
                'open_in_new_window' => true,
                'nofollow'           => 'external',
                'noopener'           => 'external',
                'noreferrer'         => 'external',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new ExternalLinkExtension);

        $environment->addEventListener(DocumentParsedEvent::class, self::replaceImagesWithLinks(...));

        return self::$markdown = new MarkdownConverter($environment);
    }

    /**
     * Swap every image for a link to it, labelled with its alt text or
     * "image".
     *
     * @since 1.0.0
     */
    private static function replaceImagesWithLinks(DocumentParsedEvent $event): void
    {
        $images = [];

        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Image) {
                $images[] = $node;
            }
        }

        foreach ($images as $image) {
            $link = new Link($image->getUrl(), null, $image->getTitle());

            foreach ($image->children() as $child) {
                $link->appendChild($child);
            }

            if (null === $link->firstChild()) {
                $link->appendChild(new Text(__('image')));
            }

            $image->replaceWith($link);
        }
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
