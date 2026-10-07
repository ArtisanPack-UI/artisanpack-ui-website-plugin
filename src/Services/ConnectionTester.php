<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services;

use ArtisanPackUI\Site\Exceptions\DocsSiteException;
use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Services\Docs\DocsSiteClient;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;

/**
 * The Settings page's "Test connection" checks, against the saved settings.
 *
 * Each check returns a status payload rather than throwing, so the page can
 * show what passed, what failed and why. Failures carry the clients'
 * admin-friendly messages.
 *
 * @phpstan-type CheckResult array{ok: bool, message: string, details: list<string>}
 *
 * @since 0.2.0
 */
final class ConnectionTester
{
    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly DocsSiteClient $docs,
        private readonly GitHubAppClient $github,
    ) {}

    /**
     * List the docs site's packages, which proves the URL, the token and
     * the token's `packages:read` ability.
     *
     * @return CheckResult
     */
    public function testDocsSite(): array
    {
        try {
            $count = count($this->docs->packages());
        } catch (IntegrationNotConfiguredException|DocsSiteException $exception) {
            return self::failed($exception->getMessage());
        }

        return [
            'ok'      => true,
            'message' => __('Connected to the docs site.'),
            'details' => [trans_choice(':count package found.|:count packages found.', $count, ['count' => $count])],
        ];
    }

    /**
     * Authenticate as the App, exchange for an installation token and, when
     * a project number is saved, read the org project through GraphQL.
     *
     * @return CheckResult
     */
    public function testGitHub(): array
    {
        $details = [];

        try {
            $app       = $this->github->app();
            $details[] = __('Authenticated as the ":name" GitHub App.', ['name' => (string) ($app['name'] ?? $app['slug'] ?? '?')]);

            $this->github->forgetInstallationToken();
            $this->github->installationToken();
            $details[] = __('Installation token issued.');

            $project = $this->project();

            if (null !== $project) {
                $details[] = $project;
            }
        } catch (IntegrationNotConfiguredException|GitHubException $exception) {
            return self::failed($exception->getMessage(), $details);
        }

        $remaining = $this->github->rateLimitRemaining();

        if (null !== $remaining) {
            $details[] = __(':count API requests left this hour.', ['count' => $remaining]);
        }

        return ['ok' => true, 'message' => __('Connected to GitHub.'), 'details' => $details];
    }

    /**
     * A line describing the configured org project, or null when no project
     * number is saved.
     */
    private function project(): ?string
    {
        $number = $this->settings->github_project_number;

        if (null === $number) {
            return null;
        }

        $organization = $this->settings->githubOrganization();
        $response     = $this->github->graphql(
            'query($login: String!, $number: Int!) { organization(login: $login) { projectV2(number: $number) { title } } }',
            ['login' => $organization, 'number' => $number],
        );

        $title = is_array($response->data) ? ($response->data['organization']['projectV2']['title'] ?? null) : null;

        if (! is_string($title)) {
            throw new GitHubException(__('Project #:number wasn\'t found in the :org org, or the App can\'t read it.', [
                'number' => $number,
                'org'    => $organization,
            ]));
        }

        return __('Found project #:number, ":title".', ['number' => $number, 'title' => $title]);
    }

    /**
     * @param  list<string>  $details
     *
     * @return CheckResult
     */
    private static function failed(string $message, array $details = []): array
    {
        return ['ok' => false, 'message' => $message, 'details' => $details];
    }
}
