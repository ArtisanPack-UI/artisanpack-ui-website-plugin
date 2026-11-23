<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Jobs\Concerns;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Models\RecapSession;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a failed recap step on its session, so the modal can say what
 * went wrong and offer to retry it. Errors written for the admin are shown
 * as they are; anything else is logged and summarized.
 *
 * @since 1.6.0
 */
trait RecordsRecapFailures
{
    /**
     * @param  RecapSession::ACTION_*  $action
     */
    protected function recordFailure(RecapSession $session, string $action, Throwable $exception): void
    {
        // Only while the step still owns the session: never over a created
        // post, or a step another request has since started.
        $status = RecapSession::query()->whereKey($session->getKey())->value('status');

        if (! in_array($status, [RecapSession::STATUS_GATHERING, RecapSession::STATUS_OUTLINING, RecapSession::STATUS_DRAFTING], true)) {
            return;
        }

        // Drop whatever the step changed in memory but didn't commit, such
        // as a rolled-back post's id.
        $session->refresh();

        $known = $exception instanceof RecapException
            || $exception instanceof GitHubException
            || $exception instanceof IntegrationNotConfiguredException;

        if (! $known) {
            Log::warning('ArtisanPack UI recap: a step failed.', [
                'session'   => $session->getKey(),
                'action'    => $action,
                'exception' => $exception->getMessage(),
            ]);
        }

        $session->status        = RecapSession::STATUS_FAILED;
        $session->failed_action = $action;
        $session->error         = $known ? $exception->getMessage() : __('Something went wrong. Try again, and check the logs if it keeps happening.');
        $session->save();
    }

    /**
     * Record a failure the job couldn't catch itself: the queue gave up on
     * it, e.g. after every attempt hit the timeout. Called from the job's
     * `failed()` callback.
     *
     * @param  RecapSession::ACTION_*  $action
     */
    protected function recordTerminalFailure(int $sessionId, string $action, ?Throwable $exception): void
    {
        $session = RecapSession::query()->find($sessionId);

        if (null !== $session) {
            $this->recordFailure($session, $action, $exception ?? new RecapException(__('The step stopped before it finished. Try again.')));
        }
    }
}
