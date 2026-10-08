<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Board;

use Illuminate\Support\Carbon;

/**
 * One issue on the org project, as a board card: the project item (whose
 * id a status change targets), its Status option, the issue itself and the
 * package its repo maps to.
 *
 * @phpstan-type Label array{name: string, color: string}
 * @phpstan-type Milestone array{number: int, title: string}
 * @phpstan-type Assignee array{login: string, avatarUrl: string|null}
 * @phpstan-type PackageRef array{id: int, title: string}
 *
 * @since 1.0.0
 */
final class BoardItem
{
    /**
     * @param  string            $id         The project item's node id.
     * @param  string|null       $statusId   The item's Status option id, or null when unset.
     * @param  string            $repo       `owner/name`.
     * @param  string            $state      `OPEN` or `CLOSED`.
     * @param  list<Label>       $labels
     * @param  Milestone|null    $milestone
     * @param  list<Assignee>    $assignees
     * @param  PackageRef|null   $package    The package whose `github_repo` is `$repo`.
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $statusId,
        public readonly string $repo,
        public readonly int $number,
        public readonly string $title,
        public readonly string $url,
        public readonly string $state,
        public readonly array $labels,
        public readonly ?array $milestone,
        public readonly array $assignees,
        public readonly ?Carbon $createdAt,
        public readonly ?Carbon $updatedAt,
        public readonly ?array $package,
    ) {}

    /**
     * @return array{id: string, statusId: string|null, repo: string, number: int, title: string, url: string, state: string, labels: list<Label>, milestone: Milestone|null, assignees: list<Assignee>, createdAt: string|null, updatedAt: string|null, package: PackageRef|null}
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'statusId'  => $this->statusId,
            'repo'      => $this->repo,
            'number'    => $this->number,
            'title'     => $this->title,
            'url'       => $this->url,
            'state'     => $this->state,
            'labels'    => $this->labels,
            'milestone' => $this->milestone,
            'assignees' => $this->assignees,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'updatedAt' => $this->updatedAt?->toIso8601String(),
            'package'   => $this->package,
        ];
    }
}
