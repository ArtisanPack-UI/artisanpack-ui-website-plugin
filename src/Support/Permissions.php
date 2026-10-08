<?php

declare(strict_types=1);

namespace ArtisanPackUI\Site\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The plugin's permissions, and the one derived Gate ability built on them.
 *
 * The permission slugs are declared in `plugin.json`, so Keystone seeds
 * them as RBAC permissions on activation and they can be granted to roles
 * separately. Admins hold all of them through the host's `Gate::before`
 * bypass. Everything the plugin exposes checks one of them:
 *
 *   - {@see self::SYNC}: everything that talks to the docs site (sync,
 *     imports, doc reorder).
 *   - {@see self::ISSUES_MANAGE}: the kanban boards and issue edits.
 *   - {@see self::STATS_VIEW}: the Stats tab and the dashboard stats widgets.
 *   - {@see self::SETTINGS_MANAGE}: edit integration settings and run
 *     connection tests. Kept apart from {@see self::SYNC} because the
 *     settings hold the credentials sync runs on.
 *
 * {@see self::ACCESS} is not a stored permission. It is a Gate ability that
 * passes when the user holds any of them, and gates the plugin's nav
 * entry and landing page so a user with only one grant can still reach it.
 *
 * Gating on the package edit screen happens client side, from the
 * {@see self::abilitiesFor()} map shared as an Inertia prop, because the
 * host's server-side panel `capability` key never runs on the dynamic
 * content-type screen (see the 0.1 spike notes in `plans/roadmap.md`).
 * The endpoints behind those tabs enforce the same permissions server side.
 *
 * @since 0.2.0
 */
final class Permissions
{
    public const SYNC = 'artisanpack-ui.sync';

    public const ISSUES_MANAGE = 'artisanpack-ui.issues.manage';

    public const STATS_VIEW = 'artisanpack-ui.stats.view';

    /**
     * @since 1.0.0
     */
    public const SETTINGS_MANAGE = 'artisanpack-ui.settings.manage';

    /**
     * Gate ability: the user holds at least one plugin permission.
     */
    public const ACCESS = 'artisanpack-ui.access';

    /**
     * Every stored permission, in the order `plugin.json` declares them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::SYNC, self::ISSUES_MANAGE, self::STATS_VIEW, self::SETTINGS_MANAGE];
    }

    /**
     * Define the {@see self::ACCESS} ability. Each permission goes back
     * through the Gate, so the host's admin bypass and RBAC resolution apply
     * exactly as they would to a direct check.
     */
    public static function defineGates(Gate $gate): void
    {
        $gate->define(self::ACCESS, static function (Authenticatable $user) use ($gate): bool {
            return $gate->forUser($user)->any(self::all());
        });
    }

    /**
     * What the given user may do, keyed for the React bundle.
     *
     * @return array{sync: bool, issuesManage: bool, statsView: bool, settingsManage: bool}
     */
    public static function abilitiesFor(Gate $gate, ?Authenticatable $user): array
    {
        $allows = static fn (string $permission): bool => null !== $user && $gate->forUser($user)->allows($permission);

        return [
            'sync'           => $allows(self::SYNC),
            'issuesManage'   => $allows(self::ISSUES_MANAGE),
            'statsView'      => $allows(self::STATS_VIEW),
            'settingsManage' => $allows(self::SETTINGS_MANAGE),
        ];
    }
}
