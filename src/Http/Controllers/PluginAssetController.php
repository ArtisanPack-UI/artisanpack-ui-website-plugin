<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the plugin's built federation bundle from `dist/assets/`.
 *
 * The host's Module Federation runtime fetches `remoteEntry.js` and its
 * content-hashed chunks from this route (registered by the provider as
 * `GET /plugins/artisanpack-ui/assets/{path}`). Public by design — the
 * bundle carries no secrets and must load before the admin shell can gate
 * the pages it renders — and read-only.
 *
 * `{path}` is attacker-controlled, so the resolved target is confined to the
 * plugin's real `dist/assets/` directory with a `realpath()` containment
 * check before anything is streamed. Anything that escapes the directory,
 * doesn't exist, or isn't a regular file 404s.
 *
 * Cache-Control splits by filename: everything except `remoteEntry.js` is
 * content-hashed by Vite and safe to serve `immutable`; `remoteEntry.js` is
 * the stable-URL federation manifest and gets `no-cache` + ETag revalidation
 * so an upgraded build isn't shadowed by a cached stale entry.
 */
final class PluginAssetController
{
    /**
     * MIME types the Vite federation build emits. Explicit because the
     * browser rejects an ESM bundle served as `text/plain`.
     *
     * @var array<string, string>
     */
    private const CONTENT_TYPES = [
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'css' => 'text/css',
        'json' => 'application/json',
        'map' => 'application/json',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public function __invoke(Request $request, string $path): BinaryFileResponse
    {
        $distRoot = realpath(
            base_path(config('cms.plugins.directory', 'plugins').'/artisanpack-ui/dist/assets'),
        );

        if ($distRoot === false) {
            abort(404);
        }

        $requested = realpath($distRoot.'/'.$path);

        if (
            $requested === false
            || ! str_starts_with($requested.DIRECTORY_SEPARATOR, $distRoot.DIRECTORY_SEPARATOR)
            || ! is_file($requested)
        ) {
            abort(404);
        }

        $extension = strtolower(pathinfo($requested, PATHINFO_EXTENSION));
        $isEntry = basename($requested) === 'remoteEntry.js';

        $cacheControl = $isEntry
            ? 'public, no-cache'
            : 'public, max-age=31536000, immutable';

        $response = response()->file($requested, [
            'Cache-Control' => $cacheControl,
        ]);

        if ($isEntry) {
            $response->setAutoLastModified();
            $response->setAutoEtag();

            if ($response->isNotModified($request)) {
                return $response;
            }
        }

        if (isset(self::CONTENT_TYPES[$extension])) {
            $response->headers->set('Content-Type', self::CONTENT_TYPES[$extension]);
        }

        return $response;
    }
}
