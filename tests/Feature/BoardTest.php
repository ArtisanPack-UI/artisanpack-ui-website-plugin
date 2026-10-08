<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\Board\BoardSummary;
use ArtisanPackUI\Site\Services\Board\ProjectBoardReader;
use ArtisanPackUI\Site\Support\Permissions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The kanban boards (roadmap 5.1, 5.2, 5.4 and 5.5): reading the org
 * project, the global and package board endpoints, moving a card, and the
 * dashboard board widget's counts.
 */

beforeEach(function (): void {
    actingAsUserWith([Permissions::ISSUES_MANAGE]);
    Http::preventStrayRequests();

    IntegrationSettings::create([
        'github_app_id'          => '123',
        'github_installation_id' => '456',
        'github_private_key'     => testPrivateKey(),
        'github_project_number'  => 7,
    ]);
});

/**
 * A project item holding an issue.
 *
 * @return array<string, mixed>
 */
function projectIssue(string $id, string $repo, int $number, ?string $status = 'opt-todo', array $overrides = []): array
{
    return [
        'id'               => $id,
        'isArchived'       => false,
        'fieldValueByName' => null === $status ? null : ['optionId' => $status],
        'content'          => [
            '__typename' => 'Issue',
            'number'     => $number,
            'title'      => "Issue {$number}",
            'url'        => "https://github.com/{$repo}/issues/{$number}",
            'state'      => 'OPEN',
            'createdAt'  => '2026-09-01T10:00:00Z',
            'updatedAt'  => '2026-10-01T10:00:00Z',
            'repository' => ['nameWithOwner' => $repo],
            'milestone'  => ['number' => 1, 'title' => 'v1.0'],
            'labels'     => ['nodes' => [['name' => 'bug', 'color' => 'd73a4a']]],
            'assignees'  => ['nodes' => [['login' => 'octocat', 'avatarUrl' => 'https://avatars.example/octocat']]],
        ],
        ...$overrides,
    ];
}

/**
 * One page of the project's GraphQL answer.
 *
 * @param  list<array<string, mixed>>  $nodes
 *
 * @return array<string, mixed>
 */
function projectPage(array $nodes, ?string $nextCursor = null): array
{
    return ['data' => ['organization' => ['projectV2' => [
        'id'     => 'PVT_project',
        'title'  => 'ArtisanPack UI',
        'url'    => 'https://github.com/orgs/ArtisanPack-UI/projects/7',
        'number' => 7,
        'field'  => ['id' => 'PVTSSF_status', 'options' => [
            ['id' => 'opt-todo', 'name' => 'Todo', 'color' => 'GRAY'],
            ['id' => 'opt-doing', 'name' => 'In progress', 'color' => 'YELLOW'],
            ['id' => 'opt-done', 'name' => 'Done', 'color' => 'GREEN'],
        ]],
        'items' => [
            'pageInfo' => ['hasNextPage' => null !== $nextCursor, 'endCursor' => $nextCursor],
            'nodes'    => $nodes,
        ],
    ]]]];
}

/**
 * Fake the token exchange plus a GraphQL endpoint answering in turn.
 *
 * @param  list<mixed>  $graphql
 */
