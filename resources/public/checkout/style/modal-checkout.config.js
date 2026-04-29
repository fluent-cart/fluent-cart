/** @type {import('tailwindcss').Config} */
import colors from '../../../styles/tailwind/extends/color'
import spacing from '../../../styles/tailwind/extends/spacing'
import fontSize from "../../../styles/tailwind/extends/fontSize";
import borderRadius from "../../../styles/tailwind/extends/borderRadius";
module.exports = {
  //important: '.fct-checkout-modal-container',
  content: [
    './app/FC/Template/DefaultTemplate/Views/checkout/**/*.{html,php,js}',
  ],

  theme: {
    extend: {
      colors: colors,
      borderRadius: borderRadius,
    },
    spacing: spacing,
    fontSize: fontSize,

  },
  plugins: [],
}

