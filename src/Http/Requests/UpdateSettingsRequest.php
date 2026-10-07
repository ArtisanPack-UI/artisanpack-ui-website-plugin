<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Requests;

use ArtisanPackUI\Site\Support\OutboundUrlPolicy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a save from the plugin's Settings page.
 *
 * Secrets are write-only: the page never receives the stored docs API
 * token or GitHub private key, so an empty value means "keep what's
 * stored", and the `remove_*` flags clear one explicitly. Authorization is
 * the route's `permission:artisanpack-ui.sync` middleware.
 *
 * @since 0.2.0
 */
final class UpdateSettingsRequest extends FormRequest
{
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
            'docs_base_url'             => ['nullable', 'string', 'max:255', 'url:http,https', $this->outboundUrlRule()],
            'docs_api_token'            => ['nullable', 'string', 'max:2000'],
            'remove_docs_api_token'     => ['sometimes', 'boolean'],
            'github_app_id'             => ['nullable', 'string', 'max:64', 'alpha_dash:ascii'],
            'github_installation_id'    => ['nullable', 'string', 'max:20', 'regex:/^\d+$/'],
            'github_private_key'        => ['nullable', 'string', 'max:10000', $this->privateKeyRule()],
            'remove_github_private_key' => ['sometimes', 'boolean'],
            'github_organization'       => ['nullable', 'string', 'max:39', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/'],
            'github_project_number'     => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'docs_base_url'          => __('docs site URL'),
            'docs_api_token'         => __('API token'),
            'github_app_id'          => __('App ID'),
            'github_installation_id' => __('installation ID'),
            'github_private_key'     => __('private key'),
            'github_organization'    => __('organization'),
            'github_project_number'  => __('project number'),
        ];
    }

    /**
     * The server sends the docs API token to this URL, so it must pass
     * {@see OutboundUrlPolicy}. Local development may use http and
     * loopback hosts.
     */
    private function outboundUrlRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $problem = OutboundUrlPolicy::problem((string) $value, app()->isLocal());

            if (null !== $problem) {
                $fail($problem);
            }
        };
    }

    /**
     * The private key must parse as a PEM private key, so a pasted public
     * key or a truncated file is caught here rather than at the first
     * GitHub call.
     */
    private function privateKeyRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || false === openssl_pkey_get_private($value)) {
                $fail(__('The private key must be the PEM private key file GitHub generated for the App.'));
            }
        };
    }
}
