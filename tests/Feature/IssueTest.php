<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Services\GitHub\GitHubIssues;
use ArtisanPackUI\Site\Support\Permissions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The board's issue modal (roadmap 5.3): reading, editing and commenting
 * on a GitHub issue in the org.
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

    primeProjectCache();
});

/**
 * Cache what a board load leaves behind for the org: the project's Status
 * field and the repos with issues on it.
 *
 * @param  list<string>  $repositories
 */
function primeProjectCache(string $org = 'ArtisanPack-UI', array $repositories = ['accessibility']): void
{
    $key = sha1(strtolower($org) . '|7');

    Cache::put('artisanpack-ui:board:status-field:' . $key, ['projectId' => 'PVT_project', 'fieldId' => 'PVTSSF_status', 'options' => []], 600);
    Cache::put('artisanpack-ui:board:repositories:' . $key, array_map(fn (string $repo): string => strtolower("{$org}/{$repo}"), $repositories), 600);
}

/**
 * GitHub's GraphQL answer for an issue's project items.
 *
 * @param  list<string>|null  $projects  The project ids the issue is on, or null for a pull request's number.
 *
 * @return array<string, mixed>
 */
function projectMembership(?array $projects = ['PVT_project']): array
{
    return ['data' => ['repository' => ['issue' => null === $projects ? null : ['projectItems' => ['nodes' => array_map(
        fn (string $id): array => ['project' => ['id' => $id]],
        $projects,
    )]]]]];
}

/**
 * GitHub's REST representation of an issue.
 *
 * @return array<string, mixed>
 */
