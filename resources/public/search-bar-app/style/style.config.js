/** @type {import('tailwindcss').Config} */
import colors from '../../../styles/tailwind/extends/color'
import spacing from "../../../styles/tailwind/extends/spacing";
import borderRadius from "../../../styles/tailwind/extends/borderRadius";
import fontSize from "../../../styles/tailwind/extends/fontSize";

module.exports = {
    darkMode: 'class',
    content: [
        './app/FC/Template/DefaultTemplate/Views/search-bar-app/wrapper.php',
    ],
    plugins: [
        require('@tailwindcss/container-queries'),
    ],
    theme: {
        extend: {
            colors: colors,
            borderRadius: borderRadius,
            borderWidth: {
                '0.5': '.5px',
            }
        },
        spacing: spacing,
        fontSize: fontSize,

    },
}

