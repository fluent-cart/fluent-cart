import {fileURLToPath, URL} from 'node:url';
import vue from '@vitejs/plugin-vue';
import {defineConfig} from 'vitest/config';

// *.mount.test.js mount components, so their .vue files need the client build.
const MOUNT_TESTS = 'tests/js/**/*.mount.test.js';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/admin', import.meta.url)),
        },
    },
    test: {
        clearMocks: true,
        restoreMocks: true,
        testTimeout: 5000,
        env: {
            TZ: 'UTC',
        },
        projects: [
            {
                extends: true,
                test: {
                    name: 'node',
                    environment: 'node',
                    include: ['tests/js/**/*.test.js'],
                    exclude: [MOUNT_TESTS],
                },
            },
            {
                extends: true,
                test: {
                    name: 'mount',
                    environment: './tests/js/support/client-transform-env.js',
                    include: [MOUNT_TESTS],
                },
            },
        ],
    },
});
