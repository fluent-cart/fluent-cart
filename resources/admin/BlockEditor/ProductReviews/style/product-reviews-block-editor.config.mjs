/** @type {import('tailwindcss').Config} */
import colors from '../../../../styles/tailwind/extends/color'
import spacing from "../../../../styles/tailwind/extends/spacing";
import fontSize from "../../../../styles/tailwind/extends/fontSize";
import borderRadius from "../../../../styles/tailwind/extends/borderRadius";
import palette from '../../../../styles/tailwind/extends/palette';

export default {
    // v4 resolves these from this file, not the plugin root.
    content: [
        '../../../../../resources/admin/BlockEditor/ProductReviews/**/*.*',
    ],

    theme: {
        // v3 palette as the base; extend.colors merges into it the way v3 did.
        colors: palette,
        extend: {
            colors: colors,
            borderRadius: borderRadius,
        },
        spacing: spacing,
        fontSize: fontSize,
    },
}
