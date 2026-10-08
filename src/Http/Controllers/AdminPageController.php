<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Support\AdminPages;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The plugin's landing page at `/admin/artisanpack-ui`.
 *
 * @since 1.0.0
 */
final class AdminPageController
{
    /**
     * The packages board: the global kanban of every package on the org
     * project (roadmap 5.4). The page loads the board itself, from
     * {@see BoardController::index()}.
     */
    public function board(Request $request): Response
    {
        return AdminPages::render($request, 'packages-board');
    }
}
