<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Http\Requests\UpdateSettingsRequest;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Services\Board\ProjectBoardReader;
use ArtisanPackUI\Site\Services\ConnectionTester;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;
use ArtisanPackUI\Site\Support\AdminPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The plugin's Settings page: docs site, GitHub App and release
 * announcement (webhook, Bluesky and Mastodon) connection details, plus
 * their "Test connection" checks.
 *
 * The stored secrets never reach the browser. The page only learns
 * whether each is set.
 *
 * @since 1.0.0
 */
final class SettingsController
{
    public function show(Request $request): Response
    {
        return AdminPages::render($request, 'settings', [
            'settings'  => self::present(IntegrationSettings::current()),
            'endpoints' => [
                'update'       => route('artisanpack-ui.settings.update'),
                'testDocs'     => route('artisanpack-ui.settings.test-docs'),
                'testGitHub'   => route('artisanpack-ui.settings.test-github'),
                'testBluesky'  => route('artisanpack-ui.settings.test-bluesky'),
                'testMastodon' => route('artisanpack-ui.settings.test-mastodon'),
                'webhook'      => route('artisanpack-ui.webhooks.github'),
            ],
        ]);
    }

    /**
     * Save the settings. Cached GitHub state built from the old values is
     * dropped first, while the cache keys still derive from them: the
     * installation token when the App credentials change, and the project's
     * Status field when the org or project changes.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $settings  = IntegrationSettings::current();
        $validated = $request->validated();
        $before    = self::gitHubState($settings);

        $settings->fill([
            'docs_base_url'          => $validated['docs_base_url'] ?? null,
            'github_app_id'          => self::trimmed($validated['github_app_id'] ?? null),
            'github_installation_id' => self::trimmed($validated['github_installation_id'] ?? null),
            'github_organization'    => self::trimmed($validated['github_organization'] ?? null),
            'github_project_number'  => $validated['github_project_number'] ?? null,
            'bluesky_handle'         => self::trimmed($validated['bluesky_handle'] ?? null),
            'mastodon_instance_url'  => self::origin($validated['mastodon_instance_url'] ?? null),
        ]);

        // Sent as an empty string (or null, once the host converts empty
        // strings) when the admin clears the list, which excludes nothing.
        if ($request->exists('announcement_excluded_repositories')) {
            $settings->announcement_excluded_repositories = implode("\n", IntegrationSettings::parseRepositoryList(
                (string) ($validated['announcement_excluded_repositories'] ?? ''),
            ));
        }

        self::applySecret($settings, 'docs_api_token', $validated, $request->boolean('remove_docs_api_token'));
        self::applySecret($settings, 'github_private_key', $validated, $request->boolean('remove_github_private_key'));
        self::applySecret($settings, 'github_webhook_secret', $validated, $request->boolean('remove_github_webhook_secret'));
        self::applySecret($settings, 'bluesky_app_password', $validated, $request->boolean('remove_bluesky_app_password'));
        self::applySecret($settings, 'mastodon_access_token', $validated, $request->boolean('remove_mastodon_access_token'));

        self::forgetStaleGitHubCaches($before, self::gitHubState($settings));

        $settings->save();

        return response()->json([
            'message'  => __('Settings saved.'),
            'settings' => self::present($settings),
        ]);
    }

    public function testDocs(ConnectionTester $tester): JsonResponse
    {
        return response()->json($tester->testDocsSite());
    }

    public function testGitHub(ConnectionTester $tester): JsonResponse
    {
        return response()->json($tester->testGitHub());
    }

    /**
     * @since 1.4.0
     */
    public function testBluesky(ConnectionTester $tester): JsonResponse
    {
        return response()->json($tester->testBluesky());
    }

    /**
     * @since 1.4.0
     */
    public function testMastodon(ConnectionTester $tester): JsonResponse
    {
        return response()->json($tester->testMastodon());
    }

    /**
     * What the Settings page may see: every plain value, and only whether
     * each secret is set.
     *
     * @return array{docsBaseUrl: string|null, hasDocsApiToken: bool, githubAppId: string|null, githubInstallationId: string|null, hasGitHubPrivateKey: bool, githubOrganization: string, githubProjectNumber: int|null, hasWebhookSecret: bool, blueskyHandle: string|null, hasBlueskyAppPassword: bool, mastodonInstanceUrl: string|null, hasMastodonAccessToken: bool, excludedRepositories: list<string>}
     */
    public static function present(IntegrationSettings $settings): array
    {
        return [
            'docsBaseUrl'            => $settings->docsBaseUrl(),
            'hasDocsApiToken'        => filled($settings->docs_api_token),
            'githubAppId'            => $settings->github_app_id,
            'githubInstallationId'   => $settings->github_installation_id,
            'hasGitHubPrivateKey'    => filled($settings->github_private_key),
            'githubOrganization'     => $settings->githubOrganization(),
            'githubProjectNumber'    => $settings->github_project_number,
            'hasWebhookSecret'       => $settings->hasWebhookSecret(),
            'blueskyHandle'          => $settings->blueskyHandle(),
            'hasBlueskyAppPassword'  => filled($settings->bluesky_app_password),
            'mastodonInstanceUrl'    => $settings->mastodonInstanceUrl(),
            'hasMastodonAccessToken' => filled($settings->mastodon_access_token),
            'excludedRepositories'   => $settings->excludedRepositories(),
        ];
    }

    /**
     * Replace a secret when a new value was sent, clear it when asked, and
     * otherwise keep the stored one.
     *
     * @param  array<string, mixed>  $validated
     */
    private static function applySecret(IntegrationSettings $settings, string $key, array $validated, bool $remove): void
    {
        $value = self::trimmed($validated[$key] ?? null);

        if (null !== $value) {
            $settings->{$key} = $value;
        } elseif ($remove) {
            $settings->{$key} = null;
        }
    }

    /**
     * The values the cached GitHub state is built from.
     *
     * @return array{app: list<string|null>, project: list<string|int|null>}
     *
     * @since 1.0.0
     */
    private static function gitHubState(IntegrationSettings $settings): array
    {
        return [
            'app'     => [$settings->github_app_id, $settings->github_installation_id, $settings->github_private_key],
            'project' => [strtolower($settings->githubOrganization()), $settings->github_project_number],
        ];
    }

    /**
     * Drop the cached GitHub state the change invalidates. Resolved fresh,
     * so the services still read the saved (old) settings and forget the
     * keys built from them.
     *
     * @param  array{app: list<string|null>, project: list<string|int|null>}  $before
     * @param  array{app: list<string|null>, project: list<string|int|null>}  $after
     *
     * @since 1.0.0
     */
    private static function forgetStaleGitHubCaches(array $before, array $after): void
    {
        if ($before['app'] !== $after['app']) {
            app(GitHubAppClient::class)->forgetInstallationToken();
        }

        if ($before['project'] !== $after['project']) {
            try {
                app(ProjectBoardReader::class)->forgetStatusField();
            } catch (IntegrationNotConfiguredException) {
                // Nothing was cached for a project that wasn't configured.
            }
        }
    }

    /**
     * A URL cut down to its origin (scheme, host and port), so a pasted
     * profile URL such as `https://phpc.social/@artisanpackui` still makes
     * a working API base URL.
     */
    private static function origin(mixed $url): ?string
    {
        $url = self::trimmed($url);

        if (null === $url) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function trimmed(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return '' === $value ? null : $value;
    }
}
