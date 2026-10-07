<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Services\Docs;

use ArtisanPackUI\Site\Exceptions\DocsSiteException;
use ArtisanPackUI\Site\Exceptions\IntegrationNotConfiguredException;
use ArtisanPackUI\Site\Models\IntegrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Typed client for the docs site's Sanctum `/api/v1` API
 * (docs.artisanpackui.dev).
 *
 * Every call sends the API token saved on the Settings page as a bearer
 * token. The docs site gates each route on a token ability, so a token
 * missing one fails with a 403 that names it (see the `ABILITY_*`
 * constants and {@see DocsSiteException::fromResponse()}).
 *
 * `{package}` accepts the docs site's numeric id or its slug: the docs site
 * resolves an all-digit value as an id and anything else as a slug.
 *
 * Redirects are refused, so the token only ever goes to the base URL the
 * Settings page validated (see {@see \ArtisanPackUI\Site\Support\OutboundUrlPolicy}).
 *
 * Import triggers are queued on the docs site and answer `202 Accepted`;
 * they return a {@see QueuedImport}, and the outcome shows up later on the
 * package's `imports` status.
 *
 * @since 0.2.0
 */
class DocsSiteClient
{
    public const ABILITY_PACKAGES_READ = 'packages:read';

    public const ABILITY_PACKAGES_WRITE = 'packages:write';

    public const ABILITY_DOCS_READ = 'docs:read';

    public const ABILITY_DOCS_WRITE = 'docs:write';

    public const ABILITY_CHANGELOGS_READ = 'changelogs:read';

    public const ABILITY_IMPORTS_TRIGGER = 'imports:trigger';

    public function __construct(private readonly IntegrationSettings $settings) {}

    /**
     * Every package, ordered by name, optionally narrowed to one slug.
     *
     * @return list<DocsPackage>
     */
    public function packages(?string $slug = null): array
    {
        $response = $this->send('GET', '/packages', null === $slug ? [] : ['slug' => $slug], self::ABILITY_PACKAGES_READ);

        return array_map(
            static fn (array $package): DocsPackage => DocsPackage::fromArray($package),
            $this->dataList($response),
        );
    }

    /**
     * One package by id or slug.
     */
    public function package(int|string $package): DocsPackage
    {
        $response = $this->send('GET', '/packages/' . $this->segment($package), [], self::ABILITY_PACKAGES_READ);

        return DocsPackage::fromArray($this->dataObject($response));
    }

    /**
     * Change some of a package's fields.
     *
     * The docs site validates `PATCH /packages/{package}` like a create, so
     * the current package is read first and `$changes` is laid over its full
     * write payload. Keys use the docs site's names (`version`,
     * `package_registry`, `icon`, …).
     *
     * @param  array<string, mixed>  $changes
     */
    public function updatePackage(int|string $package, array $changes): DocsPackage
    {
        $current = $this->package($package);

        $response = $this->send(
            'PATCH',
            '/packages/' . $current->id,
            [...$current->toWritePayload(), ...$changes],
            self::ABILITY_PACKAGES_WRITE,
        );

        return DocsPackage::fromArray($this->dataObject($response));
    }

    /**
     * A package's documentation as a tree of root nodes.
     *
     * @return list<DocsDocumentation>
     */
    public function documentation(int|string $package): array
    {
        $response = $this->send('GET', '/packages/' . $this->segment($package) . '/documentation', [], self::ABILITY_DOCS_READ);

        return array_map(
            static fn (array $node): DocsDocumentation => DocsDocumentation::fromArray($node),
            $this->dataList($response),
        );
    }

    /**
     * Save a new sibling order. Only `menu_order` changes on the docs site;
     * parents can't be moved through the API.
     *
     * @param  array<int, int>  $menuOrders  Documentation id → new `menu_order`.
     *
     * @return string The docs site's confirmation message.
     */
    public function reorderDocumentation(int|string $package, array $menuOrders): string
    {
        $items = [];

        foreach ($menuOrders as $id => $menuOrder) {
            $items[] = ['id' => (int) $id, 'menu_order' => (int) $menuOrder];
        }

        $response = $this->send(
            'POST',
            '/packages/' . $this->segment($package) . '/documentation/reorder',
            ['items' => $items],
            self::ABILITY_DOCS_WRITE,
        );

        return (string) $response->json('message', __('Documentation order updated.'));
    }

    /**
     * A package's changelog entries, newest first.
     *
     * @return list<DocsChangelog>
     */
    public function changelogs(int|string $package): array
    {
        $response = $this->send('GET', '/packages/' . $this->segment($package) . '/changelogs', [], self::ABILITY_CHANGELOGS_READ);

        return array_map(
            static fn (array $entry): DocsChangelog => DocsChangelog::fromArray($entry),
            $this->dataList($response),
        );
    }

    /**
     * Queue a documentation import from the package's docs or wiki URL.
     */
    public function importDocumentation(int|string $package): QueuedImport
    {
        return $this->triggerImport($package, 'import-docs');
    }

    /**
     * Queue a changelog import from the package's changelog URL.
     */
    public function importChangelog(int|string $package): QueuedImport
    {
        return $this->triggerImport($package, 'import-changelog');
    }

    private function triggerImport(int|string $package, string $endpoint): QueuedImport
    {
        $response = $this->send(
            'POST',
            '/packages/' . $this->segment($package) . '/' . $endpoint,
            [],
            self::ABILITY_IMPORTS_TRIGGER,
        );

        return new QueuedImport(
            queued: 202 === $response->status(),
            message: (string) $response->json('message', ''),
            package: is_string($response->json('package')) ? $response->json('package') : null,
            source: is_string($response->json('source')) ? $response->json('source') : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload  Query string for GET, JSON body otherwise.
     */
    private function send(string $method, string $path, array $payload, string $ability): Response
    {
        $request = $this->request();

        try {
            $response = $request->send($method, $path, 'GET' === $method
                ? ['query' => $payload]
                : ['json' => (object) $payload]);
        } catch (ConnectionException $exception) {
            throw DocsSiteException::unreachable((string) $this->settings->docsBaseUrl(), $exception);
        }

        if ($response->failed()) {
            throw DocsSiteException::fromResponse($response, $ability);
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        if (! $this->settings->hasDocsSite()) {
            throw IntegrationNotConfiguredException::docsSite();
        }

        return Http::baseUrl($this->settings->docsBaseUrl() . '/api/v1')
            ->withToken((string) $this->settings->docs_api_token)
            ->acceptJson()
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dataList(Response $response): array
    {
        $data = $response->json('data');

        if (! is_array($data) || ! array_is_list($data)) {
            throw DocsSiteException::unexpectedResponse($response->status());
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    private function dataObject(Response $response): array
    {
        $data = $response->json('data');

        if (! is_array($data) || array_is_list($data)) {
            throw DocsSiteException::unexpectedResponse($response->status());
        }

        return $data;
    }

    private function segment(int|string $package): string
    {
        return rawurlencode((string) $package);
    }
}
