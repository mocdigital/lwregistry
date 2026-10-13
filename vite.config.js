import { defineConfig } from 'vite';
import { createHash } from 'node:crypto';
import crypto from 'node:crypto';

/*
 * TEMPORARY — remove once Node is >= 20.19.
 *
 * This project declares Vite 7 and laravel-vite-plugin 2, both of which
 * require Node ^20.19 || >=22.12. The Node on this machine is 20.10, which
 * predates `crypto.hash()` (added in 20.12). @vitejs/plugin-vue 6 calls it and
 * dies with "crypto.hash is not a function".
 *
 * Downgrading was not an option: plugin-vue 5 caps at Vite 6, and
 * laravel-vite-plugin 2 peer-requires Vite 7, so matching them would mean
 * rolling back the whole build toolchain of a working app.
 *
 * `crypto.hash(alg, data, enc)` is exactly a one-shot createHash, so this is a
 * faithful stand-in rather than a workaround with different behaviour. It is a
 * no-op on a supported Node.
 */
if (typeof crypto.hash !== 'function') {
    crypto.hash = (algorithm, data, outputEncoding = 'hex') =>
        createHash(algorithm).update(data).digest(outputEncoding);
}
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from "@tailwindcss/vite";
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            // `app.js` still drives the Livewire/Volt admin; `inertia.ts` is the
            // Vue front end. Separate entries so the migration can land screen
            // by screen rather than in one cut.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/inertia.ts',
                // The blueprint canvas, loaded only by the admin page that
                // draws one — it has no business in the SPA bundle.
                'resources/js/admin/blueprint-canvas.js',
                // The live-chat console, likewise admin-only.
                'resources/js/admin/assistant-live.js',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        // The Vue sources were written against `@` -> the app root, so keep that
        // alias pointing at their new home.
        alias: {
            '@': fileURLToPath(new URL('./resources/js/spa', import.meta.url)),
        },
    },
    server: {
        cors: true,
        /*
         * Pinned rather than left to Vite's 5173-then-increment search.
         * 5173 is occupied by an unrelated project on this machine, so an
         * unpinned server lands on a different port run to run and `public/hot`
         * silently follows it. `host` is set because the default binds to the
         * IPv6 loopback and writes asset URLs as `http://[::1]:5174/...`.
         *
         * Note this port serves ASSETS ONLY — opening it directly returns 404,
         * because Laravel renders the pages now. Browse the Laravel URL.
         */
        host: 'localhost',
        port: 5174,
        strictPort: true,
    },
});
