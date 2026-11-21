<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Jobs;

use ArtisanPackUI\Site\Jobs\Concerns\RecordsRecapFailures;
use ArtisanPackUI\Site\Models\RecapSession;
use ArtisanPackUI\Site\Services\Recap\RecapGatherer;
use ArtisanPackUI\Site\Services\Recap\RecapPrompts;
use ArtisanPackUI\Site\Services\Recap\RecapWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Writes or revises a recap session's outline: on the first run it gathers
 * the material ({@see RecapGatherer}), then asks the AI for the outline and
 * records its reply in the conversation.
 *
 * Queued because gathering reads every package's releases and the AI may
 * call tools, which together can outlast a web request. Runs once; a
 * failure is recorded on the session for the modal to retry. Two copies
 * for one session never run at once.
 *
 * @since 1.6.0
 */
final class GenerateRecapOutline implements ShouldQueue
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

    public function handle(RecapGatherer $gatherer, RecapWriter $writer): void
    {
        $session = RecapSession::query()->find($this->sessionId);

        if (null === $session || RecapSession::STATUS_CREATED === $session->status) {
            return;
        }

        try {
            if (null === $session->window) {
                $session->status = RecapSession::STATUS_GATHERING;
                $session->save();

                $gatherer->gather($session);
            }

            $session->status = RecapSession::STATUS_OUTLINING;
            $session->save();

            $result = $writer->outline(RecapPrompts::outlineInput($session->context()));

            $session->outline  = $result['outline'];
            $session->packages = $result['packages'];
            $session->mergeSources($result['sources']);
            $session->addMessage('assistant', '' === $result['reply'] ? __('Here\'s the outline.') : $result['reply']);
            $session->status        = RecapSession::STATUS_READY;
            $session->failed_action = null;
            $session->error         = null;
            $session->touchExpiry();
            $session->save();
        } catch (Throwable $exception) {
            $this->recordFailure($session, RecapSession::ACTION_OUTLINE, $exception);
        }
    }
}
