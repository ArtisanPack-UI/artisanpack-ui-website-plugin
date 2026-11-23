<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a message asking the AI to revise a recap outline.
 * Authorization is the route's `permission:` middleware.
 *
 * @since 1.6.0
 */
final class RecapMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['message' => ['required', 'string', 'max:5000']];
    }
}
