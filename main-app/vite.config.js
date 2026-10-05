import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { globSync } from 'node:fs';

// Add-on modules can ship their own entry (packages/workdo/<Module>/src/Resources/js/app.tsx)
const modulePackages = globSync('packages/workdo/*/src/Resources/js/app.tsx');

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.tsx', ...modulePackages],
            refresh: true,
        }),
        react(),
    ],
    server: {
        host: 'localhost',
        fs: { allow: ['..', 'packages'] },
        watch: { ignored: ['**/vendor/**', '**/node_modules/**'] },
    },
});
