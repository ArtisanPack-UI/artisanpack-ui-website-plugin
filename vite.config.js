import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import federation from '@originjs/vite-plugin-federation';

/**
 * Module Federation REMOTE build for the ArtisanPack UI plugin's admin UI.
 *
 * Produces `dist/assets/remoteEntry.js` plus content-hashed chunks. The
 * plugin provider registers the remote's absolute URL, the host shares it as
 * an Inertia prop, and the host's federated loader resolves each
 * `plugins/artisanpack-ui/{page}` Inertia page to the matching `./{page}`
 * expose below. `./boot` is preloaded before the admin shell mounts and
 * contributes the Edit Package tabs through the host's hook filters.
 *
 * `shared` singletons align with the host's `keystoneFederationPlugin()`
 * block. Each `requiredVersion` accepts the version the host advertises, so
 * the bundle reuses the host's React, Inertia and hooks registry instead of
 * loading a second copy — the classic "Invalid hook call" trap, and a
 * private hooks registry the host never reads.
 */
export default defineConfig({
    // Relative, because the bundle is served from the plugin's asset route
    // (`/plugins/artisanpack-ui/assets/`), not the site root. With the
    // default `/` base, Vite's lazy-chunk preloader requests `/assets/…`,
    // which 404s; a relative base resolves every chunk URL against the
    // module that imports it (`import.meta.url`).
    base: './',
    plugins: [
        react(),
        federation({
            name: 'artisanpack-ui',
            filename: 'remoteEntry.js',
            exposes: {
                './boot': './resources/js/boot.tsx',
                './packages-board': './resources/js/pages/packages-board.tsx',
                './settings': './resources/js/pages/settings.tsx',
                './package-docs': './resources/js/tabs/PackageDocsTab.tsx',
                './package-stats': './resources/js/tabs/PackageStatsTab.tsx',
                './package-issues': './resources/js/tabs/PackageIssuesTab.tsx',
            },
            shared: {
                react: {
                    singleton: true,
                    requiredVersion: '^19.0.0',
                },
                'react-dom': {
                    singleton: true,
                    requiredVersion: '^19.0.0',
                },
                // A range rather than the host's advertised `^2.0.0`: the host's
                // `keystoneFederationPlugin()` shared block (vite.config.js in
                // Keystone) advertises 2.0.0 while running 3.x, and the bundle
                // only uses `usePage`, `Link` and `router`, which both majors
                // provide alike.
                '@inertiajs/react': {
                    singleton: true,
                    requiredVersion: '>=2.0.0 <4.0.0',
                },
                '@artisanpack-ui/hooks-js': {
                    singleton: true,
                    requiredVersion: '^1.0.0',
                },
            },
        }),
    ],
    build: {
        target: 'esnext',
        // Chunks go straight into `dist/assets/` (no `assetsDir`), where the
        // asset route and `plugin.json` expect them. The federation plugin
        // writes expose paths into `remoteEntry.js` as base + assetsDir +
        // chunk, resolved from `remoteEntry.js` itself, so a non-empty
        // `assetsDir` with a relative base would point at `assets/assets/…`.
        outDir: 'dist/assets',
        assetsDir: '',
        cssCodeSplit: false,
        rollupOptions: {
            input: './resources/js/federation-stub.ts',
            // The stub entry is empty by design (see federation-stub.ts), so
            // Rollup's empty-chunk warning for it is noise.
            onwarn(warning, warn) {
                if (warning.code === 'EMPTY_BUNDLE') {
                    return;
                }

                warn(warning);
            },
        },
    },
});
