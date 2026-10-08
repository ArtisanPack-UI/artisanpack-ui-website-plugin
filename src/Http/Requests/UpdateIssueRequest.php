<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an edit from the board's issue modal. Every field is optional:
 * only the ones sent are changed on GitHub. A null `milestone` removes the
 * milestone, and empty `labels` / `assignees` arrays remove them all.
 * Authorization is the route's `permission:artisanpack-ui.issues.manage`
 * middleware.
 *
 * Limits follow GitHub's: 256-character titles, 65,536-character bodies,
 * 50-character label names, 39-character logins and 10 assignees.
 *
 * @since 1.0.0
 */
final class UpdateIssueRequest extends FormRequest
{
    public const MAX_BODY = 65536;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'title'        => ['sometimes', 'required', 'string', 'max:256'],
            'body'         => ['sometimes', 'nullable', 'string', 'max:' . self::MAX_BODY],
            'labels'       => ['sometimes', 'array', 'max:100'],
            'labels.*'     => ['string', 'max:50', 'distinct'],
            'milestone'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assignees'    => ['sometimes', 'array', 'max:10'],
            'assignees.*'  => ['string', 'max:39', 'distinct', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\[bot\])?$/'],
            'state'        => ['sometimes', 'string', 'in:open,closed'],
            'state_reason' => ['sometimes', 'nullable', 'string', 'in:completed,not_planned,reopened'],
        ];
    }

    /**
     * The validated changes, keyed as GitHub's REST API names them.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = $this->validated();

        if (array_key_exists('body', $changes)) {
            $changes['body'] ??= '';
        }

        return $changes;
    }
}
