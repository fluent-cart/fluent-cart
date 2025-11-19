/** @type {import('tailwindcss').Config} */
import colors from './extends/color'
import spacing from './extends/spacing'
import borderRadius from './extends/borderRadius'
import fontSize from './extends/fontSize'

module.exports = {
    darkMode: 'class',
    important: '#fct_admin_app_wrapper',
    content: [
        './resources/admin/**/*.{vue,js,php}',
        './api/StoreSettings.php',
        './resources/licensing/**/*.{vue,js,php}',
    ],

    corePlugins: {
        preflight: false,
    },
    theme: {
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

