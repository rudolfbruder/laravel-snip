import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    // Vue's runtime checks `process.env.NODE_ENV` for warnings. In a browser
    // bundle there is no `process`, so define it at build time. Replace with
    // a string literal the bundler can inline + tree-shake.
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
        '__VUE_OPTIONS_API__': 'true',
        '__VUE_PROD_DEVTOOLS__': 'false',
        '__VUE_PROD_HYDRATION_MISMATCH_DETAILS__': 'false',
    },
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
        target: 'es2019',
        lib: {
            entry: fileURLToPath(new URL('./resources/js/main.ts', import.meta.url)),
            name: 'LaravelSnip',
            formats: ['iife'],
            fileName: () => 'snip.js',
        },
        rolldownOptions: {
            output: {
                // Keep the bundled libraries' `@license` headers and the strict-mode IIFE the
                // Vite 5 build produced.
                comments: { legal: true },
                strict: true,
            },
        },
    },
});
