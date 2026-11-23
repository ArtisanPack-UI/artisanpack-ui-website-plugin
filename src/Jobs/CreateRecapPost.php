<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Jobs;

use ArtisanPackUI\Site\Jobs\Concerns\RecordsRecapFailures;
use ArtisanPackUI\Site\Models\RecapSession;
use ArtisanPackUI\Site\Services\Recap\RecapPostBuilder;
use ArtisanPackUI\Site\Services\Recap\RecapPostWriter;
use ArtisanPackUI\Site\Services\Recap\RecapPrompts;
use ArtisanPackUI\Site\Services\Recap\RecapWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates a recap session's draft post: the AI writes it from the approved
 * outline ({@see RecapWriter::draft()}), {@see RecapPostBuilder} works out
 * its title, tags, terms and image, and the {@see RecapPostWriter} saves it.
 * The session then ends, holding the post's edit URL.
 *
 * A failure leaves the outline as it was and is recorded on the
 * session for the modal to retry. The post and the session's completion
 * commit together, so a retry after a crash can't create a second draft.
 *
 * @since 1.6.0
 */
final class CreateRecapPost implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RecordsRecapFailures;

    /**
     * Two, though the job records its own failures and never throws: the
     * second attempt only runs if a worker dies mid-run. When the queue
     * hands a long AI run out again after its `retry_after`, the copy finds
     * the overlap lock held and is dropped rather than running twice.
     */
    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly int $sessionId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('artisanpack-ui-recap-' . $this->sessionId))->dontRelease()->expireAfter($this->timeout)];
    }

    public function handle(RecapWriter $writer, RecapPostBuilder $builder, RecapPostWriter $posts): void
    {
        $session = RecapSession::query()->find($this->sessionId);

        // Only the step its endpoint claimed: a stale copy finds the
        // session moved on and leaves it be.
        if (null === $session || RecapSession::STATUS_DRAFTING !== $session->status || null === $session->outline) {
            return;
        }

        try {
            $context = $session->context();
            $draft   = $writer->draft(RecapPrompts::draftInput($context));
            $data    = $builder->build($context, $draft, $session->user_id);

            DB::transaction(static function () use ($session, $posts, $data, $draft): void {
                $status = RecapSession::query()->whereKey($session->getKey())->lockForUpdate()->value('status');

                if (RecapSession::STATUS_DRAFTING !== $status) {
                    return;
                }

                $created = $posts->create($data);

                $session->mergeSources($draft['sources']);
                $session->status        = RecapSession::STATUS_CREATED;
                $session->post_id       = $created['id'];
                $session->edit_url      = $created['editUrl'];
                $session->failed_action = null;
                $session->error         = null;
                $session->expires_at    = now()->addHour();
                $session->save();
            });
        } catch (Throwable $exception) {
            $this->recordFailure($session, RecapSession::ACTION_POST, $exception);
        }
    }

    /**
     * The queue gave up on the job (every attempt timed out, say), so let
     * the modal offer a retry instead of waiting on it forever.
     */
    public function failed(?Throwable $exception): void
    {
        $this->recordTerminalFailure($this->sessionId, RecapSession::ACTION_POST, $exception);
    }
}
