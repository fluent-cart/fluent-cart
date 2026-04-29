/** @type {import('tailwindcss').Config} */
import colors from '../../../../styles/tailwind/extends/color'
import spacing from "../../../../styles/tailwind/extends/spacing";
import borderRadius from "../../../../styles/tailwind/extends/borderRadius";
import fontSize from "../../../../styles/tailwind/extends/fontSize";

module.exports = {
    darkMode: 'class',
    //important: '.fluent-cart-shop-app-wrapper .fluent-cart-default-product-page-style',
    content: [
        './app/FC/Template/DefaultTemplate/Views/buttons/direct_checkout.php',
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

