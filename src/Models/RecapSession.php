<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Models;

use ArtisanPackUI\Site\Services\Recap\RecapPosts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One admin's weekly recap in progress: the publish date and notes, the
 * material gathered for the window, the outline conversation, and, once
 * created, the draft post.
 *
 * Saved server side so closing the modal (or the browser) doesn't lose it.
 * A session lasts {@see self::LIFETIME_DAYS} days from its last change, and
 * ends as soon as its post is created.
 *
 * Status moves `gathering` → `outlining` → `ready`, back to `outlining` for
 * each revision, then `drafting` → `created`. A failed step sets `failed`,
 * with `failed_action` saying which step to retry: `outline` or `post`.
 *
 * @property int                           $id
 * @property int                           $user_id
 * @property Carbon                        $publish_date
 * @property string                        $title
 * @property string|null                   $notes
 * @property array<string, mixed>|null     $window
 * @property list<array<string, mixed>>|null $releases
 * @property list<string>|null             $warnings
 * @property list<array<string, mixed>>|null $pull_requests
 * @property list<array{url: string, kind: string, title: string, text: string}>|null $sources
 * @property list<array{role: string, content: string}>|null $messages
 * @property string|null                   $outline
 * @property list<string>|null             $packages
 * @property string                        $status
 * @property string|null                   $failed_action
 * @property string|null                   $error
 * @property int|null                      $post_id
 * @property string|null                   $edit_url
 * @property Carbon                        $expires_at
 *
 * @since 1.6.0
 */
final class RecapSession extends Model
{
    public const STATUS_GATHERING = 'gathering';

    public const STATUS_OUTLINING = 'outlining';

    public const STATUS_READY = 'ready';

    public const STATUS_DRAFTING = 'drafting';

    public const STATUS_CREATED = 'created';

    public const STATUS_FAILED = 'failed';

    public const ACTION_OUTLINE = 'outline';

    public const ACTION_POST = 'post';

    /**
     * Days a session lasts after its last change.
     */
    public const LIFETIME_DAYS = 7;

    protected $table = 'artisanpack_ui_recap_sessions';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * Sessions that can still be resumed: not expired, post not created.
     *
     * @param  Builder<self>  $query
     *
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_CREATED)->where('expires_at', '>', now());
    }

    /**
     * Whether a job is working on the session.
     */
    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_GATHERING, self::STATUS_OUTLINING, self::STATUS_DRAFTING], true);
    }

    /**
     * Push the expiry out to {@see self::LIFETIME_DAYS} days from now.
     */
    public function touchExpiry(): void
    {
        $this->expires_at = now()->addDays(self::LIFETIME_DAYS);
    }

    /**
     * The publish date at the start of its day, in the app's timezone.
     */
    public function publishDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->publish_date->format('Y-m-d'), (string) config('app.timezone'))->startOfDay();
    }

    /**
     * Append a turn to the outline conversation.
     *
     * @param  'user'|'assistant'  $role
     */
    public function addMessage(string $role, string $content): void
    {
        $messages   = $this->messages ?? [];
        $messages[] = ['role' => $role, 'content' => $content];

        $this->messages = $messages;
    }

    /**
     * Record fetched sources, a later fetch of a URL replacing the earlier.
     *
     * @param  list<array{url: string, kind: string, title: string, text: string}>  $sources
     */
    public function mergeSources(array $sources): void
    {
        $merged = [];

        foreach ([...($this->sources ?? []), ...$sources] as $source) {
            $merged[$source['url']] = $source;
        }

        $this->sources = array_values($merged);
    }

    /**
     * The recap context the AI prompts are built from (see
     * {@see \ArtisanPackUI\Site\Services\Recap\RecapPrompts}).
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $posts = app(RecapPosts::class);

        return [
            'title'         => $this->title,
            'publishDate'   => $this->publish_date->format('Y-m-d'),
            'window'        => $this->window ?? [],
            'notes'         => (string) $this->notes,
            'releases'      => $this->releases ?? [],
            'warnings'      => $this->warnings ?? [],
            'pullRequests'  => $this->pull_requests ?? [],
            'sources'       => $this->sources ?? [],
            'styleGuide'    => IntegrationSettings::current()->recapStyleGuide(),
            'styleExamples' => $posts->styleExamples(),
            'packages'      => Package::query()->orderBy('title')->pluck('title')->filter()->map(static fn (mixed $title): string => (string) $title)->values()->all(),
            'tags'          => $posts->tagNames(),
            'messages'      => $this->messages ?? [],
            'outline'       => $this->outline,
        ];
    }

    /**
     * Delete the expired sessions.
     */
    public static function pruneExpired(): int
    {
        return self::query()->where('expires_at', '<=', now())->delete();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id'       => 'integer',
            'publish_date'  => 'date',
            'window'        => 'array',
            'releases'      => 'array',
            'warnings'      => 'array',
            'pull_requests' => 'array',
            'sources'       => 'array',
            'messages'      => 'array',
            'packages'      => 'array',
            'post_id'       => 'integer',
            'expires_at'    => 'datetime',
        ];
    }
}
