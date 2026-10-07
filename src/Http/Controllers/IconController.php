<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Services\Icons\IconCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The icon picker field's catalog: the sets on offer and a page of icons
 * matching the search, each with its SVG for the preview.
 *
 * @since 0.3.0
 */
final class IconController
{
    public function index(Request $request, IconCatalog $catalog): JsonResponse
    {
        $validated = $request->validate([
            'q'    => ['nullable', 'string', 'max:100'],
            'set'  => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'sets'    => $catalog->sets(),
            'perPage' => IconCatalog::PER_PAGE,
            ...$catalog->search(
                (string) ($validated['q'] ?? ''),
                $validated['set'] ?? null,
                (int) ($validated['page'] ?? 1),
            ),
        ]);
    }
}
