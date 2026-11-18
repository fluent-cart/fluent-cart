/** @type {import('tailwindcss').Config} */
import borderRadius from './extends/borderRadius';
import palette from './extends/palette';

// For stylesheets built on Tailwind's default rem spacing (checkout login and
// registration) that read the shared radius scale through theme(). Loading
// spacing.js here would turn their @apply spacing from rem into px.
export default {
    theme: {
        // v3 palette as the base; extend.colors merges into it the way v3 did.
        colors: palette,
        extend: {
            borderRadius,
        },
    },
};
