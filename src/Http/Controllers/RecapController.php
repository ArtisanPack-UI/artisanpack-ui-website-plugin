<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Http\Requests\RecapMessageRequest;
use ArtisanPackUI\Site\Http\Requests\StartRecapRequest;
use ArtisanPackUI\Site\Jobs\CreateRecapPost;
use ArtisanPackUI\Site\Jobs\GenerateRecapOutline;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Models\RecapSession;
use ArtisanPackUI\Site\Services\GitHub\GitHubIssues;
use ArtisanPackUI\Site\Services\Recap\RecapAvailability;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The weekly recap modal's endpoints: start a recap, poll it, ask for a
 * revision, create the post, retry a failed step, and start over.
 *
 * Every session belongs to the admin who started it; anyone else gets a
 * 404, as does an expired one. An admin has one recap in progress at a
 * time: starting a new one discards the old. The AI work runs in queued
 * jobs and the modal polls {@see self::show()} until the session is no
 * longer busy. A step is claimed under a row lock, so a double submit can't
 * queue two jobs for one session.
 *
 * @since 1.6.0
 */
final class RecapController
{
    public function __construct(private readonly RecapAvailability $availability) {}

    /**
     * The admin's recap in progress, or null.
     */
    public function current(Request $request): JsonResponse
    {
        RecapSession::pruneExpired();

        $session = RecapSession::query()
            ->active()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->latest('id')
            ->first();

        return response()->json(['session' => null === $session ? null : self::present($session)]);
    }

    /**
     * Start a recap, replacing any the admin had in progress, and queue its
     * first outline.
     */
    public function store(StartRecapRequest $request): JsonResponse
    {
        $this->ensureAvailable();

        $userId      = (int) $request->user()->getAuthIdentifier();
        $publishDate = CarbonImmutable::createFromFormat('Y-m-d', (string) $request->validated('publish_date'), (string) config('app.timezone'))->startOfDay();

        RecapSession::query()->active()->where('user_id', $userId)->delete();

        $session = new RecapSession([
            'user_id'      => $userId,
            'publish_date' => $publishDate->format('Y-m-d'),
            'title'        => IntegrationSettings::current()->recapTitleFor($publishDate),
            'notes'        => trim((string) $request->validated('notes')) ?: null,
            'messages'     => [],
            'status'       => RecapSession::STATUS_GATHERING,
        ]);
        $session->touchExpiry();
        $session->save();

        self::queue($session, RecapSession::ACTION_OUTLINE);

        return response()->json(['session' => self::present($session->refresh())], 202);
    }

    public function show(Request $request, RecapSession $recap): JsonResponse
    {
        self::authorizeOwner($request, $recap);

        return response()->json(['session' => self::present($recap)]);
    }

    /**
     * Ask the AI to revise the outline.
     */
    public function message(RecapMessageRequest $request, RecapSession $recap): JsonResponse
    {
        self::authorizeOwner($request, $recap);
        $this->ensureAvailable();

        $text    = trim((string) $request->validated('message'));
        $claimed = self::claim($recap, static function (RecapSession $locked) use ($text): bool {
            if (! self::hasRevisableOutline($locked)) {
                return false;
            }

            $locked->addMessage('user', $text);
            $locked->status = RecapSession::STATUS_OUTLINING;

            return true;
        });

        if (null === $claimed) {
            return self::conflict(__('The outline can\'t be revised right now.'));
        }

        self::queue($claimed, RecapSession::ACTION_OUTLINE);

        return response()->json(['session' => self::present($claimed)], 202);
    }

    /**
     * Create the draft post from the outline.
     */
    public function createPost(Request $request, RecapSession $recap): JsonResponse
    {
        self::authorizeOwner($request, $recap);
        $this->ensureAvailable();

        $claimed = self::claim($recap, static function (RecapSession $locked): bool {
            if (! self::hasRevisableOutline($locked)) {
                return false;
            }

            $locked->status = RecapSession::STATUS_DRAFTING;

            return true;
        });

        if (null === $claimed) {
            return self::conflict(__('The post can\'t be created right now.'));
        }

        self::queue($claimed, RecapSession::ACTION_POST);

        return response()->json(['session' => self::present($claimed)], 202);
    }

    /**
     * Run a failed step again.
     */
    public function retry(Request $request, RecapSession $recap): JsonResponse
    {
        self::authorizeOwner($request, $recap);
        $this->ensureAvailable();

        $action  = RecapSession::ACTION_OUTLINE;
        $claimed = self::claim($recap, static function (RecapSession $locked) use (&$action): bool {
            if (RecapSession::STATUS_FAILED !== $locked->status) {
                return false;
            }

            $action         = RecapSession::ACTION_POST === $locked->failed_action && null !== $locked->outline ? RecapSession::ACTION_POST : RecapSession::ACTION_OUTLINE;
            $locked->status = RecapSession::ACTION_POST === $action ? RecapSession::STATUS_DRAFTING : RecapSession::STATUS_OUTLINING;

            return true;
        });

        if (null === $claimed) {
            return self::conflict(__('Nothing has failed.'));
        }

        self::queue($claimed, $action);

        return response()->json(['session' => self::present($claimed)], 202);
    }

