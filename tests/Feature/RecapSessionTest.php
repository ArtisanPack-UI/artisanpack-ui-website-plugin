<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Jobs\GenerateRecapOutline;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\Package;
use ArtisanPackUI\Site\Models\RecapSession;
use ArtisanPackUI\Site\Services\Recap\RecapPostWriter;
use ArtisanPackUI\Site\Services\Recap\RecapWriter;
use ArtisanPackUI\Site\Support\Permissions;
use ArtisanPackUI\Site\Tests\Support\FakeRecapPostWriter;
use ArtisanPackUI\Site\Tests\Support\FakeRecapWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The weekly recap sessions (#62): starting, polling, revising, creating
 * the post, retrying and discarding, and who may.
 *
 * The queue runs synchronously here, so each step's job has finished by the
 * time its request returns.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));
    $this->user = actingAsUserWith([Permissions::RECAPS_CREATE]);

    IntegrationSettings::create([
        'github_app_id'          => '123',
        'github_installation_id' => '456',
        'github_private_key'     => testPrivateKey(),
    ]);

    $this->writer = new FakeRecapWriter;
    $this->posts  = new FakeRecapPostWriter;
    app()->instance(RecapWriter::class, $this->writer);
    app()->instance(RecapPostWriter::class, $this->posts);

    Package::query()->create(['title' => 'Forms', 'github_repo' => 'ArtisanPack-UI/forms']);

    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/app/installations/*'                  => Http::response(['token' => 'ghs_1', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/ArtisanPack-UI/forms/releases*' => Http::response([[
            'tag_name' => 'v1.6.0', 'name' => 'v1.6.0', 'html_url' => 'https://github.com/ArtisanPack-UI/forms/releases/tag/v1.6.0',
            'published_at' => '2026-11-18T12:00:00Z', 'draft' => false, 'prerelease' => false, 'body' => 'Uploads',
        ]]),
        'api.github.com/search/issues*'                        => Http::response(['items' => []]),
        'laravel-news.example/*'                               => Http::response('<title>News</title><p>Forms 1.6 is out.</p>', 200, ['Content-Type' => 'text/html']),
    ]);
});

function startRecap(array $payload = []): Illuminate\Testing\TestResponse
{
    return test()->postJson('/admin/artisanpack-ui/recaps', [
        'publish_date' => '2026-11-20',
        'notes'        => 'Lead with Forms. See https://laravel-news.example/forms.',
        ...$payload,
    ]);
}

it('gathers the material and writes the first outline', function (): void {
    startRecap()
        ->assertAccepted()
        ->assertJsonPath('session.title', 'This Week in ArtisanPack UI: November 20, 2026');

    $session = RecapSession::query()->sole();

    expect($session->status)->toBe(RecapSession::STATUS_READY)
        ->and($session->outline)->toBe("## Releases\n\n- Forms v1.6.0")
        ->and(array_column($session->releases, 'tag'))->toBe(['v1.6.0'])
        ->and(array_column($session->sources, 'title'))->toBe(['News'])
        ->and($session->messages)->toBe([['role' => 'assistant', 'content' => 'Here is a first outline.']])
        ->and($this->writer->outlineInputs[0]['notes'])->toBe('Lead with Forms. See https://laravel-news.example/forms.')
        ->and($this->writer->outlineInputs[0]['sources'][0]['text'])->toBe('Forms 1.6 is out.');

    $this->getJson('/admin/artisanpack-ui/recaps/current')
        ->assertOk()
        ->assertJsonPath('session.id', $session->getKey())
        ->assertJsonPath('session.status', 'ready')
        ->assertJsonPath('session.releases.0.tag', 'v1.6.0')
        ->assertJsonPath('session.outlineHtml', "<h2>Releases</h2>\n<ul>\n<li>Forms v1.6.0</li>\n</ul>\n")
        ->assertJsonMissingPath('session.sources.0.text');
});

it('revises the outline from a message, keeping the conversation', function (): void {
    startRecap();
    $session = RecapSession::query()->sole();

    $this->writer->outlines[] = ['outline' => '## Forms first', 'reply' => 'Moved Forms up.', 'packages' => ['Forms'], 'sources' => []];

    $this->postJson("/admin/artisanpack-ui/recaps/{$session->getKey()}/messages", ['message' => 'Put Forms first.'])
        ->assertAccepted();

    $session->refresh();

    expect($session->outline)->toBe('## Forms first')
        ->and(array_column($session->messages, 'content'))->toBe(['Here is a first outline.', 'Put Forms first.', 'Moved Forms up.'])
        ->and($this->writer->outlineInputs[1]['current_outline'])->toBe("## Releases\n\n- Forms v1.6.0")
        ->and(end($this->writer->outlineInputs[1]['conversation']))->toBe(['role' => 'user', 'content' => 'Put Forms first.']);

    Http::assertSentCount(4);
});

it('creates the draft post and ends the session', function (): void {
    startRecap();
    $session = RecapSession::query()->sole();

    $this->postJson("/admin/artisanpack-ui/recaps/{$session->getKey()}/post")->assertAccepted();

    $session->refresh();

    expect($session->status)->toBe(RecapSession::STATUS_CREATED)
        ->and($session->edit_url)->toBe('https://example.test/admin/posts/1/edit')
        ->and($this->posts->created)->toHaveCount(1)
        ->and($this->posts->created[0]->title)->toBe('This Week in ArtisanPack UI: November 20, 2026')
        ->and($this->posts->created[0]->authorId)->toBe($this->user->getKey())
        ->and($this->writer->draftInputs[0]['approved_outline'])->toBe("## Releases\n\n- Forms v1.6.0");

    $this->getJson("/admin/artisanpack-ui/recaps/{$session->getKey()}")->assertJsonPath('session.editUrl', 'https://example.test/admin/posts/1/edit');
    $this->getJson('/admin/artisanpack-ui/recaps/current')->assertJsonPath('session', null);
});

it('records a failed step and retries it', function (): void {
    $this->writer->outlines[] = new RecapException('The AI request failed: overloaded');

    startRecap();
    $session = RecapSession::query()->sole();

    expect($session->status)->toBe(RecapSession::STATUS_FAILED)
        ->and($session->failed_action)->toBe(RecapSession::ACTION_OUTLINE)
        ->and($session->error)->toBe('The AI request failed: overloaded');

    $this->postJson("/admin/artisanpack-ui/recaps/{$session->getKey()}/retry")->assertAccepted();

    expect($session->refresh()->status)->toBe(RecapSession::STATUS_READY)
        ->and($session->error)->toBeNull();
});

it('keeps the outline when creating the post fails, and retries the post', function (): void {
    startRecap();
    $session = RecapSession::query()->sole();
    $this->writer->drafts[] = new RecapException('The AI sent back something the plugin couldn\'t use (no post body). Try again.');

    $this->postJson("/admin/artisanpack-ui/recaps/{$session->getKey()}/post")->assertAccepted();

    expect($session->refresh()->status)->toBe(RecapSession::STATUS_FAILED)
        ->and($session->failed_action)->toBe(RecapSession::ACTION_POST)
        ->and($session->outline)->not->toBeNull();

    $this->postJson("/admin/artisanpack-ui/recaps/{$session->getKey()}/retry")->assertAccepted();

    expect($session->refresh()->status)->toBe(RecapSession::STATUS_CREATED);
});

it('refuses to revise or create while a step is running', function (string $path, array $payload): void {
    $session = RecapSession::query()->create([
        'user_id'      => $this->user->getKey(),
        'publish_date' => '2026-11-20',
        'title'        => 'T',
        'outline'      => '## Outline',
        'status'       => RecapSession::STATUS_OUTLINING,
        'expires_at'   => now()->addDay(),
    ]);

    $this->postJson("/admin/artisanpack-ui/recaps/{$session->getKey()}/{$path}", $payload)->assertStatus(409);
})->with([
    'message' => ['messages', ['message' => 'Again']],
    'post'    => ['post', []],
    'retry'   => ['retry', []],
]);

it('replaces the recap in progress when another is started', function (): void {
    startRecap();
    $first = RecapSession::query()->sole();

    startRecap(['publish_date' => '2026-11-27']);

    expect(RecapSession::query()->pluck('id')->all())->not->toContain($first->getKey())
        ->and(RecapSession::query()->sole()->title)->toBe('This Week in ArtisanPack UI: November 27, 2026');
});

it('discards a recap', function (): void {
    startRecap();
    $session = RecapSession::query()->sole();

    $this->deleteJson("/admin/artisanpack-ui/recaps/{$session->getKey()}")->assertNoContent();

    expect(RecapSession::query()->count())->toBe(0);
});

it('hides another admin\'s recap', function (): void {
    startRecap();
    $session = RecapSession::query()->sole();

    actingAsUserWith([Permissions::RECAPS_CREATE]);

    $this->getJson("/admin/artisanpack-ui/recaps/{$session->getKey()}")->assertNotFound();
    $this->deleteJson("/admin/artisanpack-ui/recaps/{$session->getKey()}")->assertNotFound();
    $this->getJson('/admin/artisanpack-ui/recaps/current')->assertJsonPath('session', null);
});

it('drops expired recaps', function (): void {
    startRecap();
    $this->travel(RecapSession::LIFETIME_DAYS + 1)->days();

    $this->getJson('/admin/artisanpack-ui/recaps/current')->assertJsonPath('session', null);

    expect(RecapSession::query()->count())->toBe(0);
});

it('refuses to start when the recap can\'t run', function (Closure $breakIt): void {
    Queue::fake();
    $breakIt();

    startRecap()->assertStatus(409)->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'AI Studio'));

    Queue::assertNothingPushed();
    expect(RecapSession::query()->count())->toBe(0);
})->with([
    'AI unavailable'        => [fn () => test()->writer->available = false],
    'GitHub not configured' => [fn () => IntegrationSettings::current()->update(['github_app_id' => null])],
]);

it('validates the publish date', function (mixed $date): void {
    startRecap(['publish_date' => $date])->assertUnprocessable()->assertJsonValidationErrors('publish_date');
})->with([
    'missing'    => [null],
    'not a date' => ['next friday'],
    'too far'    => ['2027-06-01'],
]);

it('refuses the recap endpoints without the recap permission', function (): void {
    actingAsUserWith([Permissions::SETTINGS_MANAGE]);

    startRecap()->assertForbidden();
    $this->getJson('/admin/artisanpack-ui/recaps/current')->assertForbidden();
});

it('queues the outline rather than writing it in the request', function (): void {
    Queue::fake();

    startRecap()->assertAccepted()->assertJsonPath('session.status', 'gathering')->assertJsonPath('session.busy', true);

    Queue::assertPushed(GenerateRecapOutline::class, fn (GenerateRecapOutline $job): bool => $job->sessionId === RecapSession::query()->sole()->getKey());
});

it('shares the recap endpoints and availability with admins who may use it', function (): void {
    $this->get('/admin/artisanpack-ui')->assertInertia(fn (Assert $page) => $page
        ->where('artisanpackUi.can.recapsCreate', true)
        ->where('artisanpackUi.endpoints.recaps.available', true)
        ->where('artisanpackUi.endpoints.recaps.store', route('artisanpack-ui.recaps.store')));

    actingAsUserWith([Permissions::STATS_VIEW]);

    $this->get('/admin/artisanpack-ui')->assertInertia(fn (Assert $page) => $page
        ->where('artisanpackUi.endpoints.recaps', null));
});
