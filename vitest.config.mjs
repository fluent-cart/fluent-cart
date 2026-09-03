import {fileURLToPath, URL} from 'node:url';
import vue from '@vitejs/plugin-vue';
import {defineConfig} from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/admin', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['tests/js/**/*.test.js'],
        clearMocks: true,
        restoreMocks: true,
        testTimeout: 5000,
        env: {
            TZ: 'UTC',
        },
    },
});
