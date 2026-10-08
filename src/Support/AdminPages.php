<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use ArtisanPackUI\Site\ArtisanPackUIServiceProvider;
use ArtisanPackUI\Site\Http\Controllers\AdminPageController;
use ArtisanPackUI\Site\Http\Controllers\SettingsController;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The plugin's full admin pages, each a page component from the federated
 * bundle, and the props they all share.
 *
 * {@see ArtisanPackUIServiceProvider} registers each definition with
 * Keystone's `AdminMenuManager`, which routes it under `/admin/{slug}` behind
 * `can:{capability}`. The test suite registers the same definitions the same
 * way, so the capability on each page is covered by tests.
 *
 * @phpstan-type PageDefinition array{title: string, slug: string, parent: string|null, capability: string, order: int, action: array{class-string, string}}
 *
 * @since 1.0.0
 */
final class AdminPages
{
    public const SLUG = 'artisanpack-ui';

    /**
     * The landing page (the packages board) is open to anyone holding a
     * plugin permission, so a user with a single grant can reach the
     * plugin; the board's own data and actions check
     * {@see Permissions::ISSUES_MANAGE}. Settings holds the integration
     * credentials, so it needs {@see Permissions::SETTINGS_MANAGE}.
     *
     * @return list<PageDefinition>
     */
    public static function definitions(): array
    {
        return [
            [
                'title'      => __('ArtisanPack UI'),
                'slug'       => self::SLUG,
                'parent'     => null,
                'capability' => Permissions::ACCESS,
                'order'      => 60,
                'action'     => [AdminPageController::class, 'board'],
            ],
            [
                'title'      => __('Settings'),
                'slug'       => self::SLUG . '/settings',
                'parent'     => self::SLUG,
                'capability' => Permissions::SETTINGS_MANAGE,
                'order'      => 10,
                'action'     => [SettingsController::class, 'show'],
            ],
        ];
    }

    /**
     * Render a federated admin page with the props every page shares: the
     * plugin's nav URLs and what the current user may do.
     *
     * @param  array<string, mixed>  $props
     */
    public static function render(Request $request, string $page, array $props = []): Response
    {
        return Inertia::render('plugins/' . self::SLUG . '/' . $page, [
            'nav' => [
                'board'    => url('/admin/' . self::SLUG),
                'settings' => url('/admin/' . self::SLUG . '/settings'),
            ],
            'can' => Permissions::abilitiesFor(app(Gate::class), $request->user()),
            ...$props,
        ]);
    }
}
