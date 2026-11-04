/** @type {import('tailwindcss').Config} */
import spacing from './extends/spacing';
import borderRadius from './extends/borderRadius';

// For stylesheets that generate no utilities but read the shared scales
// through theme(), e.g. var(--fct-btn-radius, theme('borderRadius.DEFAULT')).
module.exports = {
    // Nothing to scan; non-empty only to stop the empty-content warning.
    content: ['./resources/styles/tailwind/scales.config.js'],
    theme: {
        extend: {
            spacing,
            borderRadius,
        },
    },
};
