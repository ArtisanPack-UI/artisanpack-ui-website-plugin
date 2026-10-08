<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Board;

use ArtisanPackUI\Site\Exceptions\GitHubException;
use ArtisanPackUI\Site\Exceptions\GitHubRateLimitException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Services\GitHub\GitHubAppClient;

/**
 * Moves a card between the board's columns (roadmap 5.2): sets, or clears,
 * a project item's Status through `updateProjectV2ItemFieldValue` /
 * `clearProjectV2ItemFieldValue`.
 *
 * Only the Status changes. Which package a card belongs to comes from its
 * issue's repo, which the board never edits. A successful move drops the
 * cached board, so the next load shows it.
 *
 * @since 1.0.0
 */
class ProjectBoardWriter
{
    private const UPDATE_MUTATION = <<<'GRAPHQL'
        mutation ($project: ID!, $item: ID!, $field: ID!, $option: String!) {
          updateProjectV2ItemFieldValue(input: {projectId: $project, itemId: $item, fieldId: $field, value: {singleSelectOptionId: $option}}) {
            projectV2Item { id }
          }
        }
        GRAPHQL;

    private const CLEAR_MUTATION = <<<'GRAPHQL'
        mutation ($project: ID!, $item: ID!, $field: ID!) {
          clearProjectV2ItemFieldValue(input: {projectId: $project, itemId: $item, fieldId: $field}) {
            projectV2Item { id }
          }
        }
        GRAPHQL;

    public function __construct(
        private readonly ProjectBoardReader $reader,
        private readonly GitHubAppClient $github,
    ) {}

    /**
     * Set the item's Status to `$optionId`, or clear it when null.
     *
     * @throws IntegrationNotConfiguredException
     * @throws GitHubException When the option isn't one of the Status options, or GitHub refuses the change.
     */
    public function moveItem(string $itemId, ?string $optionId): void
    {
        $status = $this->reader->statusField();

        if (null !== $optionId && ! $status->hasOption($optionId)) {
            // The cached options may be stale (an option was added on
            // GitHub since), so check once against fresh ones.
            $this->reader->forgetStatusField();
            $status = $this->reader->statusField();

            if (! $status->hasOption($optionId)) {
                throw new GitHubException(__('That column no longer exists on the project. Reload the board.'), 422);
            }
        }

        $variables = ['project' => $status->projectId, 'item' => $itemId, 'field' => $status->fieldId];

        try {
            null === $optionId
                ? $this->github->graphql(self::CLEAR_MUTATION, $variables)
                : $this->github->graphql(self::UPDATE_MUTATION, [...$variables, 'option' => $optionId]);
        } catch (GitHubRateLimitException $exception) {
            throw $exception;
        } catch (GitHubException $exception) {
            // The cached ids may belong to a project that was replaced.
            $this->reader->forgetStatusField();

            throw $exception;
        }

        $this->reader->forgetBoard();
    }
}
