import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        vue({
            customElement: true,
        }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        sourcemap: false,
        minify: 'esbuild',
        target: 'es2019',
        lib: {
            entry: fileURLToPath(new URL('./resources/js/main.ts', import.meta.url)),
            name: 'LaravelSnip',
            formats: ['iife'],
            fileName: () => 'snip.js',
        },
        rollupOptions: {
            output: {
                inlineDynamicImports: true,
            },
        },
    },
});
