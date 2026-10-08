<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * One package's stats on one day, written by the daily stats job (roadmap
 * 4.1) and charted on the Stats tab. A null figure means its source
 * couldn't be read that day.
 *
 * @property int          $id
 * @property int          $package_id
 * @property Carbon       $date
 * @property int|null     $downloads_daily
 * @property int|null     $downloads_monthly
 * @property int|null     $downloads_total
 * @property int|null     $stars
 * @property int|null     $forks
 * @property int|null     $watchers
 * @property int|null     $open_issues
 * @property int|null     $open_prs
 * @property int|null     $dependents
 * @property string|null  $latest_release
 * @property Carbon|null  $latest_release_at
 *
 * @since 0.4.0
 */
final class PackageStatSnapshot extends Model
{
    protected $table = 'artisanpack_ui_package_stat_snapshots';

    /** @var list<string> */
    protected $fillable = [
        'package_id', 'date',
        'downloads_daily', 'downloads_monthly', 'downloads_total',
        'stars', 'forks', 'watchers', 'open_issues', 'open_prs', 'dependents',
        'latest_release', 'latest_release_at',
    ];

    /**
     * Write a package's stats for one day, replacing that day's row if
     * there is one. Matched with `whereDate` because the `date` cast
     * stores a time part on some drivers. When a concurrent run inserts
     * the day's row between the read and the insert, the write is retried
     * once as an update of that row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(int $packageId, Carbon $date, array $attributes): self
    {
        try {
            return self::write($packageId, $date, $attributes);
        } catch (UniqueConstraintViolationException) {
            return self::write($packageId, $date, $attributes);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function write(int $packageId, Carbon $date, array $attributes): self
    {
        $snapshot = self::query()->where('package_id', $packageId)->whereDate('date', $date->toDateString())->first()
            ?? new self(['package_id' => $packageId, 'date' => $date->copy()->startOfDay()]);

        $snapshot->fill($attributes)->save();

        return $snapshot;
    }

    /**
     * @return array{date: string, downloadsDaily: int|null, downloadsMonthly: int|null, downloadsTotal: int|null, stars: int|null, forks: int|null, watchers: int|null, openIssues: int|null, openPullRequests: int|null, dependents: int|null}
     */
    public function toChartPoint(): array
    {
        return [
            'date'             => $this->date->toDateString(),
            'downloadsDaily'   => $this->downloads_daily,
            'downloadsMonthly' => $this->downloads_monthly,
            'downloadsTotal'   => $this->downloads_total,
            'stars'            => $this->stars,
            'forks'            => $this->forks,
            'watchers'         => $this->watchers,
            'openIssues'       => $this->open_issues,
            'openPullRequests' => $this->open_prs,
            'dependents'       => $this->dependents,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'package_id'        => 'integer',
            'date'              => 'date',
            'downloads_daily'   => 'integer',
            'downloads_monthly' => 'integer',
            'downloads_total'   => 'integer',
            'stars'             => 'integer',
            'forks'             => 'integer',
            'watchers'          => 'integer',
            'open_issues'       => 'integer',
            'open_prs'          => 'integer',
            'dependents'        => 'integer',
            'latest_release_at' => 'datetime',
        ];
    }
}
