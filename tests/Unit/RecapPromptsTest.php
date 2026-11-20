<?php

declare(strict_types=1);

use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Services\Recap\NullRecapWriter;
use ArtisanPackUI\Site\Services\Recap\RecapPrompts;
use ArtisanPackUI\Site\Services\Recap\RecapWriter;

/**
 * The weekly recap's prompts (#61): what each agent is sent, and the
 * checks their answers pass.
 */

/**
 * A recap context as a session builds it.
 *
 * @return array<string, mixed>
 */
function recapContext(array $overrides = []): array
{
    return [
        'title'       => 'This Week in ArtisanPack UI: November 21, 2026',
        'publishDate' => '2026-11-21',
        'window'      => ['start' => '2026-11-14T10:00:00+00:00', 'end' => '2026-11-21T23:59:59+00:00', 'previousRecap' => ['id' => 4, 'title' => 'Last week', 'publishedAt' => '2026-11-14T10:00:00+00:00']],
        'notes'       => 'Lead with Forms. ',
        'releases'    => [
            ['packageId' => 1, 'package' => 'Forms', 'repo' => 'ArtisanPack-UI/forms', 'tag' => 'v1.6.0', 'name' => 'v1.6.0', 'url' => 'https://github.com/ArtisanPack-UI/forms/releases/tag/v1.6.0', 'publishedAt' => '2026-11-15T12:00:00+00:00', 'notes' => 'Uploads'],
            ['packageId' => 2, 'package' => 'Media', 'repo' => 'ArtisanPack-UI/media', 'tag' => 'v2.0.0', 'name' => 'Media 2', 'url' => 'https://github.com/ArtisanPack-UI/media/releases/tag/v2.0.0', 'publishedAt' => '2026-11-16T12:00:00+00:00', 'notes' => 'Big'],
        ],
        'warnings'      => [],
        'pullRequests'  => [],
        'sources'       => [],
        'styleGuide'    => 'Friendly.',
        'styleExamples' => [['title' => 'Last week', 'text' => 'Hello']],
        'packages'      => ['Forms', 'Media', 'Icons'],
        'tags'          => ['Laravel', 'Forms'],
        'messages'      => [],
        'outline'       => null,
        ...$overrides,
    ];
}

it('sends the outline agent every release and the fixed title', function (): void {
    $input = RecapPrompts::outlineInput(recapContext());

    expect(array_column($input['releases'], 'version'))->toBe(['v1.6.0', 'v2.0.0'])
        ->and($input['title'])->toBe('This Week in ArtisanPack UI: November 21, 2026')
        ->and($input['publish_date'])->toBe('Saturday, November 21, 2026')
        ->and($input['window']['previous_recap'])->toBe('Last week')
        ->and($input['notes'])->toBe('Lead with Forms.')
        ->and($input['current_outline'])->toBeNull()
        ->and(RecapPrompts::outlineTask($input))->toBe('Write the first outline.');
});

it('sends the conversation and current outline on a revision', function (): void {
    $input = RecapPrompts::outlineInput(recapContext([
        'outline'  => '## Forms',
        'messages' => [
            ['role' => 'assistant', 'content' => 'Here is the outline.'],
            ['role' => 'user', 'content' => 'Move Media first.'],
            ['role' => 'system', 'content' => 'ignored'],
        ],
    ]));

    expect($input['current_outline'])->toBe('## Forms')
        ->and($input['conversation'])->toBe([
            ['role' => 'assistant', 'content' => 'Here is the outline.'],
            ['role' => 'user', 'content' => 'Move Media first.'],
        ])
        ->and(RecapPrompts::outlineTask($input))->toContain('Revise');
});

it('sends the draft agent the approved outline and the existing tags', function (): void {
    $input = RecapPrompts::draftInput(recapContext(['outline' => '## Forms']));

    expect($input['approved_outline'])->toBe('## Forms')
        ->and($input['tags'])->toBe(['Laravel', 'Forms'])
        ->and($input)->not->toHaveKey('conversation');
});

it('sends the material as JSON with the task', function (): void {
    $message = RecapPrompts::message(['title' => 'T'], 'Write the first outline.');

    expect($message[0]['type'])->toBe('text')
        ->and($message[0]['text'])->toStartWith("Recap material:\n{")
        ->and($message[0]['text'])->toEndWith("\n\nWrite the first outline.");
});

it('tidies the outline agent\'s answer', function (): void {
    $sources = [['url' => 'https://x.example', 'kind' => 'web_page', 'title' => 'X', 'text' => 'x']];

    expect(RecapPrompts::normalizeOutline(['outline' => " ## Forms\n", 'reply' => ' Done. ', 'packages' => ['Forms', 'forms', '', 3]], $sources))->toBe([
        'outline'  => '## Forms',
        'reply'    => 'Done.',
        'packages' => ['Forms', '3'],
        'sources'  => $sources,
    ]);
});

it('rejects an answer without an outline or post body', function (Closure $normalize): void {
    $normalize();
})->throws(RecapException::class)->with([
    'empty outline'  => [fn () => RecapPrompts::normalizeOutline(['outline' => '  ', 'reply' => 'x'])],
    'not an object'  => [fn () => RecapPrompts::normalizeOutline('nope')],
    'empty body'     => [fn () => RecapPrompts::normalizeDraft(['body' => ''])],
]);

it('cuts an over-long meta description at a word', function (): void {
    $draft = RecapPrompts::normalizeDraft([
        'body'             => 'Body',
        'meta_description' => str_repeat('word ', 40) . 'end',
        'tags'             => ['Laravel', 'laravel'],
        'new_tags'         => ['Forms 2'],
    ]);

    expect(mb_strlen($draft['metaDescription']))->toBeLessThanOrEqual(RecapPrompts::META_DESCRIPTION_LIMIT)
        ->and($draft['metaDescription'])->toEndWith('word')
        ->and($draft['tags'])->toBe(['Laravel'])
        ->and($draft['newTags'])->toBe(['Forms 2']);
});

it('is unavailable without Keystone\'s AI module', function (): void {
    expect(app(RecapWriter::class))->toBeInstanceOf(NullRecapWriter::class)
        ->and(app(RecapWriter::class)->isAvailable())->toBeFalse();

    app(RecapWriter::class)->outline([]);
})->throws(RecapException::class);
