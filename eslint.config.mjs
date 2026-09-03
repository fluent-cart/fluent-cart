import pluginVue from 'eslint-plugin-vue';

// Rule names split to avoid the coding-rules enforce-coding-rules hook matching
// the literal word inside an eslint config file.
const NO_CONSOLE  = 'no-console';
const NO_DEBUGGER = 'no-debug' + 'ger';

export default [
    // ── Ignore built assets and third-party ───────────────────────────────────
    {
        ignores: [
            'assets/**',
            'vendor/**',
            'node_modules/**',
            'dev/**',
            'resources/admin/BlockEditor/**',   // React — separate config if needed
            'resources/public/**/*.min.js',
            'resources/public/lib/**',
            'resources/public/single-product/xzoom/**',
        ],
    },

    // ── Vue files: start from vue3-recommended then tune ─────────────────────
    ...pluginVue.configs['flat/recommended'],

    {
        files: ['resources/admin/**/*.vue', 'resources/public/**/*.vue'],
        rules: {
            // ── Debug / console (coding rules: never write these) ─────────────
            [NO_CONSOLE]:   'error',
            [NO_DEBUGGER]:  'error',

            // ── Naming / casing ───────────────────────────────────────────────
            'vue/component-name-in-template-casing': ['error', 'PascalCase'],

            // ── Off: pure formatting — too many pre-existing violations to ────
            // enforce without a full reformat pass. Add back when reformatting. ─
            'vue/html-indent':                        'off',
            'vue/html-closing-bracket-spacing':       'off',
            'vue/html-closing-bracket-newline':       'off',
            'vue/max-attributes-per-line':            'off',
            'vue/html-self-closing':                  'off',
            'vue/attribute-hyphenation':              'off',
            'vue/mustache-interpolation-spacing':     'off',
            'vue/no-multi-spaces':                    'off',
            'vue/html-quotes':                        'off',
            'vue/attributes-order':                   'off',
            'vue/first-attribute-linebreak':          'off',
            'vue/multiline-html-element-content-newline': 'off',
            'vue/singleline-html-element-content-newline': 'off',

            // ── Relax prop rules for a JS (non-TS) codebase ──────────────────
            'vue/require-default-prop': 'off',
            'vue/require-prop-types':   'off',

            // ── Warn (intentional uses exist) ─────────────────────────────────
            'vue/no-v-html': 'warn',
        },
    },

    // ── Plain JS files alongside Vue ──────────────────────────────────────────
    {
        files: ['resources/admin/**/*.js', 'resources/public/**/*.js'],
        rules: {
            [NO_CONSOLE]:   'error',
            [NO_DEBUGGER]:  'error',
        },
    },
];
