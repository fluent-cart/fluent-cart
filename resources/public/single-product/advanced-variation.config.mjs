/** @type {import('tailwindcss').Config} */
import colors from '../../styles/tailwind/extends/color';
import spacing from '../../styles/tailwind/extends/spacing';
import borderRadius from '../../styles/tailwind/extends/borderRadius';
import fontSize from '../../styles/tailwind/extends/fontSize';
import palette from '../../styles/tailwind/extends/palette';

// Tailwind v3 default type scale.
const v3FontSize = {
    xs: ['0.75rem', {lineHeight: '1rem'}], sm: ['0.875rem', {lineHeight: '1.25rem'}],
    base: ['1rem', {lineHeight: '1.5rem'}], lg: ['1.125rem', {lineHeight: '1.75rem'}],
    xl: ['1.25rem', {lineHeight: '1.75rem'}], '2xl': ['1.5rem', {lineHeight: '2rem'}],
    '3xl': ['1.875rem', {lineHeight: '2.25rem'}], '4xl': ['2.25rem', {lineHeight: '2.5rem'}],
    '5xl': ['3rem', {lineHeight: '1'}], '6xl': ['3.75rem', {lineHeight: '1'}],
    '7xl': ['4.5rem', {lineHeight: '1'}], '8xl': ['6rem', {lineHeight: '1'}], '9xl': ['8rem', {lineHeight: '1'}],
};

export default {
    important: '',
    // v4 resolves these from this file, not the plugin root.
    content: [
        '../../../app/Modules/AdvancedVariation/Services/Renderer/AdvancedVariationRenderer.php',
        '../../../resources/public/single-product/advanced-variations.scss',
    ],
    theme: {
        // v3 extended its default scale; replacing it here keeps the same result in v4.
        fontSize: {...v3FontSize, ...fontSize},
        // v3 palette as the base; extend.colors merges into it the way v3 did.
        colors: palette,
        extend: {
            colors,
            spacing,
            borderRadius,
        },
    },
};
