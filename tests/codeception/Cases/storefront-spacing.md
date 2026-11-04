# Storefront spacing tokens

Storefront radius, padding and spacing read `--fct-*` tokens with the surface's
current value as the fallback, the way colours read the palette globals. Nothing
prints the tokens yet, so the Default look must not move; a store (or a later
Appearance setting) opts in by declaring a token on `:root`.

Guard: none. Check a change in the browser by comparing `getComputedStyle` with and without a token on `:root`.

## The stylesheet contract (Vitest)

- [x] 1. Every token use carries the value the surface already had.
- [x] 2. No stylesheet declares a global token.
- [x] 3. Every token is read by at least one storefront surface.
- [x] 4. No stylesheet reads a spacing-shaped token outside the known set.
- [x] 5. Where a stylesheet's Tailwind config loads the shared scales, an on-scale fallback reads the scale (`theme('spacing.3')`, `theme('borderRadius.DEFAULT')`) instead of copying its value.
- [x] 6. Every declaration that reads a token stays valid when the token is set to one length; a single-side property never reads a token as one of several values.
