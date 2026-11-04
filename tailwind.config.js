/** @type {import('tailwindcss').Config} */
export default {
    // Fallback for stylesheets without @config: they only use @apply/theme(), so
    // there is nothing to scan. A non-empty list stops the empty-content warning.
    content: ['./tailwind.config.js'],
    theme: {
        extend: {},
    },
    plugins: [],
}
