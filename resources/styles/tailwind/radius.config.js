/** @type {import('tailwindcss').Config} */
import borderRadius from './extends/borderRadius';

// For stylesheets built on Tailwind's default rem spacing (checkout login and
// registration) that read the shared radius scale through theme(). Loading
// spacing.js here would turn their @apply spacing from rem into px.
module.exports = {
    content: [],
    theme: {
        extend: {
            borderRadius,
        },
    },
};
