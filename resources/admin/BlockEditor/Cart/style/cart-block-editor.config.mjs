/** @type {import('tailwindcss').Config} */
import colors from '../../../../styles/tailwind/extends/color'
import spacing from "../../../../styles/tailwind/extends/spacing";
import fontSize from "../../../../styles/tailwind/extends/fontSize";
import borderRadius from "../../../../styles/tailwind/extends/borderRadius";
import palette from '../../../../styles/tailwind/extends/palette';

export default {
    //important: '.fluent-cart-product-block-editor',
    // v4 resolves these from this file, not the plugin root.
    content: [
        // Buttons is a copy/paste leftover from whichever block this config was
        // cloned from — kept so nothing that currently resolves through it
        // breaks. Cart is what this config is actually named for, and both the
        // Cart and Mini Cart editor styles live there.
        '../../../../../resources/admin/BlockEditor/Buttons/**/*.*',
        '../../../../../resources/admin/BlockEditor/Cart/**/*.*',
    ],


    theme: {
        // v3 palette as the base; extend.colors merges into it the way v3 did.
        colors: palette,
        extend: {
            colors: colors,
            borderRadius: borderRadius,
            gridTemplateColumns: {
                // Simple 16 column grid
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
