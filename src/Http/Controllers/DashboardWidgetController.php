<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Services\Dashboard\DashboardWidgetData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard widgets' payloads, fetched by each widget once the
 * dashboard has rendered ({@see DashboardWidgetData}).
 *
 * Two actions because the stats widgets and the board widget sit behind
 * different permissions, which the routes enforce.
 *
 * @since 1.3.0
 */
final class DashboardWidgetController
{
    public function stats(Request $request, string $widget, DashboardWidgetData $data): JsonResponse
    {
        return response()->json($data->resolve($widget, $request->query()));
    }

    public function board(Request $request, DashboardWidgetData $data): JsonResponse
    {
        return response()->json($data->resolve(DashboardWidgetData::BOARD, $request->query()));
    }
}