    /**
     * Discard the recap.
     */
    public function destroy(Request $request, RecapSession $recap): Response
    {
        self::authorizeOwner($request, $recap);

        $recap->delete();

        return response()->noContent();
    }

    /**
     * What the modal sees: everything but the fetched sources' text.
     *
     * @return array<string, mixed>
     */
    public static function present(RecapSession $session): array
    {
        $id = (int) $session->getKey();

        return [
            'id'               => $id,
            'status'           => $session->status,
            'busy'             => $session->isBusy(),
            'publishDate'      => $session->publish_date->format('Y-m-d'),
            'title'            => $session->title,
            'notes'            => (string) $session->notes,
            'window'           => $session->window,
            'releases'         => array_map(static fn (array $release): array => [
                'package'     => (string) ($release['package'] ?? ''),
                'tag'         => (string) ($release['tag'] ?? ''),
                'url'         => (string) ($release['url'] ?? ''),
                'publishedAt' => (string) ($release['publishedAt'] ?? ''),
            ], $session->releases ?? []),
            'pullRequestCount' => count($session->pull_requests ?? []),
            'sources'          => array_map(static fn (array $source): array => [
                'url'   => $source['url'],
                'title' => $source['title'],
                'kind'  => $source['kind'],
            ], $session->sources ?? []),
            'warnings'         => $session->warnings ?? [],
            'messages'         => $session->messages ?? [],
            'outline'          => $session->outline,
            'outlineHtml'      => null === $session->outline ? null : GitHubIssues::renderMarkdown($session->outline),
            'error'            => $session->error,
            'failedAction'     => $session->failed_action,
            'editUrl'          => $session->edit_url,
            'endpoints'        => [
                'show'    => route('artisanpack-ui.recaps.show', ['recap' => $id]),
                'message' => route('artisanpack-ui.recaps.messages.store', ['recap' => $id]),
                'post'    => route('artisanpack-ui.recaps.post', ['recap' => $id]),
                'retry'   => route('artisanpack-ui.recaps.retry', ['recap' => $id]),
                'destroy' => route('artisanpack-ui.recaps.destroy', ['recap' => $id]),
            ],
        ];
    }

    /**
     * A session with an outline that isn't being worked on: ready, or
     * failed with the outline intact.
     */
    private static function hasRevisableOutline(RecapSession $session): bool
    {
        return null !== $session->outline
            && in_array($session->status, [RecapSession::STATUS_READY, RecapSession::STATUS_FAILED], true);
    }

    /**
     * Reload the session under a row lock and let `$change` check and
     * update it, so only one request moves a session into a busy state.
     * Clears the last failure and pushes the expiry out when it does.
     *
     * @param  Closure(RecapSession): bool  $change  False when the session can't take the step.
     *
     * @return RecapSession|null The claimed session, or null when it was refused.
     */
    private static function claim(RecapSession $recap, Closure $change): ?RecapSession
    {
        return DB::transaction(static function () use ($recap, $change): ?RecapSession {
            $locked = RecapSession::query()->whereKey($recap->getKey())->lockForUpdate()->first();

            if (null === $locked || ! $change($locked)) {
                return null;
            }

            $locked->failed_action = null;
            $locked->error         = null;
            $locked->touchExpiry();
            $locked->save();

            return $locked;
        });
    }

    /**
     * Queue the step's job. If the queue refuses it, record the step as
     * failed so the modal stops waiting and offers a retry.
     *
     * @param  RecapSession::ACTION_*  $action
     */
    private static function queue(RecapSession $session, string $action): void
    {
        $id = (int) $session->getKey();

        try {
            Bus::dispatch(RecapSession::ACTION_POST === $action ? new CreateRecapPost($id) : new GenerateRecapOutline($id));
        } catch (Throwable $exception) {
            Log::warning('ArtisanPack UI recap: a step could not be queued.', ['session' => $id, 'action' => $action, 'exception' => $exception->getMessage()]);

            $session->status        = RecapSession::STATUS_FAILED;
            $session->failed_action = $action;
            $session->error         = __('The step couldn\'t be queued. Try again, and check the logs if it keeps happening.');
            $session->save();
        }
    }

    /**
     * Refuse with a 409 the modal can show when the recap can't run.
     */
    private function ensureAvailable(): void
    {
        if (! $this->availability->isAvailable()) {
            throw new HttpResponseException(self::conflict(RecapException::unavailable()->getMessage()));
        }
    }

    private static function authorizeOwner(Request $request, RecapSession $session): void
    {
        abort_unless($session->user_id === (int) $request->user()->getAuthIdentifier() && $session->expires_at->isFuture(), 404);
    }

    private static function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }
}
