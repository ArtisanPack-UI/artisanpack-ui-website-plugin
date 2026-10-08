<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Board;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Reads the org-wide GitHub Project (v2) as a {@see Board} (roadmap 5.1).
 *
 * Live, with no local mirror: every {@see self::read()} pages through the
 * project's items over GraphQL, {@see self::PAGE_SIZE} at a time. Only
 * issues become cards. Pull requests, draft issues and archived items are
 * skipped, because the board edits issues. Each issue's repo is mapped to
 * the package whose `github_repo` names it.
 *
 * The Status field's ids, which a status change is written against, are
 * cached for {@see self::STATUS_FIELD_TTL} seconds by {@see self::statusField()}
 * so a drag doesn't spend a read first. Every full read refreshes them.
 *
 * @since 0.5.0
 */
class ProjectBoardReader
{
    public const PAGE_SIZE = 100;

    /**
     * Pages read before giving up, so a runaway project can't hold an admin
     * request open. 3,000 items is far beyond the org's project.
     */
    public const MAX_PAGES = 30;

    public const STATUS_FIELD_TTL = 600;

    /** The single-select field whose options are the board's columns. */
    public const STATUS_FIELD = 'Status';

    private const STATUS_FIELD_FRAGMENT = <<<'GRAPHQL'
        field(name: "Status") {
          ... on ProjectV2SingleSelectField { id options { id name color } }
        }
        GRAPHQL;

