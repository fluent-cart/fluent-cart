/** @type {import('tailwindcss').Config} */
import colors from '../../../../styles/tailwind/extends/color'
import spacing from "../../../../styles/tailwind/extends/spacing";
import fontSize from "../../../../styles/tailwind/extends/fontSize";
import borderRadius from "../../../../styles/tailwind/extends/borderRadius";

module.exports = {
    // The review blocks' own editor wrapper, the way the shop app scopes to
    // its own. Everything this sheet emits is qualified by it, so a utility
    // written for a review row cannot reach the rest of the editor.
    important: '.fct-review-list-block-editor',

    // Only the review block editors, not every block under BlockEditor/. The
    // shop app's sheet already emits the utilities its own files ask for, and
    // a second sheet scanning the same tree would emit them all again — the
    // same CSS twice, in two stylesheets that both claim importance.
    content: [
        './resources/admin/BlockEditor/ProductReviewList/**/*.*',
        './resources/admin/BlockEditor/ProductReviews/**/*.*',
    ],

    // The editor is WordPress's page, not ours. Preflight would reset its
    // chrome along with the canvas — buttons, headings and lists that
    // Gutenberg styles itself.
    corePlugins: {
        preflight: false,
    },

    theme: {
        extend: {
            colors: colors,
            borderRadius: borderRadius,
        },
        spacing: spacing,
        fontSize: fontSize,
    },
}
