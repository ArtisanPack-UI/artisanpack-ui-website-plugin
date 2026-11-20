<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Recap;

use ArtisanPackUI\Site\Exceptions\RecapException;

/**
 * Reads a link from the recap notes: a GitHub pull request, issue, commit
 * or release through the GitHub App ({@see GitHubLinkReader}), anything
 * else as a public web page ({@see PublicUrlReader}).
 *
 * @since 1.6.0
 */
final class RecapSourceReader
{
    public function __construct(
        private readonly GitHubLinkReader $gitHub,
        private readonly PublicUrlReader $web,
    ) {}

    /**
     * @return array{url: string, kind: string, title: string, text: string}
     *
     * @throws RecapException
     */
    public function read(string $url): array
    {
        return GitHubLinkReader::handles($url) ? $this->gitHub->read($url) : $this->web->read($url);
    }
}
