/** @type {import('tailwindcss').Config} */
import spacing from './extends/spacing';
import borderRadius from './extends/borderRadius';
import palette from './extends/palette';

// For stylesheets that generate no utilities but read the shared scales
// through theme(), e.g. var(--fct-btn-radius, theme('borderRadius.DEFAULT')).
export default {
    theme: {
        // v3 palette as the base; extend.colors merges into it the way v3 did.
        colors: palette,
        extend: {
            spacing,
            borderRadius,
        },
    },
};
