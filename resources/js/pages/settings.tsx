/**
 * Settings — federated remote `artisanpack-ui`, exposed as `./settings`,
 * resolved by the host at `plugins/artisanpack-ui/settings`.
 *
 * Docs site and GitHub App credentials plus connection checks land in
 * roadmap item 0.2.
 */

import { ComingSoon, PluginPage } from '../components/ui';
import type { PluginPageProps } from '../lib/types';

export default function SettingsPage({ nav }: PluginPageProps) {
    return (
        <PluginPage
            nav={nav}
            active="settings"
            title="ArtisanPack UI settings"
            description="Connections to the docs site and the ArtisanPack-UI GitHub App."
        >
            <ComingSoon title="Settings" roadmapItem="0.2" />
        </PluginPage>
    );
}
