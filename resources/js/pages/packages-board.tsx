/**
 * Packages board — federated remote `artisanpack-ui`, exposed as
 * `./packages-board`, resolved by the host at
 * `plugins/artisanpack-ui/packages-board`.
 *
 * The plugin's landing page at `/admin/artisanpack-ui`. The global kanban of
 * every package in the org GitHub Project lands in roadmap item 5.4.
 */

import { ComingSoon, PluginPage } from '../components/ui';
import type { PluginPageProps } from '../lib/types';

export default function PackagesBoardPage({ nav }: PluginPageProps) {
    return (
        <PluginPage
            nav={nav}
            active="board"
            title="ArtisanPack UI"
            description="Every ArtisanPack UI package's issues, across the org GitHub Project."
        >
            <ComingSoon title="Packages board" roadmapItem="5.4" />
        </PluginPage>
    );
}
