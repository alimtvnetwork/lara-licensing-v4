import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

// Plan 06 step 79: the production console is served by @vite() in
// resources/views/app.blade.php, which resolves every tag through
// public/build/manifest.json. The build output is therefore part of the
// deployable artifact (see publish-laravel.ps1) and is pinned explicitly here
// instead of relying on plugin defaults.
export default defineConfig({
    plugins: [
        laravel({
            // The stylesheet is a first-class entry so the console still gets
            // its CSS from a <link> tag even before app.tsx executes.
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            buildDirectory: 'build',
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    build: {
        outDir: 'public/build',
        emptyOutDir: true,
        manifest: 'manifest.json',
        // Content-hashed filenames: the strict CSP from step 78 allows only
        // same-origin scripts, and immutable names let the CDN cache forever.
        rollupOptions: {
            output: {
                entryFileNames: 'assets/[name]-[hash].js',
                chunkFileNames: 'assets/[name]-[hash].js',
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
        sourcemap: false,
    },
});
