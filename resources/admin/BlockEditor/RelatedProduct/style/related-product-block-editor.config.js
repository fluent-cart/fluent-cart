/** @type {import('tailwindcss').Config} */
import colors from '../../../../styles/tailwind/extends/color'
import spacing from "../../../../styles/tailwind/extends/spacing";
import fontSize from "../../../../styles/tailwind/extends/fontSize";
import borderRadius from "../../../../styles/tailwind/extends/borderRadius";

module.exports = {
    darkMode: 'class',
    content: [
        './resources/admin/BlockEditor/RelatedProduct/**/*.*',
    ],

    safelist: [
        {
            pattern: /grid-cols-(1|2|3|4|5|6|7|8|9|10|)/,
            variants: ['sm', 'md', 'lg', 'xl'],
        },

        {
            pattern: /col-span-(1|2|3|4|5|6|7|8|9|10|)/,
            variants: ['sm', 'md', 'lg', 'xl'],
        }
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
            },
            gridColumn: {
                'dynamic': 'span var(--col-span) / span var(--col-span)',
            }
        },
        fontFamily: {
            'display': ['Inter'],
            'body': ['Inter'],
        },
        spacing: spacing,
        fontSize: fontSize,
    },
}
