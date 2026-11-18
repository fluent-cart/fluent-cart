/** @type {import('tailwindcss').Config} */
import palette from './resources/styles/tailwind/extends/palette';

// Theme for stylesheets that have no @config of their own (Vue style blocks).
export default {
    theme: {
        // v3 palette as the base; extend.colors merges into it the way v3 did.
        colors: palette,
    },
}
