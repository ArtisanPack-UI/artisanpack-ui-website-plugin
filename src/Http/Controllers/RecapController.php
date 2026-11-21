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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The weekly recap modal's endpoints: start a recap, poll it, ask for a
 * revision, create the post, retry a failed step, and start over.
 *
 * Every session belongs to the admin who started it; anyone else gets a
 * 404. An admin has one recap in progress at a time: starting a new one
 * discards the old. The AI work runs in queued jobs and the modal polls
 * {@see self::show()} until the session is no longer busy.
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

        GenerateRecapOutline::dispatch((int) $session->getKey());

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

        if (! self::hasRevisableOutline($recap)) {
            return self::conflict(__('The outline can\'t be revised right now.'));
        }

        $recap->addMessage('user', trim((string) $request->validated('message')));
        $recap->status        = RecapSession::STATUS_OUTLINING;
        $recap->failed_action = null;
        $recap->error         = null;
        $recap->touchExpiry();
        $recap->save();

        GenerateRecapOutline::dispatch((int) $recap->getKey());

        return response()->json(['session' => self::present($recap)], 202);
    }

    /**
     * Create the draft post from the outline.
     */
    public function createPost(Request $request, RecapSession $recap): JsonResponse
    {
        self::authorizeOwner($request, $recap);
        $this->ensureAvailable();

        if (! self::hasRevisableOutline($recap)) {
            return self::conflict(__('The post can\'t be created right now.'));
        }

        $recap->status        = RecapSession::STATUS_DRAFTING;
        $recap->failed_action = null;
        $recap->error         = null;
        $recap->touchExpiry();
        $recap->save();

        CreateRecapPost::dispatch((int) $recap->getKey());

        return response()->json(['session' => self::present($recap)], 202);
    }

    /**
     * Run a failed step again.
     */
    public function retry(Request $request, RecapSession $recap): JsonResponse
    {
        self::authorizeOwner($request, $recap);
        $this->ensureAvailable();

        if (RecapSession::STATUS_FAILED !== $recap->status) {
            return self::conflict(__('Nothing has failed.'));
        }

        $post = RecapSession::ACTION_POST === $recap->failed_action && null !== $recap->outline;

        $recap->status        = $post ? RecapSession::STATUS_DRAFTING : RecapSession::STATUS_OUTLINING;
        $recap->failed_action = null;
        $recap->error         = null;
        $recap->touchExpiry();
        $recap->save();

        $post ? CreateRecapPost::dispatch((int) $recap->getKey()) : GenerateRecapOutline::dispatch((int) $recap->getKey());

        return response()->json(['session' => self::present($recap)], 202);
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
        abort_unless($session->user_id === (int) $request->user()->getAuthIdentifier(), 404);
    }

    private static function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }
}