function restIssue(array $overrides = []): array
{
    return [
        'number'       => 12,
        'title'        => 'Focus ring missing',
        'body'         => "Steps:\n\n- open the menu\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1))",
        'html_url'     => 'https://github.com/ArtisanPack-UI/accessibility/issues/12',
        'state'        => 'open',
        'state_reason' => null,
        'labels'       => [['name' => 'bug', 'color' => 'd73a4a']],
        'milestone'    => ['number' => 2, 'title' => 'v2.5'],
        'assignees'    => [['login' => 'octocat', 'avatar_url' => 'https://avatars.example/octocat']],
        'user'         => ['login' => 'reporter', 'avatar_url' => null],
        'created_at'   => '2026-09-01T10:00:00Z',
        'updated_at'   => '2026-10-01T10:00:00Z',
        'closed_at'    => null,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $routes
 */
function fakeGitHubRest(array $routes): void
{
    Http::fake([
        'api.github.com/app/installations/*' => Http::response(['token' => 'ghs_1', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/graphql'             => Http::response(projectMembership()),
        ...$routes,
    ]);
}

it('shows an issue with its markdown rendered safely and its comments', function (): void {
    fakeGitHubRest([
        'api.github.com/repos/ArtisanPack-UI/accessibility/issues/12/comments*' => Http::response([
            ['id' => 99, 'user' => ['login' => 'maintainer', 'avatar_url' => null], 'body' => 'Thanks, **confirmed**.', 'created_at' => '2026-10-02T10:00:00Z', 'html_url' => 'https://github.com/c/99'],
        ]),
        'api.github.com/repos/ArtisanPack-UI/accessibility/issues/12' => Http::response(restIssue()),
    ]);

    $response = $this->getJson('/admin/artisanpack-ui/issues/accessibility/12')
        ->assertOk()
        ->assertJsonPath('repo', 'ArtisanPack-UI/accessibility')
        ->assertJsonPath('title', 'Focus ring missing')
        ->assertJsonPath('state', 'OPEN')
        ->assertJsonPath('labels', [['name' => 'bug', 'color' => 'd73a4a']])
        ->assertJsonPath('milestone', ['number' => 2, 'title' => 'v2.5'])
        ->assertJsonPath('assignees', [['login' => 'octocat', 'avatarUrl' => 'https://avatars.example/octocat']])
        ->assertJsonPath('comments.0.author.login', 'maintainer')
        ->assertJsonPath('comments.0.bodyHtml', "<p>Thanks, <strong>confirmed</strong>.</p>\n");

    $html = $response->json('bodyHtml');

    expect($html)->toContain('<li>open the menu</li>')
        ->not->toContain('<script>')
        ->toContain('&lt;script&gt;')
        ->toContain('<a>bad</a>')
        ->not->toContain('href="javascript:');
});

it('renders images as links and opens links in a new tab', function (): void {
    $html = GitHubIssues::renderMarkdown("![x](https://evil.example/p.png) and ![](https://evil.example/q.png)\n\n[y](https://example.com)\n\n<script>alert(1)</script>");

    expect($html)->not->toContain('<img')
        ->toContain('href="https://evil.example/p.png"')
        ->toContain('>x</a>')
        ->toContain('>image</a>')
        ->toContain('target="_blank"')
        ->toMatch('/<a rel="[^"]*\bnoopener\b[^"]*" target="_blank" href="https:\/\/example\.com">y<\/a>/')
        ->toMatch('/<a rel="[^"]*\bnoreferrer\b[^"]*" target="_blank" href="https:\/\/example\.com">y<\/a>/')
        ->not->toContain('<script>')
        ->toContain('&lt;script&gt;');
});

it('sends only the fields that changed', function (): void {
    fakeGitHubRest([
        'api.github.com/repos/ArtisanPack-UI/accessibility/issues/12' => Http::response(restIssue([
            'title'     => 'Focus ring missing on menus',
            'labels'    => [],
            'milestone' => null,
        ])),
    ]);

    $this->patchJson('/admin/artisanpack-ui/issues/accessibility/12', [
        'title'     => 'Focus ring missing on menus',
        'labels'    => [],
        'milestone' => null,
    ])
        ->assertOk()
        ->assertJsonPath('title', 'Focus ring missing on menus')
        ->assertJsonPath('milestone', null)
        ->assertJsonMissingPath('comments');

    Http::assertSent(fn (Request $request): bool => 'PATCH' === $request->method()
        && ['title' => 'Focus ring missing on menus', 'labels' => [], 'milestone' => null] === $request->data());
});

it('closes and reopens an issue', function (string $state, ?string $reason): void {
    fakeGitHubRest([
        'api.github.com/repos/ArtisanPack-UI/accessibility/issues/12' => Http::response(restIssue(['state' => $state, 'state_reason' => $reason])),
    ]);

    $this->patchJson('/admin/artisanpack-ui/issues/accessibility/12', ['state' => $state, 'state_reason' => $reason])
        ->assertOk()
        ->assertJsonPath('state', strtoupper($state))
        ->assertJsonPath('stateReason', $reason);

    Http::assertSent(fn (Request $request): bool => 'PATCH' === $request->method()
        && ['state' => $state, 'state_reason' => $reason] === $request->data());
})->with([
    'close'  => ['closed', 'completed'],
    'reopen' => ['open', 'reopened'],
]);

it('validates an edit', function (array $payload, string $field): void {
    $this->patchJson('/admin/artisanpack-ui/issues/accessibility/12', $payload)->assertJsonValidationErrors($field);

    Http::assertNothingSent();
})->with([
    'empty title'      => [['title' => ''], 'title'],
    'long title'       => [['title' => str_repeat('a', 257)], 'title'],
    'bad state'        => [['state' => 'merged'], 'state'],
    'bad milestone'    => [['milestone' => 0], 'milestone'],
    'too many owners'  => [['assignees' => array_map(fn (int $i): string => "user{$i}", range(1, 11))], 'assignees'],
    'bad login'        => [['assignees' => ['not a login']], 'assignees.0'],
    'long label'       => [['labels' => [str_repeat('l', 51)]], 'labels.0'],
]);

it('refuses an empty edit', function (): void {
    $this->patchJson('/admin/artisanpack-ui/issues/accessibility/12', [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Nothing to change.');

    Http::assertNothingSent();
});

it('adds a comment', function (): void {
    fakeGitHubRest([
        'api.github.com/repos/ArtisanPack-UI/accessibility/issues/12/comments' => Http::response([
            'id' => 100, 'user' => ['login' => 'artisanpack-ui[bot]', 'avatar_url' => null], 'body' => 'On it.', 'created_at' => '2026-10-07T10:00:00Z', 'html_url' => 'https://github.com/c/100',
        ], 201),
    ]);

    $this->postJson('/admin/artisanpack-ui/issues/accessibility/12/comments', ['body' => 'On it.'])
        ->assertCreated()
        ->assertJsonPath('id', 100)
        ->assertJsonPath('author.login', 'artisanpack-ui[bot]')
        ->assertJsonPath('bodyHtml', "<p>On it.</p>\n");

    Http::assertSent(fn (Request $request): bool => 'POST' === $request->method() && ['body' => 'On it.'] === $request->data());
});

it('requires a comment body', function (): void {
    $this->postJson('/admin/artisanpack-ui/issues/accessibility/12/comments', ['body' => ''])->assertJsonValidationErrors('body');
});

it('lists the repo\'s labels, open milestones and assignable users', function (): void {
    fakeGitHubRest([
        'api.github.com/repos/ArtisanPack-UI/accessibility/labels*'     => Http::response([['name' => 'bug', 'color' => 'd73a4a', 'id' => 1]]),
        'api.github.com/repos/ArtisanPack-UI/accessibility/milestones*' => Http::response([['number' => 2, 'title' => 'v2.5', 'state' => 'open']]),
        'api.github.com/repos/ArtisanPack-UI/accessibility/assignees*'  => Http::response([['login' => 'octocat', 'avatar_url' => 'https://avatars.example/octocat']]),
    ]);

    $this->getJson('/admin/artisanpack-ui/issues/accessibility/options')
        ->assertOk()
        ->assertExactJson([
            'labels'     => [['name' => 'bug', 'color' => 'd73a4a']],
            'milestones' => [['number' => 2, 'title' => 'v2.5']],
            'assignees'  => [['login' => 'octocat', 'avatarUrl' => 'https://avatars.example/octocat']],
        ]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/milestones') && 'open' === $request['state']);
});

it('stays inside the configured org', function (): void {
    IntegrationSettings::current()->update(['github_organization' => 'Other-Org']);
    primeProjectCache('Other-Org');
    fakeGitHubRest([
        'api.github.com/repos/Other-Org/accessibility/issues/12/comments*' => Http::response([]),
        'api.github.com/repos/Other-Org/accessibility/issues/12'           => Http::response(restIssue()),
    ]);

    $this->getJson('/admin/artisanpack-ui/issues/accessibility/12')->assertOk()->assertJsonPath('repo', 'Other-Org/accessibility');
});

it('refuses repo names that could leave the org\'s repos', function (string $repo): void {
    $this->getJson('/admin/artisanpack-ui/issues/' . $repo . '/12')->assertStatus(422);

    Http::assertNothingSent();
})->with(['..', '.']);

it('treats an over-long issue number as not found', function (): void {
    $this->getJson('/admin/artisanpack-ui/issues/accessibility/99999999999999999999')->assertNotFound();

    Http::assertNothingSent();
});

it('answers 404 when GitHub can\'t find the issue', function (): void {
    fakeGitHubRest([
        'api.github.com/repos/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $this->getJson('/admin/artisanpack-ui/issues/accessibility/999')->assertNotFound();
});

it('refuses an issue that isn\'t on the org project', function (string $method, string $uri, ?array $projects): void {
    fakeGitHubRest([
        'api.github.com/graphql'  => Http::response(projectMembership($projects)),
        'api.github.com/repos/*'  => Http::response(restIssue()),
    ]);

    $this->json($method, $uri, ['body' => 'x', 'title' => 'x'])->assertNotFound();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/repos/'));
})->with([
    'edit a pull request'           => ['PATCH', '/admin/artisanpack-ui/issues/accessibility/12', null],
    'edit another project\'s issue' => ['PATCH', '/admin/artisanpack-ui/issues/accessibility/12', ['PVT_other']],
    'show another project\'s issue' => ['GET', '/admin/artisanpack-ui/issues/accessibility/12', ['PVT_other']],
    'comment on a pull request'     => ['POST', '/admin/artisanpack-ui/issues/accessibility/12/comments', null],
]);

it('remembers that an issue is on the project', function (): void {
    fakeGitHubRest([
        'api.github.com/repos/ArtisanPack-UI/accessibility/issues/12' => Http::response(restIssue()),
    ]);

    $this->patchJson('/admin/artisanpack-ui/issues/accessibility/12', ['title' => 'One'])->assertOk();
    $this->patchJson('/admin/artisanpack-ui/issues/accessibility/12', ['title' => 'Two'])->assertOk();

    Http::assertSentCount(4);
});

it('refuses the edit options for a repo with no issues on the project', function (): void {
    fakeGitHubRest([]);

    $this->getJson('/admin/artisanpack-ui/issues/private-repo/options')->assertNotFound();

    Http::assertNothingSent();
});

it('refuses the issue endpoints without the issues permission', function (string $method, string $uri): void {
    actingAsUserWith([Permissions::SYNC, Permissions::STATS_VIEW]);

    $this->json($method, $uri, ['body' => 'x', 'title' => 'x'])->assertForbidden();

    Http::assertNothingSent();
})->with([
    'show'    => ['GET', '/admin/artisanpack-ui/issues/accessibility/12'],
    'update'  => ['PATCH', '/admin/artisanpack-ui/issues/accessibility/12'],
    'comment' => ['POST', '/admin/artisanpack-ui/issues/accessibility/12/comments'],
    'options' => ['GET', '/admin/artisanpack-ui/issues/accessibility/options'],
]);
