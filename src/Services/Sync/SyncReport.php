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
 * Failures are also kept per marketing package (see {@see self::failuresFor()})
 * so a run can record each package's last error for the edit screen. They
 * stay server side; `toArray()` only carries the flat `messages`.
 *
 * @since 1.0.0
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

    /** @var array<int, list<string>> Marketing package id → why it failed. */
    private array $packageFailures = [];

    /**
     * Count a failure and record why, against the package it belongs to
     * when there is one.
     */
    public function fail(string $message, ?int $packageId = null): void
    {
        $this->failed++;
        $this->messages[] = $message;

        if (null !== $packageId) {
            $this->packageFailures[$packageId][] = $message;
        }
    }

    /**
     * The failures recorded against one marketing package.
     *
     * @return list<string>
     */
    public function failuresFor(int $packageId): array
    {
        return $this->packageFailures[$packageId] ?? [];
    }

    /**
     * Fold another report's counts, messages and package failures into
     * this one. `next` is taken from the other report, so merging a run's
     * batches in order leaves the last batch's cursor.
     */
    public function merge(self $other): self
    {
        $this->created     += $other->created;
        $this->updated     += $other->updated;
        $this->skipped     += $other->skipped;
        $this->failed      += $other->failed;
        $this->docsUpdated += $other->docsUpdated;
        $this->next         = $other->next;
        $this->messages     = [...$this->messages, ...$other->messages];

        foreach ($other->packageFailures as $packageId => $failures) {
            $this->packageFailures[$packageId] = [...($this->packageFailures[$packageId] ?? []), ...$failures];
        }

        return $this;
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
