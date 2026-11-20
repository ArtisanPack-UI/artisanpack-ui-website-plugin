<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Ai\Tools;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Services\Recap\RecapSourceLog;
use ArtisanPackUI\Site\Services\Recap\RecapSourceReader;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Recap tool: read a link the maintainer mentioned, a GitHub pull request,
 * issue, commit or release through the GitHub App, or a public web page
 * (see {@see RecapSourceReader}). What it reads is logged, so later turns
 * and the draft get it too.
 *
 * A link that can't be read comes back as an error the model can report,
 * rather than failing the run.
 *
 * @since 1.6.0
 */
final class FetchRecapSourceTool implements Tool
{
    public function __construct(
        private readonly RecapSourceReader $reader,
        private readonly RecapSourceLog $log,
    ) {}

    public function name(): string
    {
        return 'fetch_link';
    }

    public function description(): string
    {
        return 'Read a link the maintainer mentioned: a GitHub pull request, issue, commit or release URL, or a public https web page. Returns its title and text.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('The full https URL to read.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $url = trim((string) $request->string('url'));

        try {
            $source = $this->reader->read($url);
        } catch (RecapException|GitHubException|IntegrationNotConfiguredException $exception) {
            return (string) json_encode(['url' => $url, 'error' => $exception->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->log->add($source);

        return (string) json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
