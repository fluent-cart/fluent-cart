/** @type {import('tailwindcss').Config} */
import colors from '../../../../styles/tailwind/extends/color'
import spacing from "../../../../styles/tailwind/extends/spacing";
import borderRadius from "../../../../styles/tailwind/extends/borderRadius";
import fontSize from "../../../../styles/tailwind/extends/fontSize";
import palette from '../../../../styles/tailwind/extends/palette';

export default {
    darkMode: ['selector', '.fluent_theme_dark'],
    //important: '.fluent-cart-shop-app-wrapper .fluent-cart-default-product-page-style',
    // v4 resolves these from this file, not the plugin root.
    content: [
        '../../../../../app/FC/Template/DefaultTemplate/Views/buttons/direct_checkout.php',
    ],
    theme: {
        // v3 palette as the base; extend.colors merges into it the way v3 did.
        colors: palette,
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
