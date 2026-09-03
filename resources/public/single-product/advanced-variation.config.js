/** @type {import('tailwindcss').Config} */
import colors from '../../styles/tailwind/extends/color';
import spacing from '../../styles/tailwind/extends/spacing';
import borderRadius from '../../styles/tailwind/extends/borderRadius';
import fontSize from '../../styles/tailwind/extends/fontSize';

module.exports = {
    important: '',
    content: [
        './app/Modules/AdvancedVariation/Services/Renderer/AdvancedVariationRenderer.php',
        './resources/public/single-product/advanced-variations.scss',
    ],
    theme: {
        extend: {
            colors,
            spacing,
            borderRadius,
            fontSize,
        },
    },
};
