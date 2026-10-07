<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What sync last wrote to one marketing package, so a later run can tell a
 * value it set from one an admin changed by hand.
 *
 * @property int                                    $id
 * @property int                                    $package_id
 * @property array{set: string, name: string}|null  $last_synced_icon
 *
 * @since 0.3.0
 */
final class PackageSyncState extends Model
{
    protected $table = 'artisanpack_ui_package_syncs';

    /** @var list<string> */
    protected $fillable = ['package_id', 'last_synced_icon'];

    public static function for(Package $package): self
    {
        return self::query()->firstOrNew(['package_id' => $package->getKey()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'package_id'       => 'integer',
            'last_synced_icon' => 'array',
        ];
    }
}
