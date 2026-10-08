<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What sync last wrote to one marketing package, so a later run can tell a
 * value it set from one an admin changed by hand, and how the package's
 * last sync went: when it was checked and, if it failed, why.
 *
 * @property int                                    $id
 * @property int                                    $package_id
 * @property array{set: string, name: string}|null  $last_synced_icon
 * @property Carbon|null                            $last_checked_at
 * @property string|null                            $last_error
 *
 * @since 0.3.0
 */
final class PackageSyncState extends Model
{
    protected $table = 'artisanpack_ui_package_syncs';

    /** @var list<string> */
    protected $fillable = ['package_id', 'last_synced_icon', 'last_checked_at', 'last_error'];

    /**
     * The package's row, created if it has none. `firstOrCreate()` retries
     * the read when a concurrent run inserts the row first.
     */
    public static function for(Package $package): self
    {
        return self::query()->firstOrCreate(['package_id' => $package->getKey()]);
    }

    /**
     * Record that a sync just checked the package, with the failures it
     * hit (an empty list clears the last error).
     *
     * @param  list<string>  $failures
     */
    public function recordCheck(array $failures): void
    {
        $this->last_checked_at = Carbon::now();
        $this->last_error      = [] === $failures ? null : implode("\n", $failures);
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'package_id'       => 'integer',
            'last_synced_icon' => 'array',
            'last_checked_at'  => 'datetime',
        ];
    }
}
