/** @type {import('tailwindcss').Config} */
import colors from '../../../styles/tailwind/extends/color'
import spacing from "../../../styles/tailwind/extends/spacing";
import borderRadius from "../../../styles/tailwind/extends/borderRadius";
import fontSize from "../../../styles/tailwind/extends/fontSize";

module.exports = {
    darkMode: ['selector', '.fluent_theme_dark'],
    // important: '.fluent-cart-single-product-card',
    // Nothing to scan; non-empty only to stop the empty-content warning.
    content: ['./resources/public/receipt/style/thank_you.js'],
    plugins: [
        require('@tailwindcss/container-queries'),
    ],
    theme: {
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