    private const ITEMS_QUERY = <<<'GRAPHQL'
        query ($login: String!, $number: Int!, $cursor: String) {
          organization(login: $login) {
            projectV2(number: $number) {
              id
              title
              url
              number
              %s
              items(first: %d, after: $cursor) {
                pageInfo { hasNextPage endCursor }
                nodes {
                  id
                  isArchived
                  fieldValueByName(name: "Status") {
                    ... on ProjectV2ItemFieldSingleSelectValue { optionId }
                  }
                  content {
                    __typename
                    ... on Issue {
                      number
                      title
                      url
                      state
                      createdAt
                      updatedAt
                      repository { nameWithOwner }
                      milestone { number title }
                      labels(first: 20) { nodes { name color } }
                      assignees(first: 10) { nodes { login avatarUrl } }
                    }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    private const STATUS_FIELD_QUERY = <<<'GRAPHQL'
        query ($login: String!, $number: Int!) {
          organization(login: $login) {
            projectV2(number: $number) {
              id
              %s
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly GitHubAppClient $github,
        private readonly Cache $cache,
    ) {}

    /**
     * The whole project, every page of it.
     *
     * @throws IntegrationNotConfiguredException When the GitHub App or the project number isn't saved.
     * @throws GitHubException                   When GitHub can't be read, or the project has no Status field.
     */
    public function read(): Board
    {
        [$login, $number] = $this->project();

        $packages = $this->packagesByRepo();
        $query    = sprintf(self::ITEMS_QUERY, self::STATUS_FIELD_FRAGMENT, self::PAGE_SIZE);
        $items    = [];
        $cursor   = null;
        $project  = null;
        $status   = null;

        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $project = $this->projectNode(
                $this->github->graphql($query, ['login' => $login, 'number' => $number, 'cursor' => $cursor])->data,
                $number,
            );
            $status ??= $this->statusFieldFrom($project);

            $connection = is_array($project['items'] ?? null) ? $project['items'] : [];

            foreach (is_array($connection['nodes'] ?? null) ? $connection['nodes'] : [] as $node) {
                $item = is_array($node) ? $this->item($node, $packages) : null;

                if (null !== $item) {
                    $items[] = $item;
                }
            }

            $pageInfo = is_array($connection['pageInfo'] ?? null) ? $connection['pageInfo'] : [];
            $cursor   = is_string($pageInfo['endCursor'] ?? null) ? $pageInfo['endCursor'] : null;

            if (true !== ($pageInfo['hasNextPage'] ?? false) || null === $cursor) {
                break;
            }
        }

        $this->cache->put($this->statusCacheKey($login, $number), $status->toArray(), self::STATUS_FIELD_TTL);

        return new Board(
            (string) ($project['title'] ?? ''),
            (string) ($project['url'] ?? ''),
            (int) ($project['number'] ?? $number),
            $status,
            $items,
        );
    }

    /**
     * The project's Status field, from the cache when it's fresh.
     *
     * @throws IntegrationNotConfiguredException
     * @throws GitHubException
     */
    public function statusField(): ProjectStatusField
    {
        [$login, $number] = $this->project();

        $key    = $this->statusCacheKey($login, $number);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return ProjectStatusField::fromArray($cached);
        }

        $status = $this->statusFieldFrom($this->projectNode(
            $this->github->graphql(sprintf(self::STATUS_FIELD_QUERY, self::STATUS_FIELD_FRAGMENT), ['login' => $login, 'number' => $number])->data,
            $number,
        ));

        $this->cache->put($key, $status->toArray(), self::STATUS_FIELD_TTL);

        return $status;
    }

    /**
     * Drop the cached Status field, e.g. after a write it was rejected for.
     */
    public function forgetStatusField(): void
    {
        [$login, $number] = $this->project();

        $this->cache->forget($this->statusCacheKey($login, $number));
    }

    /**
     * The org login and project number.
     *
     * @return array{0: string, 1: int}
     */
    private function project(): array
    {
        if (! $this->settings->hasGitHubApp()) {
            throw IntegrationNotConfiguredException::gitHubApp();
        }

        $number = (int) $this->settings->github_project_number;

        if ($number < 1) {
            throw IntegrationNotConfiguredException::gitHubProject();
        }

        return [$this->settings->githubOrganization(), $number];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectNode(mixed $data, int $number): array
    {
        $project = is_array($data) ? ($data['organization']['projectV2'] ?? null) : null;

        if (! is_array($project)) {
            throw new GitHubException(__('Project #:number wasn\'t found in the :org org, or the App can\'t read it.', [
                'number' => $number,
                'org'    => $this->settings->githubOrganization(),
            ]), 404);
        }

        return $project;
    }

    /**
     * @param  array<string, mixed>  $project
     */
    private function statusFieldFrom(array $project): ProjectStatusField
    {
        $field = is_array($project['field'] ?? null) ? $project['field'] : [];

        if (! is_string($field['id'] ?? null) || ! is_array($field['options'] ?? null)) {
            throw new GitHubException(__('The org project has no single-select ":field" field to use as the board columns.', ['field' => self::STATUS_FIELD]));
        }

        $options = [];

        foreach ($field['options'] as $option) {
            if (is_array($option) && is_string($option['id'] ?? null)) {
                $options[] = [
                    'id'    => $option['id'],
                    'name'  => (string) ($option['name'] ?? ''),
                    'color' => is_string($option['color'] ?? null) ? $option['color'] : null,
                ];
            }
        }

        return new ProjectStatusField((string) ($project['id'] ?? ''), $field['id'], $options);
    }

    /**
     * A card for an issue item, or null for anything else.
     *
     * @param  array<string, mixed>                          $node
     * @param  array<string, array{id: int, title: string}>  $packages
     */
    private function item(array $node, array $packages): ?BoardItem
    {
        $issue = is_array($node['content'] ?? null) ? $node['content'] : [];

        if (true === ($node['isArchived'] ?? false) || 'Issue' !== ($issue['__typename'] ?? null) || ! is_string($node['id'] ?? null)) {
            return null;
        }

        $repo   = (string) ($issue['repository']['nameWithOwner'] ?? '');
        $status = is_array($node['fieldValueByName'] ?? null) ? ($node['fieldValueByName']['optionId'] ?? null) : null;

        return new BoardItem(
            id: $node['id'],
            statusId: is_string($status) ? $status : null,
            repo: $repo,
            number: (int) ($issue['number'] ?? 0),
            title: (string) ($issue['title'] ?? ''),
            url: (string) ($issue['url'] ?? ''),
            state: (string) ($issue['state'] ?? 'OPEN'),
            labels: self::nodes($issue['labels'] ?? null, static fn (array $label): array => [
                'name'  => (string) ($label['name'] ?? ''),
                'color' => (string) ($label['color'] ?? ''),
            ]),
            milestone: is_array($issue['milestone'] ?? null) ? [
                'number' => (int) ($issue['milestone']['number'] ?? 0),
                'title'  => (string) ($issue['milestone']['title'] ?? ''),
            ] : null,
            assignees: self::nodes($issue['assignees'] ?? null, static fn (array $user): array => [
                'login'     => (string) ($user['login'] ?? ''),
                'avatarUrl' => is_string($user['avatarUrl'] ?? null) ? $user['avatarUrl'] : null,
            ]),
            createdAt: is_string($issue['createdAt'] ?? null) ? Carbon::parse($issue['createdAt']) : null,
            updatedAt: is_string($issue['updatedAt'] ?? null) ? Carbon::parse($issue['updatedAt']) : null,
            package: $packages[strtolower($repo)] ?? null,
        );
    }

    /**
     * Every package with a GitHub repo, keyed by the lowercased repo.
     *
     * @return array<string, array{id: int, title: string}>
     */
    private function packagesByRepo(): array
    {
        $packages = [];

        Package::query()
            ->whereNotNull('github_repo')
            ->orderBy('id')
            ->get(['id', 'title', 'github_repo'])
            ->each(static function (Package $package) use (&$packages): void {
                $repo = strtolower(trim((string) $package->github_repo));

                if ('' !== $repo) {
                    $packages[$repo] ??= ['id' => (int) $package->getKey(), 'title' => (string) $package->title];
                }
            });

        return $packages;
    }

    /**
     * Map a GraphQL connection's `nodes`.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $map
     *
     * @return list<T>
     */
    private static function nodes(mixed $connection, callable $map): array
    {
        $nodes = is_array($connection) && is_array($connection['nodes'] ?? null) ? $connection['nodes'] : [];

        return array_values(array_map($map, array_filter($nodes, is_array(...))));
    }

    /**
     * Keyed on the org and project, so changing either in Settings never
     * reuses the old project's ids.
     */
    private function statusCacheKey(string $login, int $number): string
    {
        return 'artisanpack-ui:board:status-field:' . sha1(strtolower($login) . '|' . $number);
    }
}
