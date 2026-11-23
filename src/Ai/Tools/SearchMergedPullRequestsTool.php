<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Ai\Tools;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Services\Recap\MergedPullRequestSearch;
use ArtisanPackUI\Site\Services\Recap\RecapSourceLog;
use ArtisanPackUI\Site\Services\Recap\RecapWindow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Recap tool: search the pull requests merged across the org during the
 * recap window, optionally by keyword or repository (see
 * {@see MergedPullRequestSearch}). Results are logged as a source, so
 * later turns and the draft get them too.
 *
 * @since 1.6.0
 */
final class SearchMergedPullRequestsTool implements Tool
{
    public function __construct(
        private readonly MergedPullRequestSearch $search,
        private readonly RecapWindow $window,
        private readonly RecapSourceLog $log,
    ) {}

    public function name(): string
    {
        return 'search_merged_pull_requests';
    }

    public function description(): string
    {
        return 'Search the pull requests merged across the ArtisanPack-UI GitHub org during the recap window. Optionally narrow by keywords or by one repository name. Returns at most 50, most recently updated first.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'keywords' => $schema->string()->description('Words to search titles and bodies for.'),
            'repo'     => $schema->string()->description('A repository name in the org, without the org, e.g. "forms".'),
        ];
    }

    public function handle(Request $request): string
    {
        $keywords = trim((string) $request->string('keywords'));
        $repo     = trim((string) $request->string('repo'));

        try {
            $results = $this->search->search($this->window, $keywords, $repo);
        } catch (GitHubException|IntegrationNotConfiguredException $exception) {
            return (string) json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $json = (string) json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->log->add([
            'url'   => 'search:merged-pull-requests?' . http_build_query(array_filter(['keywords' => $keywords, 'repo' => $repo])),
            'kind'  => 'pull_request_search',
            'title' => trim('Merged pull requests ' . $keywords . ('' === $repo ? '' : ' in ' . $repo)),
            'text'  => $json,
        ]);

        return $json;
    }
}
