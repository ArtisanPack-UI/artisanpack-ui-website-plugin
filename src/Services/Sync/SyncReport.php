<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Sync;

/**
 * What one sync run did, counted per package, for the admin to read.
 *
 *   - `created`: a marketing package was created (the docs import only).
 *   - `updated`: a marketing package changed.
 *   - `skipped`: a package was left as it was, already up to date or
 *     deliberately kept (a manually chosen icon, say).
 *   - `failed`: a package couldn't be synced; `messages` says why.
 *   - `docsUpdated`: the docs site's copy was written back (version sync).
 *   - `next`: for a step that runs in batches, the cursor to send for the
 *     next batch, or null when the run is complete.
 *
 * @since 0.3.0
 */
final class SyncReport
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public int $failed = 0;

    public int $docsUpdated = 0;

    public ?int $next = null;

    /** @var list<string> */
    public array $messages = [];

    /**
     * Count a failure and record why.
     */
    public function fail(string $message): void
    {
        $this->failed++;
        $this->messages[] = $message;
    }

    /**
     * Record a note that isn't a failure, e.g. why a package was skipped.
     */
    public function note(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return array{created: int, updated: int, skipped: int, failed: int, docsUpdated: int, next: int|null, messages: list<string>}
     */
    public function toArray(): array
    {
        return [
            'created'     => $this->created,
            'updated'     => $this->updated,
            'skipped'     => $this->skipped,
            'failed'      => $this->failed,
            'docsUpdated' => $this->docsUpdated,
            'next'        => $this->next,
            'messages'    => $this->messages,
        ];
    }
}
