/** @type {import('tailwindcss').Config} */
import colors from './extends/color'
import spacing from './extends/spacing'
import borderRadius from './extends/borderRadius'
import fontSize from './extends/fontSize'
import palette from './extends/palette';

export default {
    darkMode: ['selector', '.fluent_theme_dark'],
    // v4 resolves these from this file, not the plugin root.
    content: [
        '../../../resources/admin/**/*.{vue,js,php}',
        '../../../app/Modules/StorageDrivers/S3/VueTemplates/SettingsPage.vue',
        '../../../api/StoreSettings.php',
    ],

    theme: {
        // v3 palette as the base; extend.colors merges into it the way v3 did.
        colors: palette,
        extend: {
            colors: colors,
            borderRadius: borderRadius,
            gridTemplateColumns: {
                'dynamic': 'repeat(var(--grid-columns), minmax(0, 1fr))',
                'sm-dynamic': 'repeat(var(--sm-grid-columns, var(--grid-columns)), minmax(0, 1fr))',
                'md-dynamic': 'repeat(var(--md-grid-columns, var(--grid-columns)), minmax(0, 1fr))',
                'lg-dynamic': 'repeat(var(--lg-grid-columns, var(--grid-columns)), minmax(0, 1fr))',
            },
            gridColumn: {
                'dynamic': 'span var(--col-span) / span var(--col-span)',
            },
            borderWidth: {
                '0.5': '.5px',
            },
            screens: {
                '1xl': '1360px',      // override default or add new
                '2xl': '1536px',      // override default or add new
                '3xl': '1920px',      // another custom screen
            },
        },
        fontFamily: {
            'display': ['Inter'],
            'body': ['Inter'],
        },
        spacing: spacing,
        fontSize: fontSize,


    },

}
