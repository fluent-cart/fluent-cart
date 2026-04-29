/** @type {import('tailwindcss').Config} */
import colors from '../../styles/tailwind/extends/color'
import spacing from "../../styles/tailwind/extends/spacing";
import borderRadius from "../../styles/tailwind/extends/borderRadius";
import fontSize from "../../styles/tailwind/extends/fontSize";

module.exports = {
    important: '',
    content: [
        './app/FC/Template/DefaultTemplate/Views/single/**/*.{html,php,js}',
    ],
    plugins: [
        require('@tailwindcss/container-queries'),
    ],
    theme: {
        extend: {
            colors: colors,
            borderRadius: borderRadius,

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

    }
}

