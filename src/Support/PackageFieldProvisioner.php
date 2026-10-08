<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Enums\ColumnType;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Managers\CustomFieldManager;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Models\CustomField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Registers the {@see PackageFields} on the `package` content type through
 * Keystone's custom-field manager, and brings a registered field's type up
 * to date when its definition changes. Idempotent: safe on every boot.
 *
 * Host glue, so it only runs inside Keystone; the field list itself is
 * covered by the plugin's test suite.
 *
 * @since 1.0.0
 */
final class PackageFieldProvisioner
{
    public function __construct(private readonly CustomFieldManager $fields) {}

    /**
     * Whether any field is missing its `custom_fields` row or registered
     * with an outdated type. A single query, so the provider can call it on
     * every boot.
     */
    public function isOutdated(): bool
    {
        $registered = $this->registeredTypes();

        return [] !== PackageFields::missing(array_keys($registered))
            || [] !== PackageFields::retyped($registered);
    }

    /**
     * Register every missing field on the `package` records table: add its
     * column, then its `custom_fields` row.
     *
     * This deliberately skips `CustomFieldManager::createField()`, which
     * wraps the row and the `ALTER TABLE` in one transaction. On MySQL the
     * DDL implicitly commits that transaction, so the closing commit throws
     * "There is no active transaction" after both writes already landed
     * (the same trap as cms-framework#333). Column first, row second means
     * a run that dies in between leaves a column with no row, which the
     * next boot finishes by creating only the row, keeping the column and
     * any data in it.
     *
     * Rows registered with an outdated type are updated in place; the
     * column and its data are untouched.
     */
    public function provision(string $tableName): void
    {
        $registered = $this->registeredTypes();
        $missing    = PackageFields::missing(array_keys($registered));
        $retyped    = PackageFields::retyped($registered);

        foreach ($missing as $definition) {
            $field = new CustomField([
                'name'          => $definition['name'],
                'key'           => $definition['key'],
                'type'          => $definition['type'],
                'column_type'   => ColumnType::from($definition['column_type']),
                'description'   => $definition['description'],
                'content_types' => [PackageFields::CONTENT_TYPE],
                'options'       => $definition['options'] ?? null,
                'order'         => $definition['order'],
                'required'      => false,
            ]);

            if (! Schema::hasColumn($tableName, $field->key)) {
                $this->fields->addColumnToTable($field, $tableName);
            }

            $field->save();
        }

        foreach ($retyped as $key => $type) {
            $this->registeredFields()->where('key', $key)->update(['type' => $type]);
        }

        if ([] !== $missing || [] !== $retyped) {
            $this->fields->flushFieldCache(PackageFields::CONTENT_TYPE);
        }
    }

    /**
     * Field key → registered type, for the package fields already
     * registered.
     *
     * @return array<string, string>
     */
    private function registeredTypes(): array
    {
        return $this->registeredFields()
            ->pluck('type', 'key')
            ->map(static fn (mixed $type): string => (string) $type)
            ->all();
    }

    /**
     * @return Builder<CustomField>
     */
    private function registeredFields(): Builder
    {
        return CustomField::query()
            ->whereJsonContains('content_types', PackageFields::CONTENT_TYPE)
            ->whereIn('key', PackageFields::keys());
    }
}
