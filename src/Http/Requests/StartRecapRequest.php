<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates starting a weekly recap: the publish date (within a few weeks
 * either side of today) and the notes. Authorization is the route's
 * `permission:` middleware.
 *
 * @since 1.6.0
 */
final class StartRecapRequest extends FormRequest
{
    /**
     * How far from today the publish date may be, in days.
     */
    public const DATE_RANGE_DAYS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $today = now()->startOfDay();

        return [
            'publish_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:' . $today->copy()->subDays(self::DATE_RANGE_DAYS)->format('Y-m-d'),
                'before_or_equal:' . $today->copy()->addDays(self::DATE_RANGE_DAYS)->format('Y-m-d'),
            ],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['publish_date' => __('publish date')];
    }
}
