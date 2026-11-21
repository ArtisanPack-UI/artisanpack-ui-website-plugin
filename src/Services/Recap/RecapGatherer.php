<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\GitHubRateLimitException;
use ArtisanPackUI\Site\Exceptions\RecapException;
use ArtisanPackUI\Site\Models\RecapSession;

/**
 * Gathers a recap session's material before the first outline: the window,
 * every stable release in it, the pull requests merged in it, and the links
 * in the notes. Gathering up front means the AI starts from everything
 * rather than relying on it to go and look; its tools are there for what
 * the conversation brings up later.
 *
 * A link that can't be read or a failed pull request search becomes a
 * warning the outline (and the modal) can mention. A spent GitHub rate
 * limit fails the step, since the releases are the one part a recap can't
 * go without.
 *
 * @since 1.6.0
 */
final class RecapGatherer
{
    /**
     * The most links read from the notes.
     */
    public const MAX_NOTE_LINKS = 10;

    public function __construct(
        private readonly RecapWindowResolver $windows,
        private readonly RecapReleaseCollector $releases,
        private readonly MergedPullRequestSearch $pullRequests,
        private readonly RecapSourceReader $sources,
    ) {}

    /**
     * Fill in the session's window, releases, pull requests and sources
     * (unsaved).
     *
     * @throws GitHubRateLimitException
     */
    public function gather(RecapSession $session): void
    {
        $window   = $this->windows->for($session->publishDate());
        $releases = $this->releases->collect($window);
        $warnings = $releases['warnings'];

        try {
            $pullRequests = $this->pullRequests->search($window);
        } catch (GitHubRateLimitException $exception) {
            throw $exception;
        } catch (GitHubException $exception) {
            $pullRequests = [];
            $warnings[]   = __('Merged pull requests couldn\'t be searched: :message', ['message' => $exception->getMessage()]);
        }

        $sources = [];

        foreach (self::links((string) $session->notes) as $url) {
            try {
                $sources[] = $this->sources->read($url);
            } catch (RecapException|GitHubException $exception) {
                $warnings[] = __(':url couldn\'t be read: :message', ['url' => $url, 'message' => $exception->getMessage()]);
            }
        }

        $session->window        = $window->toArray();
        $session->releases      = $releases['releases'];
        $session->pull_requests = $pullRequests;
        $session->warnings      = $warnings;
        $session->mergeSources($sources);
    }

    /**
     * The http(s) links in the notes, in order, without trailing
     * punctuation, at most {@see self::MAX_NOTE_LINKS}.
     *
     * @return list<string>
     */
    public static function links(string $notes): array
    {
        preg_match_all('#https?://[^\s<>()"\']+#i', $notes, $matches);

        $links = array_map(static fn (string $url): string => rtrim($url, '.,;:!?'), $matches[0]);

        return array_slice(array_values(array_unique($links)), 0, self::MAX_NOTE_LINKS);
    }
}
