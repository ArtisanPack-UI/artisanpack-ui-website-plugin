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
 * The plugin's Settings page: docs site and GitHub App connection details,
 * plus their "Test connection" checks.
 *
 * The stored docs API token and GitHub private key never reach the browser.
 * The page only learns whether each is set.
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
                'update'     => route('artisanpack-ui.settings.update'),
                'testDocs'   => route('artisanpack-ui.settings.test-docs'),
                'testGitHub' => route('artisanpack-ui.settings.test-github'),
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
        ]);

        self::applySecret($settings, 'docs_api_token', $validated, $request->boolean('remove_docs_api_token'));
        self::applySecret($settings, 'github_private_key', $validated, $request->boolean('remove_github_private_key'));

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
     * What the Settings page may see: every plain value, and only whether
     * each secret is set.
     *
     * @return array{docsBaseUrl: string|null, hasDocsApiToken: bool, githubAppId: string|null, githubInstallationId: string|null, hasGitHubPrivateKey: bool, githubOrganization: string, githubProjectNumber: int|null}
     */
    public static function present(IntegrationSettings $settings): array
    {
        return [
            'docsBaseUrl'          => $settings->docsBaseUrl(),
            'hasDocsApiToken'      => filled($settings->docs_api_token),
            'githubAppId'          => $settings->github_app_id,
            'githubInstallationId' => $settings->github_installation_id,
            'hasGitHubPrivateKey'  => filled($settings->github_private_key),
            'githubOrganization'   => $settings->githubOrganization(),
            'githubProjectNumber'  => $settings->github_project_number,
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

    private static function trimmed(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return '' === $value ? null : $value;
    }
}
