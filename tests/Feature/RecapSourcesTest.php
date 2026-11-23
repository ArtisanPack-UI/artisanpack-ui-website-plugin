<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Exceptions\GitHubRateLimitException;
use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Services\Recap\GitHubLinkReader;
use ArtisanPackUI\Site\Services\Recap\GitHubRepository;
use ArtisanPackUI\Site\Services\Recap\MergedPullRequestSearch;
use ArtisanPackUI\Site\Services\Recap\PublicUrlReader;
use ArtisanPackUI\Site\Services\Recap\RecapPosts;
use ArtisanPackUI\Site\Services\Recap\RecapReleaseCollector;
use ArtisanPackUI\Site\Services\Recap\RecapWindow;
use ArtisanPackUI\Site\Services\Recap\RecapWindowResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The weekly recap's sources (#60): the window, the existing recap posts,
 * the week's releases and merged pull requests, and the links in the notes.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));

    IntegrationSettings::create([
        'github_app_id'          => '123',
        'github_installation_id' => '456',
        'github_private_key'     => testPrivateKey(),
    ]);
});

/**
 * The GitHub App token exchange, faked, plus the given routes.
 *
 * @param  array<string, mixed>  $routes
 */
function fakeGitHub(array $routes = []): void
{
    Http::fake([
        'api.github.com/app/installations/*' => Http::response(['token' => 'ghs_1', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        ...$routes,
    ]);
}

/**
 * A GitHub release as the REST API lists it.
 *
 * @return array<string, mixed>
 */
function gitHubRelease(string $tag, string $publishedAt, array $overrides = []): array
{
    return [
        'tag_name'     => $tag,
        'name'         => $tag,
        'html_url'     => 'https://github.com/ArtisanPack-UI/forms/releases/tag/' . $tag,
        'published_at' => $publishedAt,
        'draft'        => false,
        'prerelease'   => false,
        'body'         => 'Notes for ' . $tag,
        ...$overrides,
    ];
}

describe('recap window', function (): void {
    it('starts at the previous recap and ends with the publish date', function (): void {
        recapPost('This Week in ArtisanPack UI: November 14, 2026', '2026-11-14 10:00:00');
        recapPost('This Week in ArtisanPack UI: November 7, 2026', '2026-11-07 10:00:00');

        $window = app(RecapWindowResolver::class)->for(CarbonImmutable::parse('2026-11-21'));

        expect($window->start->toDateTimeString())->toBe('2026-11-14 10:00:00')
            ->and($window->end->toDateTimeString())->toBe('2026-11-21 23:59:59')
            ->and($window->previousRecap['title'])->toBe('This Week in ArtisanPack UI: November 14, 2026');
    });

    it('ignores posts that aren\'t published recaps', function (): void {
        recapPost('This Week in ArtisanPack UI: November 14, 2026', '2026-11-14 10:00:00', ['status' => 'draft']);
        recapPost('This Week in ArtisanPack UI: November 13, 2026', '2026-11-13 10:00:00', ['deleted_at' => now()]);
        recapPost('This Week in ArtisanPack UI: November 12, 2026', '2026-11-12 10:00:00', [], 'news');
        recapPost('A different post', '2026-11-11 10:00:00');
        recapPost('This Week in ArtisanPack UI: November 21, 2026', '2026-11-21 10:00:00');

        $window = app(RecapWindowResolver::class)->for(CarbonImmutable::parse('2026-11-21'));

        expect($window->previousRecap)->toBeNull()
            ->and($window->start->toDateTimeString())->toBe('2026-11-14 00:00:00');
    });

    it('recognises recaps by the saved title pattern', function (): void {
        IntegrationSettings::current()->update(['recap_title_pattern' => 'Weekly Roundup – {date}']);
        recapPost('Weekly Roundup – November 14, 2026', '2026-11-14 10:00:00');

        $window = app(RecapWindowResolver::class)->for(CarbonImmutable::parse('2026-11-21'));

        expect($window->previousRecap['title'])->toBe('Weekly Roundup – November 14, 2026');
    });

    it('matches the title prefix literally', function (): void {
        IntegrationSettings::current()->update(['recap_title_pattern' => 'This_Week: {date}']);
        recapPost('This_Week: November 7, 2026', '2026-11-07 10:00:00');
        recapPost('This Week in Review', '2026-11-14 10:00:00');

        $window = app(RecapWindowResolver::class)->for(CarbonImmutable::parse('2026-11-21'));

        expect($window->previousRecap['title'])->toBe('This_Week: November 7, 2026');
    });

    it('reads the latest published recaps as plain text style examples', function (): void {
        recapPost('This Week in ArtisanPack UI: November 14, 2026', '2026-11-14 10:00:00', ['block_content' => json_encode([
            ['name' => 'artisanpack/heading', 'attributes' => ['content' => 'Forms <em>1.6</em>'], 'innerBlocks' => []],
            ['name' => 'artisanpack/paragraph', 'attributes' => ['content' => 'A big week &amp; more.'], 'innerBlocks' => []],
            ['name' => 'artisanpack/list', 'attributes' => [], 'innerBlocks' => [
                ['name' => 'artisanpack/list-item', 'attributes' => ['content' => 'One'], 'innerBlocks' => []],
            ]],
        ])]);

        expect(app(RecapPosts::class)->styleExamples())->toBe([[
            'title' => 'This Week in ArtisanPack UI: November 14, 2026',
            'text'  => "## Forms 1.6\n\nA big week & more.\n\n- One",
        ]]);
    });
});

describe('releases', function (): void {
    beforeEach(function (): void {
        $this->window = new RecapWindow(CarbonImmutable::parse('2026-11-14 10:00:00'), CarbonImmutable::parse('2026-11-21 23:59:59'));
    });

    it('collects the stable releases inside the window', function (): void {
        Package::query()->create(['title' => 'Forms', 'github_repo' => 'ArtisanPack-UI/forms']);
        Package::query()->create(['title' => 'Docs only']);

        fakeGitHub(['api.github.com/repos/ArtisanPack-UI/forms/releases*' => Http::response([
            gitHubRelease('v1.7.0-beta.1', '2026-11-19T12:00:00Z', ['prerelease' => true]),
            gitHubRelease('v1.6.1', '2026-11-18T12:00:00Z'),
            gitHubRelease('v1.6.0-draft', '2026-11-17T12:00:00Z', ['draft' => true]),
            gitHubRelease('v1.6.0', '2026-11-15T12:00:00Z'),
            gitHubRelease('v1.5.0', '2026-11-10T12:00:00Z'),
        ])]);

        $result = app(RecapReleaseCollector::class)->collect($this->window);

        expect(array_column($result['releases'], 'tag'))->toBe(['v1.6.0', 'v1.6.1'])
            ->and($result['releases'][0])->toMatchArray(['package' => 'Forms', 'repo' => 'ArtisanPack-UI/forms', 'notes' => 'Notes for v1.6.0'])
            ->and($result['warnings'])->toBe([]);
    });

    it('stops paging once a page reaches releases older than the window', function (): void {
        Package::query()->create(['title' => 'Forms', 'github_repo' => 'forms']);

        $fullPage = array_map(
            static fn (int $day): array => gitHubRelease('v1.' . $day, sprintf('2026-11-%02dT08:00:00Z', 21 - intdiv($day, 5))),
            range(0, RecapReleaseCollector::PER_PAGE - 1),
        );

        fakeGitHub(['api.github.com/repos/ArtisanPack-UI/forms/releases*' => Http::sequence()
            ->push($fullPage)
            ->push([gitHubRelease('v0.9', '2026-11-01T08:00:00Z')])]);

        app(RecapReleaseCollector::class)->collect($this->window);

        Http::assertSentCount(3);
    });

    it('reports a repository GitHub won\'t list and carries on', function (): void {
        Package::query()->create(['title' => 'Gone', 'github_repo' => 'https://github.com/ArtisanPack-UI/gone.git']);
        Package::query()->create(['title' => 'Forms', 'github_repo' => 'ArtisanPack-UI/forms']);

        fakeGitHub([
            'api.github.com/repos/ArtisanPack-UI/gone/releases*'  => Http::response(['message' => 'Not Found'], 404),
            'api.github.com/repos/ArtisanPack-UI/forms/releases*' => Http::response([gitHubRelease('v1.6.0', '2026-11-15T12:00:00Z')]),
        ]);

        $result = app(RecapReleaseCollector::class)->collect($this->window);

        expect($result['releases'])->toHaveCount(1)
            ->and($result['warnings'])->toHaveCount(1)
            ->and($result['warnings'][0])->toContain('ArtisanPack-UI/gone');
    });

    it('stops at a spent rate limit', function (): void {
        Package::query()->create(['title' => 'Forms', 'github_repo' => 'ArtisanPack-UI/forms']);

        fakeGitHub(['api.github.com/repos/*' => Http::response(['message' => 'API rate limit exceeded'], 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset'     => (string) now()->addMinutes(5)->timestamp,
        ])]);

        app(RecapReleaseCollector::class)->collect($this->window);
    })->throws(GitHubRateLimitException::class);

    it('normalizes the repository a package names', function (?string $value, ?string $expected): void {
        expect(GitHubRepository::normalize($value, 'ArtisanPack-UI'))->toBe($expected);
    })->with([
        ['ArtisanPack-UI/forms', 'ArtisanPack-UI/forms'],
        ['forms', 'ArtisanPack-UI/forms'],
        ['https://github.com/ArtisanPack-UI/forms.git', 'ArtisanPack-UI/forms'],
        ['github.com/Someone/thing/tree/main', 'Someone/thing'],
        ['not a repo!', null],
        [null, null],
    ]);
});

describe('merged pull requests', function (): void {
    it('searches the org for pull requests merged in the window', function (): void {
        fakeGitHub(['api.github.com/search/issues*' => Http::response(['items' => [[
            'repository_url' => 'https://api.github.com/repos/ArtisanPack-UI/forms',
            'number'         => 42,
            'title'          => 'Add file uploads',
            'html_url'       => 'https://github.com/ArtisanPack-UI/forms/pull/42',
            'labels'         => [['name' => 'enhancement']],
            'closed_at'      => '2026-11-16T10:00:00Z',
            'body'           => 'Adds uploads.',
        ]]])]);

        $window = new RecapWindow(CarbonImmutable::parse('2026-11-14 10:00:00', 'UTC'), CarbonImmutable::parse('2026-11-21 23:59:59', 'UTC'));
        $result = app(MergedPullRequestSearch::class)->search($window, 'uploads "OR is:issue"', 'forms');

        expect($result)->toBe([[
            'repo'     => 'ArtisanPack-UI/forms',
            'number'   => 42,
            'title'    => 'Add file uploads',
            'url'      => 'https://github.com/ArtisanPack-UI/forms/pull/42',
            'labels'   => ['enhancement'],
            'closedAt' => '2026-11-16T10:00:00Z',
            'body'     => 'Adds uploads.',
        ]]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/search/issues')
            && 'repo:ArtisanPack-UI/forms is:pr is:merged merged:2026-11-14T10:00:00Z..2026-11-21T23:59:59Z uploads OR is issue' === $request['q']);
    });
});

describe('links in the notes', function (): void {
    it('reads a pull request through the GitHub App', function (): void {
        fakeGitHub([
            'api.github.com/repos/ArtisanPack-UI/forms/pulls/42/files*' => Http::response([['filename' => 'src/Upload.php', 'additions' => 10, 'deletions' => 2]]),
            'api.github.com/repos/ArtisanPack-UI/forms/pulls/42'        => Http::response([
                'title'     => 'Add file uploads',
                'merged_at' => '2026-11-16T10:00:00Z',
                'body'      => 'Adds uploads.',
                'labels'    => [['name' => 'enhancement']],
            ]),
        ]);

        $item = app(GitHubLinkReader::class)->read('https://github.com/ArtisanPack-UI/forms/pull/42');

        expect($item['title'])->toBe('Add file uploads')
            ->and($item['kind'])->toBe('pull_request')
            ->and($item['text'])->toContain('merged 2026-11-16T10:00:00Z')
            ->and($item['text'])->toContain('- src/Upload.php (+10 -2)');
    });

    it('reads a release whose tag contains slashes', function (): void {
        fakeGitHub([
            'api.github.com/repos/ArtisanPack-UI/forms/releases/tags/packages%2Fforms%2Fv1.2.0' => Http::response([
                'tag_name' => 'packages/forms/v1.2.0', 'name' => 'Forms 1.2.0', 'body' => 'Notes', 'published_at' => '2026-11-16T10:00:00Z',
            ]),
        ]);

        $item = app(GitHubLinkReader::class)->read('https://github.com/ArtisanPack-UI/forms/releases/tag/packages/forms/v1.2.0');

        expect($item['kind'])->toBe('release')
            ->and($item['title'])->toBe('Forms 1.2.0');
    });

    it('follows a query-only redirect on the same path', function (): void {
        Http::fake(fn ($request) => str_contains($request->url(), 'view=plain')
            ? Http::response('Plain text.', 200, ['Content-Type' => 'text/plain'])
            : Http::response('', 302, ['Location' => '?view=plain']));

        $page = app(PublicUrlReader::class)->read('https://laravel-news.example/news/article');

        expect($page['url'])->toBe('https://laravel-news.example/news/article?view=plain')
            ->and($page['text'])->toBe('Plain text.');
    });

    it('reads a public web page as text', function (): void {
        Http::fake(['laravel-news.example/*' => Http::response(
            '<html><head><title>ArtisanPack UI 2.0</title><script>track()</script></head><body><nav>Menu</nav><p>Big &amp; bold.</p><p>Second</p></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        )]);

        $page = app(PublicUrlReader::class)->read('https://laravel-news.example/post');

        expect($page['title'])->toBe('ArtisanPack UI 2.0')
            ->and($page['text'])->toBe("Big & bold.\nSecond");
    });

    it('refuses URLs the server must not call', function (string $url): void {
        app(PublicUrlReader::class)->read($url);
    })->throws(RecapException::class)->with([
        'loopback'      => 'https://localhost/admin',
        'plain http'    => 'http://laravel-news.example/post',
        'not resolving' => 'https://nonexistent.example/post',
        'not http'      => 'file:///etc/passwd',
    ]);

    it('checks every redirect hop against the policy', function (): void {
        Http::fake(['laravel-news.example/*' => Http::response('', 302, ['Location' => 'https://localhost/secret'])]);

        expect(fn () => app(PublicUrlReader::class)->read('https://laravel-news.example/post'))
            ->toThrow(RecapException::class);

        Http::assertSentCount(1);
    });

    it('refuses responses that aren\'t text', function (): void {
        Http::fake(['laravel-news.example/*' => Http::response('binary', 200, ['Content-Type' => 'application/zip'])]);

        app(PublicUrlReader::class)->read('https://laravel-news.example/file.zip');
    })->throws(RecapException::class);
});
