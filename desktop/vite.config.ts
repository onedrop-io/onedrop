import { fileURLToPath } from 'node:url';
import babel from '@rolldown/plugin-babel';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

/**
 * The desktop app's frontend (DESK-001): its sign-in, then the web app's own pages (`@/` is resources/js), served
 * to Tauri's window on :1420 while developing and built into desktop/dist for `tauri build`.
 */
export default defineConfig({
    root: fileURLToPath(new URL('.', import.meta.url)),
    plugins: [
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        // The web app's typed routes, which its pages import, generated as for the web build.
        wayfinder({
            formVariants: true,
        }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('../resources/js', import.meta.url)),
        },
    },
    clearScreen: false,
    server: {
        port: 1420,
        strictPort: true,
        watch: {
            ignored: ['**/src-tauri/**'],
        },
    },
    // The web app's VITE_ settings, from the repository's .env.
    envDir: fileURLToPath(new URL('..', import.meta.url)),
    envPrefix: ['VITE_', 'TAURI_ENV_'],
    build: {
        outDir: 'dist',
        emptyOutDir: true,
    },
});
