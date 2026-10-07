<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Enums\ColumnType;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Managers\CustomFieldManager;
use ArtisanPackUI\CMSFramework\Modules\ContentTypes\Models\CustomField;
use Illuminate\Support\Facades\Schema;

/**
 * Registers the {@see PackageFields} on the `package` content type through
 * Keystone's custom-field manager. Idempotent: safe on every boot.
 *
 * Host glue, so it only runs inside Keystone; the field list itself is
 * covered by the plugin's test suite.
 *
 * @since 0.2.0
 */
final class PackageFieldProvisioner
{
    public function __construct(private readonly CustomFieldManager $fields) {}

    /**
     * The definitions with no `custom_fields` row on `package` yet. A single
     * query, so the provider can call it on every boot.
     *
     * @return list<array<string, mixed>>
     */
    public function missing(): array
    {
        $registered = CustomField::query()
            ->whereJsonContains('content_types', PackageFields::CONTENT_TYPE)
            ->whereIn('key', PackageFields::keys())
            ->pluck('key')
            ->all();

        return PackageFields::missing($registered);
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
     */
    public function provision(string $tableName): void
    {
        $missing = $this->missing();

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

        if ([] !== $missing) {
            $this->fields->flushFieldCache(PackageFields::CONTENT_TYPE);
        }
    }
}
