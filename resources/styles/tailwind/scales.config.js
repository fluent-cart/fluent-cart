/** @type {import('tailwindcss').Config} */
import spacing from './extends/spacing';
import borderRadius from './extends/borderRadius';

// For stylesheets that generate no utilities but read the shared scales
// through theme(), e.g. var(--fct-btn-radius, theme('borderRadius.DEFAULT')).
module.exports = {
    content: [],
    theme: {
        extend: {
            spacing,
            borderRadius,
        },
    },
};