function fakeGitHubGraphql(array $graphql): void
{
    $sequence = Http::sequence();

    foreach ($graphql as $response) {
        $sequence->push($response);
    }

    Http::fake([
        'api.github.com/app/installations/*' => Http::response(['token' => 'ghs_1', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/graphql'             => $sequence,
    ]);
}

function boardPackage(string $title, ?string $repo): Package
{
    return Package::query()->create(['title' => $title, 'github_repo' => $repo]);
}

describe('project reader', function (): void {
    it('pages through the project and maps each repo to its package', function (): void {
        $a11y = boardPackage('Accessibility', 'ArtisanPack-UI/accessibility');
        fakeGitHubGraphql([
            projectPage([projectIssue('PVTI_1', 'ArtisanPack-UI/accessibility', 1)], 'cursor-1'),
            projectPage([projectIssue('PVTI_2', 'ArtisanPack-UI/unmapped', 2, null)]),
        ]);

        $board = app(ProjectBoardReader::class)->read()->toArray();

        expect($board['project'])->toBe(['title' => 'ArtisanPack UI', 'url' => 'https://github.com/orgs/ArtisanPack-UI/projects/7', 'number' => 7])
            ->and(array_column($board['columns'], 'id'))->toBe([null, 'opt-todo', 'opt-doing', 'opt-done'])
            ->and(array_column($board['items'], 'id'))->toBe(['PVTI_1', 'PVTI_2'])
            ->and($board['items'][0])->toMatchArray([
                'statusId'  => 'opt-todo',
                'repo'      => 'ArtisanPack-UI/accessibility',
                'number'    => 1,
                'title'     => 'Issue 1',
                'state'     => 'OPEN',
                'labels'    => [['name' => 'bug', 'color' => 'd73a4a']],
                'milestone' => ['number' => 1, 'title' => 'v1.0'],
                'assignees' => [['login' => 'octocat', 'avatarUrl' => 'https://avatars.example/octocat']],
                'package'   => ['id' => $a11y->id, 'title' => 'Accessibility'],
            ])
            ->and($board['items'][1]['statusId'])->toBeNull()
            ->and($board['items'][1]['package'])->toBeNull()
            ->and($board['milestones'])->toBe(['v1.0'])
            ->and($board['packages'])->toBe([['id' => $a11y->id, 'title' => 'Accessibility']]);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/graphql')
            && ['login' => 'ArtisanPack-UI', 'number' => 7, 'cursor' => 'cursor-1'] === (array) $request['variables']);
    });

    it('matches repos case-insensitively', function (): void {
        $package = boardPackage('Accessibility', 'artisanpack-ui/Accessibility');
        fakeGitHubGraphql([projectPage([projectIssue('PVTI_1', 'ArtisanPack-UI/accessibility', 1)])]);

        expect(app(ProjectBoardReader::class)->read()->items[0]->package)->toBe(['id' => $package->id, 'title' => 'Accessibility']);
    });

    it('leaves out pull requests, draft issues and archived items', function (): void {
        fakeGitHubGraphql([projectPage([
            projectIssue('PVTI_issue', 'ArtisanPack-UI/a', 1),
            projectIssue('PVTI_archived', 'ArtisanPack-UI/a', 2, overrides: ['isArchived' => true]),
            ['id' => 'PVTI_pr', 'isArchived' => false, 'fieldValueByName' => null, 'content' => ['__typename' => 'PullRequest']],
            ['id' => 'PVTI_draft', 'isArchived' => false, 'fieldValueByName' => null, 'content' => ['__typename' => 'DraftIssue']],
        ])]);

        expect(array_map(fn ($item) => $item->id, app(ProjectBoardReader::class)->read()->items))->toBe(['PVTI_issue']);
    });

    it('refuses a project without a single-select Status field', function (): void {
        $page                                               = projectPage([]);
        $page['data']['organization']['projectV2']['field'] = null;
        fakeGitHubGraphql([$page]);

        $this->getJson('/admin/artisanpack-ui/board')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The org project has no single-select "Status" field to use as the board columns.');
    });

    it('says when the project number isn\'t saved', function (): void {
        IntegrationSettings::current()->update(['github_project_number' => null]);

        $this->getJson('/admin/artisanpack-ui/board')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'project number'));

        Http::assertNothingSent();
    });

    it('answers 404 when the project can\'t be found', function (): void {
        fakeGitHubGraphql([['data' => ['organization' => ['projectV2' => null]]]]);

        $this->getJson('/admin/artisanpack-ui/board')->assertNotFound();
    });

    it('passes a GitHub rate limit on as 429', function (): void {
        Http::fake([
            'api.github.com/app/installations/*' => Http::response(['token' => 'ghs_1', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            'api.github.com/graphql'             => Http::response(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (now()->getTimestamp() + 120)]),
        ]);

        $this->getJson('/admin/artisanpack-ui/board')->assertStatus(429)->assertHeader('Retry-After');
    });
});

