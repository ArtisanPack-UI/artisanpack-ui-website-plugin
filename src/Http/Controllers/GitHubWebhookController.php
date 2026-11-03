<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Services\Social\ReleaseAnnouncer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Receives GitHub webhook deliveries (an org webhook, or the GitHub App's)
 * and announces new releases through {@see ReleaseAnnouncer}.
 *
 * Public and outside the admin stack, so it has no session or CSRF check:
 * every delivery must instead carry an `X-Hub-Signature-256` HMAC of its
 * raw body, keyed with the secret saved on the Settings page. With no
 * secret saved the endpoint answers 404 to everything.
 *
 * A delivery can be redelivered by hand from GitHub, so each delivery id
 * is handled once a day (and freed again when handling it throws); a delivery without an id is refused, since GitHub
 * always sends one. The id isn't signed, so replaying an old signed body
 * under a new id is stopped by {@see ReleaseAnnouncer} refusing releases
 * published more than a day ago. `ping` (sent when the webhook is created)
 * is acknowledged; events other than `release` are accepted and ignored.
 *
 * @since 1.4.0
 */
final class GitHubWebhookController
{
    /**
     * Seconds a delivery id is remembered.
     */
    private const DELIVERY_TTL = 86400;

    public function __invoke(Request $request, IntegrationSettings $settings, ReleaseAnnouncer $announcer): JsonResponse
    {
        if (! $settings->hasWebhookSecret()) {
            return response()->json(['message' => __('Not found.')], 404);
        }

        if (! self::hasValidSignature($request, (string) $settings->github_webhook_secret)) {
            return response()->json(['message' => __('Invalid signature.')], 401);
        }

        $event = (string) $request->header('X-GitHub-Event', '');

        if ('ping' === $event) {
            return response()->json(['message' => 'pong']);
        }

        $delivery = (string) $request->header('X-GitHub-Delivery', '');

        if ('' === $delivery || strlen($delivery) > 100) {
            return response()->json(['message' => __('Missing delivery id.')], 400);
        }

        if (! self::isFirstDelivery($delivery)) {
            return response()->json(['message' => __('Delivery already handled.')]);
        }

        if ('release' !== $event) {
            return response()->json(['message' => __('Event ignored.'), 'event' => $event], 202);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['message' => __('The payload isn\'t JSON.')], 400);
        }

        try {
            $outcome = $announcer->handle($payload);
        } catch (Throwable $exception) {
            // Let a manual redelivery try again once the queue is back.
            Cache::forget(self::deliveryKey($delivery));

            throw $exception;
        }

        return response()->json($outcome, 202);
    }

    /**
     * Whether the `X-Hub-Signature-256` header is the HMAC-SHA256 of the
     * raw request body under the secret, compared in constant time.
     */
    private static function hasValidSignature(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $request->getContent(), $secret), $header);
    }

    /**
     * Record the delivery id, returning false when it was already seen.
     */
    private static function isFirstDelivery(string $delivery): bool
    {
        return Cache::add(self::deliveryKey($delivery), true, self::DELIVERY_TTL);
    }

    private static function deliveryKey(string $delivery): string
    {
        return 'artisanpack-ui:github-delivery:' . hash('sha256', $delivery);
    }
}
