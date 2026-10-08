<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Board;

/**
 * The org project as a kanban board (roadmap 5.1): the Status columns and
 * every issue on the project, read live from GitHub.
 *
 * The columns are a leading "No status" column (id null) followed by the
 * Status field's options in the project's order. {@see self::forRepository()}
 * scopes the board to one package's repo for the Edit Package Issues tab.
 *
 * @since 1.0.0
 */
final class Board
{
    /**
     * @param  list<BoardItem>  $items
     * @param  bool             $truncated  The project has more items than a read pages through.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly int $number,
        public readonly ProjectStatusField $status,
        public readonly array $items,
        public readonly bool $truncated = false,
    ) {}

    /**
     * The same board holding only the issues in `$repo` (`owner/name`,
     * matched case-insensitively, as GitHub does).
     */
    public function forRepository(string $repo): self
    {
        $repo = strtolower(trim($repo));

        return new self(
            $this->title,
            $this->url,
            $this->number,
            $this->status,
            array_values(array_filter($this->items, static fn (BoardItem $item): bool => strtolower($item->repo) === $repo)),
            $this->truncated,
        );
    }

    /**
     * The board for the admin UI, with the filter choices its items offer.
     *
     * @return array{project: array{title: string, url: string, number: int}, columns: list<array{id: string|null, name: string, color: string|null}>, items: list<array<string, mixed>>, milestones: list<string>, labels: list<array{name: string, color: string}>, packages: list<array{id: int, title: string}>, truncated: bool}
     */
    public function toArray(): array
    {
        $milestones = [];
        $labels     = [];
        $packages   = [];

        foreach ($this->items as $item) {
            if (null !== $item->milestone) {
                $milestones[$item->milestone['title']] = $item->milestone['title'];
            }

            foreach ($item->labels as $label) {
                $labels[$label['name']] ??= $label;
            }

            if (null !== $item->package) {
                $packages[$item->package['id']] ??= $item->package;
            }
        }

        natcasesort($milestones);
        uksort($labels, strnatcasecmp(...));
        uasort($packages, static fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));

        return [
            'project' => ['title' => $this->title, 'url' => $this->url, 'number' => $this->number],
            'columns' => [
                ['id' => null, 'name' => __('No status'), 'color' => null],
                ...$this->status->options,
            ],
            'items'      => array_map(static fn (BoardItem $item): array => $item->toArray(), $this->items),
            'milestones' => array_values($milestones),
            'labels'     => array_values($labels),
            'packages'   => array_values($packages),
            'truncated'  => $this->truncated,
        ];
    }
}