describe('board endpoints', function (): void {
    it('returns the global board', function (): void {
        boardPackage('Accessibility', 'ArtisanPack-UI/accessibility');
        fakeGitHubGraphql([projectPage([
            projectIssue('PVTI_1', 'ArtisanPack-UI/accessibility', 1),
            projectIssue('PVTI_2', 'ArtisanPack-UI/icons', 2),
        ])]);

        $this->getJson('/admin/artisanpack-ui/board')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonCount(4, 'columns')
            ->assertJsonPath('columns.0.name', 'No status');
    });

    it('scopes a package\'s board to its repo', function (): void {
        $package = boardPackage('Accessibility', 'ArtisanPack-UI/accessibility');
        fakeGitHubGraphql([projectPage([
            projectIssue('PVTI_1', 'ArtisanPack-UI/accessibility', 1),
            projectIssue('PVTI_2', 'ArtisanPack-UI/icons', 2, overrides: ['content' => [
                ...projectIssue('x', 'ArtisanPack-UI/icons', 2)['content'],
                'milestone' => ['number' => 3, 'title' => 'v3.0'],
            ]]),
        ])]);

        $this->getJson("/admin/artisanpack-ui/packages/{$package->id}/board")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', 'PVTI_1')
            ->assertJsonPath('milestones', ['v1.0']);
    });

    it('asks for a GitHub repo before reading a package\'s board', function (): void {
        $package = boardPackage('No repo', null);

        $this->getJson("/admin/artisanpack-ui/packages/{$package->id}/board")
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no GitHub repo'));

        Http::assertNothingSent();
    });

    it('shares the board endpoint templates with every admin page', function (): void {
        $this->get('/admin/artisanpack-ui')->assertInertia(fn (Assert $page) => $page
            ->where('artisanpackUi.endpoints.board.index', url('/admin/artisanpack-ui/board'))
            ->where('artisanpackUi.endpoints.board.move', url('/admin/artisanpack-ui/board/items/__item__/status'))
            ->where('artisanpackUi.endpoints.board.issue', url('/admin/artisanpack-ui/issues/__repo__/__issue__'))
            ->where('artisanpackUi.endpoints.board.comments', url('/admin/artisanpack-ui/issues/__repo__/__issue__/comments'))
            ->where('artisanpackUi.endpoints.board.options', url('/admin/artisanpack-ui/issues/__repo__/options'))
            ->where('artisanpackUi.endpoints.package.board', url('/admin/artisanpack-ui/packages/__package__/board')));
    });
});

describe('moving a card', function (): void {
    it('sets the item\'s Status option', function (): void {
        fakeGitHubGraphql([
            projectPage([]),
            ['data' => ['updateProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PVTI_1']]]],
        ]);

        $this->putJson('/admin/artisanpack-ui/board/items/PVTI_1/status', ['status' => 'opt-doing'])
            ->assertOk()
            ->assertJson(['id' => 'PVTI_1', 'statusId' => 'opt-doing']);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/graphql')
            && str_contains((string) ($request->data()['query'] ?? ''), 'updateProjectV2ItemFieldValue')
            && ['project' => 'PVT_project', 'item' => 'PVTI_1', 'field' => 'PVTSSF_status', 'option' => 'opt-doing'] === (array) $request['variables']);
    });

    it('clears the Status for the "No status" column', function (): void {
        fakeGitHubGraphql([
            projectPage([]),
            ['data' => ['clearProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PVTI_1']]]],
        ]);

        $this->putJson('/admin/artisanpack-ui/board/items/PVTI_1/status', ['status' => null])->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['query'] ?? ''), 'clearProjectV2ItemFieldValue')
            && ['project' => 'PVT_project', 'item' => 'PVTI_1', 'field' => 'PVTSSF_status'] === (array) $request['variables']);
    });

    it('reuses the Status field a board load cached', function (): void {
        fakeGitHubGraphql([
            projectPage([]),
            ['data' => ['updateProjectV2ItemFieldValue' => ['projectV2Item' => ['id' => 'PVTI_1']]]],
        ]);

        $this->getJson('/admin/artisanpack-ui/board')->assertOk();
        $this->putJson('/admin/artisanpack-ui/board/items/PVTI_1/status', ['status' => 'opt-done'])->assertOk();

        Http::assertSentCount(3);
    });

    it('refuses an option that isn\'t on the project, after checking fresh options', function (): void {
        fakeGitHubGraphql([projectPage([]), projectPage([])]);

        $this->putJson('/admin/artisanpack-ui/board/items/PVTI_1/status', ['status' => 'opt-gone'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That column no longer exists on the project. Reload the board.');

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) ($request->data()['query'] ?? ''), 'mutation'));
    });

    it('reports a refused move so the board can roll it back', function (): void {
        fakeGitHubGraphql([
            projectPage([]),
            ['errors' => [['message' => 'Could not resolve to a node with the global id of \'PVTI_x\'']]],
        ]);

        $this->putJson('/admin/artisanpack-ui/board/items/PVTI_x/status', ['status' => 'opt-done'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Could not resolve'));
    });

    it('requires the status key', function (): void {
        $this->putJson('/admin/artisanpack-ui/board/items/PVTI_1/status', [])->assertJsonValidationErrors('status');
    });

    it('only accepts project item ids in the URL', function (): void {
        $this->putJson('/admin/artisanpack-ui/board/items/not%20an%20id/status', ['status' => null])->assertNotFound();
    });
});

