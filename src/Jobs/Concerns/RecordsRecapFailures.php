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
}
