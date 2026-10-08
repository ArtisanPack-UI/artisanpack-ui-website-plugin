/**
 * Rollup entry stub.
 *
 * `@originjs/vite-plugin-federation` emits the real `remoteEntry.js` (and the
 * per-expose chunks) via `emitFile`, but it never sets
 * `build.rollupOptions.input`. Without an explicit input Vite falls back to
 * its `index.html` app default, which a headless federation remote has no
 * reason to ship — so the build fails with "Could not resolve entry module
 * index.html". Pointing the input at this empty module gives Rollup a valid
 * entry; the resulting chunk is inert and the host never loads it (it only
 * fetches `remoteEntry.js`).
 */

export {};