describe('dashboard board widget', function (): void {
    beforeEach(function (): void {
        $this->a11y  = boardPackage('Accessibility', 'ArtisanPack-UI/accessibility');
        $this->icons = boardPackage('Icons', 'ArtisanPack-UI/icons');

        fakeGitHubGraphql([projectPage([
            projectIssue('PVTI_1', 'ArtisanPack-UI/accessibility', 1, 'opt-todo'),
            projectIssue('PVTI_2', 'ArtisanPack-UI/accessibility', 2, 'opt-doing'),
            projectIssue('PVTI_3', 'ArtisanPack-UI/icons', 3, 'opt-todo'),
            projectIssue('PVTI_4', 'ArtisanPack-UI/unmapped', 4, null),
        ])]);
    });

    it('counts every card per Status column', function (): void {
        $summary = app(BoardSummary::class)->summarize([]);

        expect($summary['columns'])->toBe([
            ['id' => null, 'name' => 'No status', 'count' => 1],
            ['id' => 'opt-todo', 'name' => 'Todo', 'count' => 2],
            ['id' => 'opt-doing', 'name' => 'In progress', 'count' => 1],
            ['id' => 'opt-done', 'name' => 'Done', 'count' => 0],
        ])
            ->and($summary['total'])->toBe(4)
            ->and($summary['packages'])->toBe([])
            ->and($summary['project'])->toBe(['title' => 'ArtisanPack UI', 'url' => 'https://github.com/orgs/ArtisanPack-UI/projects/7'])
            ->and($summary['boardUrl'])->toBe(url('/admin/artisanpack-ui'))
            ->and($summary['error'])->toBeNull();
    });

    it('counts only the chosen packages and links to the board filtered to them', function (): void {
        $summary = app(BoardSummary::class)->summarize([$this->icons->id, $this->a11y->id]);

        expect(array_column($summary['columns'], 'count'))->toBe([0, 2, 1, 0])
            ->and($summary['total'])->toBe(3)
            ->and($summary['packages'])->toBe([
                ['id' => $this->a11y->id, 'title' => 'Accessibility'],
                ['id' => $this->icons->id, 'title' => 'Icons'],
            ])
            ->and($summary['boardUrl'])->toBe(url('/admin/artisanpack-ui') . '?packages=' . urlencode($this->a11y->id . ',' . $this->icons->id));
    });

    it('counts zero, not every card, for a chosen package with no issues on the project', function (): void {
        $docs = boardPackage('Docs', 'ArtisanPack-UI/docs');

        $summary = app(BoardSummary::class)->summarize([$docs->id]);

        expect($summary['total'])->toBe(0)
            ->and($summary['packages'])->toBe([['id' => $docs->id, 'title' => 'Docs']])
            ->and($summary['boardUrl'])->toBe(url('/admin/artisanpack-ui') . '?packages=' . $docs->id);
    });

    it('counts every card when none of the chosen packages are left', function (): void {
        $summary = app(BoardSummary::class)->summarize([999]);

        expect($summary['total'])->toBe(4)
            ->and($summary['packages'])->toBe([])
            ->and($summary['boardUrl'])->toBe(url('/admin/artisanpack-ui'));
    });

    it('reads the project once for every widget within the cache window', function (): void {
        app(BoardSummary::class)->summarize([]);
        app(BoardSummary::class)->summarize([$this->icons->id]);

        Http::assertSentCount(2);
    });

    it('reports a GitHub failure instead of throwing', function (): void {
        IntegrationSettings::current()->update(['github_project_number' => null]);

        $summary = app(BoardSummary::class)->summarize([]);

        expect($summary['error'])->toContain('project number')
            ->and($summary['columns'])->toBe([])
            ->and($summary['total'])->toBe(0);
    });
});

it('refuses the board endpoints without the issues permission', function (string $method, string $uri): void {
    actingAsUserWith([Permissions::SYNC, Permissions::STATS_VIEW]);
    $package = boardPackage('Accessibility', 'ArtisanPack-UI/accessibility');

    $this->json($method, str_replace('{package}', (string) $package->id, $uri), ['status' => null])->assertForbidden();

    Http::assertNothingSent();
})->with([
    'global board'  => ['GET', '/admin/artisanpack-ui/board'],
    'package board' => ['GET', '/admin/artisanpack-ui/packages/{package}/board'],
    'move'          => ['PUT', '/admin/artisanpack-ui/board/items/PVTI_1/status'],
]);
