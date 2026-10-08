<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Board;

/**
 * The org project's Status field: the ids a status change is written
 * against, and its options, which are the board's columns.
 *
 * @phpstan-type StatusOption array{id: string, name: string, color: string|null}
 *
 * @since 1.0.0
 */
final class ProjectStatusField
{
    /**
     * @param  string              $projectId  The project's GraphQL node id.
     * @param  string              $fieldId    The Status field's node id.
     * @param  list<StatusOption>  $options    The field's options, in the project's order.
     */
    public function __construct(
        public readonly string $projectId,
        public readonly string $fieldId,
        public readonly array $options,
    ) {}

    /**
     * Whether `$optionId` is one of the field's options.
     */
    public function hasOption(string $optionId): bool
    {
        foreach ($this->options as $option) {
            if ($option['id'] === $optionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{projectId: string, fieldId: string, options: list<StatusOption>}
     */
    public function toArray(): array
    {
        return [
            'projectId' => $this->projectId,
            'fieldId'   => $this->fieldId,
            'options'   => $this->options,
        ];
    }

    /**
     * @param  array{projectId: string, fieldId: string, options: list<StatusOption>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['projectId'], $data['fieldId'], $data['options']);
    }
}
