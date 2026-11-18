/** @type {import('tailwindcss').Config} */
import colors from '../../../styles/tailwind/extends/color'
import spacing from "../../../styles/tailwind/extends/spacing";
import borderRadius from "../../../styles/tailwind/extends/borderRadius";
import fontSize from "../../../styles/tailwind/extends/fontSize";
import palette from '../../../styles/tailwind/extends/palette';

export default {
    darkMode: ['selector', '.fluent_theme_dark'],
    // v4 resolves these from this file, not the plugin root.
    content: [
        '../../../../resources/public/customer-profile/Vue/**/*.vue',
    ],
    theme: {
        // v3 palette as the base; extend.colors merges into it the way v3 did.
        colors: palette,
        extend: {
            colors: colors,
            borderRadius: borderRadius,
            gridTemplateColumns: {
                'dynamic': 'repeat(var(--grid-columns), minmax(0, 1fr))',
            },
            gridColumn: {
                'dynamic': 'span var(--col-span) / span var(--col-span)',
            },
            borderWidth: {
                '0.5': '.5px',
            },

            animation: {
                'fade-in': 'fadeIn 1s',
            },

            keyframes: {
                fadeIn: {
                    '0%': {opacity: '0'},
                    '100%': {opacity: '100%'},
                }
            }
        },
        spacing: spacing,
        fontSize: fontSize,

    },
}
