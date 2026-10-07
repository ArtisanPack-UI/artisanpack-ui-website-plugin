<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Http\Controllers;

use ArtisanPackUI\Site\Support\AdminPages;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The plugin's landing page at `/admin/artisanpack-ui`.
 *
 * @since 0.2.0
 */
final class AdminPageController
{
    /**
     * The packages board. The global kanban lands in roadmap item 5.4.
     */
    public function board(Request $request): Response
    {
        return AdminPages::render($request, 'packages-board');
    }
}
