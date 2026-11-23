<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Tests\Support;

use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Services\Recap\RecapWriter;

/**
 * A {@see RecapWriter} for the suite: records what it was asked and answers
 * with queued outlines and drafts (or exceptions), in order.
 */
final class FakeRecapWriter implements RecapWriter
{
    public bool $available = true;

    /** @var list<array<string, mixed>|RecapException> */
    public array $outlines = [];

    /** @var list<array<string, mixed>|RecapException> */
    public array $drafts = [];

    /** @var list<array<string, mixed>> */
    public array $outlineInputs = [];

    /** @var list<array<string, mixed>> */
    public array $draftInputs = [];

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Run while the outline is being "written", e.g. to change the session
     * the way a concurrent request would.
     *
     * @var (\Closure(): void)|null
     */
    public ?\Closure $duringOutline = null;

    public function outline(array $input): array
    {
        $this->outlineInputs[] = $input;

        if (null !== $this->duringOutline) {
            ($this->duringOutline)();
        }

        return $this->next($this->outlines, [
            'outline'  => "## Releases\n\n- Forms v1.6.0",
            'reply'    => 'Here is a first outline.',
            'packages' => ['Forms'],
            'sources'  => [],
        ]);
    }

    public function draft(array $input): array
    {
        $this->draftInputs[] = $input;

        return $this->next($this->drafts, [
            'body'            => "Intro.\n\n## Forms\n\nUploads.",
            'excerpt'         => 'A busy week.',
            'focusKeyword'    => 'ArtisanPack UI updates',
            'metaDescription' => 'What shipped.',
            'tags'            => [],
            'newTags'         => [],
            'packages'        => [],
            'sources'         => [],
        ]);
    }

    /**
     * @param  list<array<string, mixed>|RecapException>  $queue
     * @param  array<string, mixed>  $default
     *
     * @return array<string, mixed>
     */
    private function next(array &$queue, array $default): array
    {
        $answer = [] === $queue ? $default : array_shift($queue);

        if ($answer instanceof RecapException) {
            throw $answer;
        }

        return $answer;
    }
}
