<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Requests;

use ArtisanPackUI\Site\Models\IntegrationSettings;
use ArtisanPackUI\Site\Support\OutboundUrlPolicy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a save from the plugin's Settings page.
 *
 * Secrets are write-only: the page never receives the stored docs API
 * token or GitHub private key, so an empty value means "keep what's
 * stored", and the `remove_*` flags clear one explicitly. Authorization is
 * the route's `permission:` middleware.
 *
 * Keeping a stored secret is only allowed while it keeps going where it
 * went before: changing the docs site's origin, or the GitHub App or
 * installation ID, requires re-entering the token or private key (see
 * {@see self::after()}). Otherwise anyone allowed to save settings could
 * point the stored token at a host they control.
 *
 * @since 1.0.0
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
     * Require the stored secret to be re-entered when the destination it
     * authenticates against changes.
     *
     * @return list<Closure(Validator): void>
     *
     * @since 1.0.0
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $settings = IntegrationSettings::current();

            if ($this->keepsStoredSecret($settings->docs_api_token, 'docs_api_token', 'remove_docs_api_token')) {
                $origin = self::origin($this->input('docs_base_url'));

                if (null !== $origin && $origin !== self::origin($settings->docsBaseUrl())) {
                    $validator->errors()->add('docs_api_token', __('Re-enter the API token when you change the docs site URL.'));
                }
            }

            if ($this->keepsStoredSecret($settings->github_private_key, 'github_private_key', 'remove_github_private_key')) {
                foreach (['github_app_id', 'github_installation_id'] as $key) {
                    $value = self::trimmedInput($this->input($key));

                    if ('' !== $value && $value !== self::trimmedInput($settings->{$key})) {
                        $validator->errors()->add('github_private_key', __('Re-enter the private key when you change the App ID or installation ID.'));

                        break;
                    }
                }
            }
        }];
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

    /**
     * Whether this save keeps a stored secret as it is: one is stored, no
     * new value was sent, and it isn't being removed.
     */
    private function keepsStoredSecret(?string $stored, string $key, string $removeKey): bool
    {
        return filled($stored) && '' === self::trimmedInput($this->input($key)) && ! $this->boolean($removeKey);
    }

    /**
     * A URL's origin (scheme, host and port), or null for a blank or
     * unparseable URL.
     */
    private static function origin(mixed $url): ?string
    {
        if (! is_string($url) || '' === trim($url)) {
            return null;
        }

        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $port   = $parts['port'] ?? ('https' === $scheme ? 443 : 80);

        return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
    }

    private static function trimmedInput(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
