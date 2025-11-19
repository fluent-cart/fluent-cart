/** @type {import('tailwindcss').Config} */

import colors from '../../../../styles/tailwind/extends/color'
import spacing from '../../../../styles/tailwind/extends/spacing'
import fontSize from "../../../../styles/tailwind/extends/fontSize";
import borderRadius from "../../../../styles/tailwind/extends/borderRadius";
module.exports = {
    important: '.fct-custom-searchable-select',
    content: [
        './resources/public/components/select/*.{html,php,js}',
    ],

    theme: {
        extend: {
            colors: colors,
            borderRadius: borderRadius,
        },
        spacing: spacing,
        fontSize: fontSize,

    },
    plugins: [],
}

