# Storefront colours

The Appearance setting decides where the storefront's global CSS custom
properties come from: FluentCart's own defaults, the active theme's palette, or
colours the store owner picked. Those globals cascade — every scoped FluentCart
variable is written `var(--fct-<global>, <fallback>)` — so getting the small set
right is what changes the whole store.

## Colour maths (Unit)

- [x] 1. A six-digit hex colour is read as its red, green and blue channels.
- [x] 2. A three-digit hex shorthand means the same colour as its long form.
- [x] 3. An eight-digit hex drops the alpha channel rather than being refused.
- [x] 4. An rgb() colour is read, so a palette written that way is not discarded.
- [x] 5. A colour that cannot be read returns nothing, so callers fall back instead of guessing.
- [x] 6. Mixing a colour with itself returns that colour.
- [x] 7. Mixing keeps all of the first colour at 100% and none of it at 0%.
- [x] 8. A mix percentage outside 0-100 is clamped instead of producing an impossible channel.
- [x] 9. Mixing with an unreadable colour falls back to whichever colour could be read.
- [x] 10. The contrast between a colour and itself is the lowest ratio there is.
- [x] 11. Black on white is the highest contrast there is.
- [x] 12. Contrast is the same whichever order the two colours are given in.
- [x] 13. Button text is light on a dark button and dark on a light one.
- [x] 14. A colour that cannot be read is not treated as dark.
- [x] 15. Moving a colour away from a light surface darkens it, and away from a dark surface lightens it.

## The colour registry (Integration)

- [x] 16. Every global in the registry names a real CSS custom property and a group that exists.
- [x] 18. Every semantic role a global claims is a role the theme resolver produces.
- [x] 19. No two globals write the same CSS custom property.

## Inheriting from the active theme (Integration)

- [x] 20. WordPress's own default palette is not offered as the theme's palette.
- [x] 21. A palette colour written as a CSS variable reference is passed through for the browser to resolve.
- [ ] 22. A colour the site owner edited wins over the theme's value for the same slug. <!-- not implemented: exercising origin precedence needs the theme.json data filters, not the palette filter, which replaces the whole list -->
- [x] 23. Anchors are matched to the palette slugs block themes actually publish.
- [x] 24. A theme that publishes no palette says so rather than claiming colours it does not have.

- [x] 35. Only a bare custom-property reference, or one whose sole fallback is a hex colour, is accepted; anything more elaborate is refused.
- [x] 59. A reference carrying a hex fallback is written as the reference and measured as the fallback.
- [x] 60. The site's own global background and text outrank a palette guess.
- [x] 61. Astra's numbered palette is read with Astra's meanings.
- [x] 62. Kadence's numbered palette is read with Kadence's meanings.
- [x] 63. Blocksy's numbered palette is read with Blocksy's meanings, and its hex fallbacks make it measurable.
- [x] 64. A body text the site states — in global styles or the theme's settings — is worn as given, even without a stated background; the surface then comes from the next source, and is never guessed under a background image.
- [x] 65. A palette button colour nobody chose that cannot be measured is not owned: the safe pair styles the button. One the owner chose (site editor or theme settings) is worn as given with its stated text and hover, and nothing is invented beside it.
- [x] 66. A vendor's bare reference is resolved at its source: the vendor's own API supplies its current value as the fallback.
- [x] 67. A vendor accent recoloured light gets dark button text — measured, never guessed.
- [x] 69. The site editor's button colours outrank every guess: what the owner set is what the buttons wear.
- [x] 70. A site-editor button background without a text partner gets a measured one; a button text the owner set alone is worn as given on whatever background the button otherwise gets.
- [x] 71. The secondary button keeps its outline: it borrows the stated pair's readable half as its label — and only a half that actually reads; otherwise the page text stays.
- [x] 72. A theme that states only its button colours — a background, a text, or both — is still a usable source.
- [x] 74. Stripe's payment element follows the palette: a measurable input pair seeds its appearance, and a dark pair turns on the night theme.
- [x] 75. Every measurable effective colour is readable as a plain hex through its own reader; the printed values never change shape, and no registry key is reserved.
- [x] 77. The primary button carries a hover pair the owner can set; left unset, every primary button hovers in its resting colour as it always did. (Under customize, a button background set without a hover now derives one — see 121.)
- [x] 78. Inheriting from a theme, the hover moves the button colour away from itself — darker for a light button, lighter for a dark one — and its text stays readable.
- [x] 79. The site editor's button hover outranks the derived one; a stated hover background alone gets a measured text partner.
- [x] 80. A customised button background without a text colour is written with a measured, readable partner — for the button and for its hover.
- [x] 76. Stripe's payment-method tabs follow the button: the selected tab wears the button pair, and the accent is the button background — never the pale active-state surface.
- [x] 81. Inheriting from Astra, every role follows the Theme Color setting that drives it — accent and button from Accent, text from Body Text, the surface from Content Background, the border from Borders, the hover from Link Hover — each a live palette reference with a measurable fallback, and each button half readable on its background.
- [x] 82. An Astra owner who points Accent at another swatch (or a custom hex) sees the accent and the button follow it, instead of staying on swatch 0.
- [x] 83. Button colours the Astra owner set are worn as given; a background set without its text gets a readable partner.
- [x] 84. The Astra owner's button hover pair is followed; a hover background alone gets a readable partner.
- [x] 85. A content background the Astra owner set is the surface, and the derived tones mix from it; with none, the site background is used.
- [x] 86. An Astra outline button preset states no filled button, so the button roles fall back as they did before.
- [x] 87. The site editor's button outranks the Astra settings, and Astra's hover is not paired onto it.
- [x] 88. With no Astra settings to read — Astra absent, or the reader filtered off — inheritance behaves exactly as before; FluentCart's own and customised colours are untouched by the reader.
- [x] 89. Inheriting from Blocksy, every role follows the Customizer colour that drives it — accent from Links, text from Base Text, the surface from Site Background, the border from Borders, the button and its hover from Buttons — each palette colour a live reference with a measurable fallback, and Blocksy's stated button text worn as given.
- [x] 90. A Blocksy owner who points Links at another palette colour sees the accent follow it while the button keeps its own Buttons colour — and the other way round.
- [x] 91. Button colours the Blocksy owner set are worn as given; a button text FluentCart cannot write gets a readable partner instead.
- [x] 92. The Blocksy owner's button hover pair is followed; a hover half the owner never saved takes Blocksy's own default for it.
- [x] 93. A site background the Blocksy owner set — flat or per device — is the surface, and the derived tones mix from it; a gradient background states no surface colour.
- [x] 94. A Blocksy colour saved as its inherit sentinel (CT_CSS_SKIP_RULE) is unset and takes Blocksy's default; one FluentCart cannot write (rgba()) is not stated, and the role falls back as before.
- [x] 95. The site editor's button outranks the Blocksy settings, and Blocksy's hover is not paired onto it.
- [x] 96. With no Blocksy settings to read — Blocksy absent, or the reader filtered off — inheritance behaves exactly as before; FluentCart's own and customised colours are untouched by the reader.
- [x] 97. A Blocksy palette colour is resolved by the property Blocksy prints it as — a slot's own custom variable name, and slots past the default eight — so the reference stays live and measurable.
- [x] 98. Inheriting from Kadence, every role follows the Customizer colour that drives it — accent from Links, text from Base Font, the surface from Content Background, the button and its hover from Buttons — each palette colour a live reference with a measurable fallback, Kadence's stated button text worn as given, and the border still derived.
- [x] 99. A Kadence owner who points Links at another palette colour sees the accent follow it while the button keeps its own Buttons colour — and the other way round.
- [x] 100. Button colours the Kadence owner set as custom colours are worn as given; a button text FluentCart cannot write gets a readable partner, and one the owner cleared takes Kadence's default.
- [x] 101. The Kadence owner's button hover pair is followed.
- [x] 102. A content background the Kadence owner set is the surface — desktop speaking for every device — and the derived tones mix from it; with none, the site background is used; a gradient content background states no surface colour.
- [x] 103. A Kadence colour FluentCart cannot write — a gradient button, an rgba() link, the oklch() complement palette10 — is not stated, and the role falls back as before.
- [x] 104. A Kadence palette colour is measured from the palette Kadence has active — the second or third palette as well as the first.
- [x] 105. The site editor's button outranks the Kadence settings, and Kadence's hover is not paired onto it.
- [x] 106. With no Kadence settings to read — Kadence absent, or the reader filtered off — inheritance behaves exactly as before; FluentCart's own and customised colours are untouched by the reader.
- [x] 107. A background the theme's settings state without a text colour takes the palette's text when that reads on it; only an unreadable one is replaced by a measured partner.
- [x] 108. Inheriting from Divi, the accent follows Theme Accent Color and the text Body Text Color — each a live Divi global colour with its hex as the fallback — on the white content area Divi paints, and the derived tones mix from that pair. Divi's default outline button states no filled button.
- [x] 109. A Divi owner who changes Theme Accent Color or Body Text Color sees the accent and the text follow; a value saved empty is what Divi's stylesheet treats as unset, and takes Divi's default.
- [x] 110. A filled button the Divi owner set — a solid background colour — is worn with its text; a text left empty keeps the accent Divi paints on it while that reads, otherwise a readable partner.
- [x] 111. The Divi owner's button hover pair is followed; a hover background alone keeps the resting text while it reads, and Divi's default hover tint states no hover.
- [x] 112. Divi's outline button — its transparent default, a background saved empty, or any background that is not a solid colour — states no filled button, and its hover is not stated without it.
- [x] 113. A Background Color the Divi owner set is the surface Divi paints the content area with, and the derived tones mix from it and the body text; a Background Image states no surface colour.
- [x] 114. A Divi colour FluentCart cannot write — rgb(), rgba(), a Divi 5 relative hsl() or a `$variable()$` reference — is not stated.
- [x] 115. The site editor's button outranks the Divi settings, and Divi's hover is not paired onto it.
- [x] 116. Divi, which publishes no palette, becomes a theme FluentCart can inherit from: its settings are written and the body is not marked no-colors. With the reader filtered off it behaves exactly as before — nothing written, the body marked no-colors — and FluentCart's own and customised colours are untouched by the reader.
- [x] 117. The Divi reader applies only to Divi: another Elegant Themes product sharing `et_get_option()` is not read as Divi, and Divi still gets its turn when other vendors' readers are loaded.
- [x] 118. Muted text derived from the theme always reads on the surface (WCAG 4.5:1): when the usual mix falls short it moves toward the body text, only as far as it has to; a mix that already reads is unchanged.
- [x] 119. A button text the theme states — in the site editor or its own settings — is worn exactly as given, whatever its contrast with the background. The same holds for the stated hover text. Only a text nobody chose is measured.
- [x] 120. A measured text partner reaches 4.5:1 on a mid-tone background where neither of the usual pair does, by taking pure black or white; every partner the usual pair already made readable is unchanged.
- [x] 121. Customize with a button background and no hover background derives the hover the same way inheriting does — the button moved 12% away from itself — with a readable text partner; a hover the owner set is worn, and Default still prints nothing.
- [x] 122. A theme colour written as a live reference with a hex fallback is previewed by its fallback, and the preview says nothing is missing.
- [x] 123. Inheriting from Bricks with no theme style — a fresh install, or a page no style's conditions apply to — states nothing, and inheriting stands aside exactly as before: nothing written, the body marked no-colors.
- [x] 124. A Bricks Primary color is the accent, and fills the button the way Bricks fills its default primary button; its text is the body text Bricks paints on it while that reads (4.5:1), otherwise a readable partner. Without a Primary color the Link colour is the accent. The content area is the white Bricks' stylesheet paints while the style sets no background.
- [x] 125. The body text and the site background a Bricks owner set are the text and surface, and the derived tones mix from them. On a boxed layout the content background is the surface; on a wide one Bricks does not paint it, so the site background is. A background image states no surface.
- [x] 126. A primary button background and text the Bricks owner set are worn as given, over the Primary color; a button text set for every button is worn when the primary one sets none.
- [x] 127. The Bricks owner's `:hover` button pair is followed; a hover background alone keeps the resting text while it reads, and no hover is stated without a filled button.
- [x] 128. A Bricks colour picked from the palette stays live — written as the palette property Bricks prints, with the palette colour as its measurable fallback — including a palette colour whose raw value names its own property. A colour whose palette entry is gone is the colour it saved.
- [x] 129. A Bricks colour FluentCart cannot write — a translucent rgba(), a palette colour that is translucent, a raw var() nothing measures, a gradient or a dynamic-data tag — is not stated, and a primary button background like that is not replaced by the Primary color.
- [x] 130. The theme style read is the one Bricks chose for the page by its conditions: a style for one post outranks the site-wide one there and nowhere else, a page no style applies to states nothing, and when Bricks applies several styles the most specific one that sets a colour wins, as in its stylesheet. Where no page is being shown (the settings preview) Bricks is asked for the site-wide style, and its own choice is left as it was.
- [x] 131. The site editor's button outranks the Bricks theme style, and the style's hover is not paired onto it.
- [x] 132. Bricks, which publishes no palette, becomes a theme FluentCart can inherit from once a theme style states colours: they are written and the body is not marked no-colors. With the reader filtered off it behaves exactly as before, and FluentCart's own and customised colours are untouched by the reader.
- [x] 133. The Bricks reader applies only while Bricks runs — a loaded but idle Bricks does not claim — and Bricks gets its turn when other vendors' readers are loaded; the filter is told the theme is 'bricks'.
- [x] 134. Inheriting from GeneratePress, the accent follows the Link colour, the text the Body text colour and the surface the Content background — each Global Color a live reference with its colour as the measurable fallback — and the button and its hover follow the Buttons colours. The border is still derived.
- [x] 135. A GeneratePress owner who points the Link colour at another Global Color moves the accent there, not the button; recolouring a Global Color moves every role that points at it; the Content area's own link colour, when set, is the accent.
- [x] 136. Custom colours a GeneratePress owner picked instead of a Global Color are worn as the hex they are.
- [x] 137. The GeneratePress owner's button and hover pair are worn — a Global Color kept live — and FluentCart's buttons compute to them. A button background or text saved empty is what GeneratePress's stylesheet paints (#55555e / #ffffff); a hover saved empty states no hover, so FluentCart derives it, and a hover text saved empty keeps the resting text while it reads.
- [x] 138. The Content background a GeneratePress owner set is the surface, with the Content text colour (else the Body text) as its text, and the derived tones mix from that pair. A Content background saved empty lets the Body background through, and that is the surface.
- [x] 139. GeneratePress's editor palette — bare `var(--slug)` references — is measured from the Global Colors GeneratePress prints, so even the palette-slot path (the reader filtered off) derives tones and owns a readable button; without GeneratePress running the references stay bare, as before.
- [x] 140. A GeneratePress colour FluentCart cannot write — rgba(), a named colour, a reference to a Global Color that does not exist — is not stated, and a set-but-unwritable Content background or button is not replaced by the next setting or the stylesheet default.
- [x] 141. The site editor's button outranks the GeneratePress settings, and GeneratePress's hover is not paired onto it.
- [x] 142. With the GeneratePress reader filtered off, inheriting behaves exactly as the palette alone makes it, whatever the settings say, and FluentCart's own and customised colours are untouched by the reader.
- [x] 143. The GeneratePress reader applies only while GeneratePress runs — a loaded but idle GeneratePress does not claim — and GeneratePress gets its turn when other vendors' readers are loaded; the filter is told the theme is 'generatepress'.
- [x] 36. When an anchor is a reference that cannot be measured, the derived tones are left unresolved.

## What gets written to the storefront (Integration)

- [x] 25. With FluentCart's own colours selected, nothing is written to the page.
- [x] 26. With colours customised, only the ones the owner actually set are written.
- [x] 27. A customised colour also writes the second spelling the modal checkout reads.
- [x] 28. With the theme selected, every global the palette can supply is written.
- [x] 29. An unrecognised stored colour key never reaches the page.
- [x] 30. A stored value that is not a colour never reaches the page.
- [x] 37. A theme with nothing to inherit from writes nothing at all, rather than pinning FluentCart's own colours.

## Marking the page for CSS (Integration)

Which source is in force is also published as a class on the storefront body, so
a stylesheet — FluentCart's, a theme's, or a store owner's — can react to it
without reading the setting.

- [ ] 46. FluentCart's own colours mark the body as FluentCart-styled.
- [ ] 47. Inheriting marks the body as customised and names the active theme.
- [ ] 48. Customising marks the body as customised without naming a theme.
- [ ] 49. A child theme is named by the theme it is built on.
- [ ] 50. A theme slug that is not safe as a class name is not written as one.
- [ ] 51. An unrecognised source is marked the same way as FluentCart's own colours.
- [x] 57. Inheriting from a theme nothing could be read from marks the body as one the theme styles itself.
- [x] 58. The no-colors marker never appears when FluentCart is writing colours.
- [x] 144. Outside the no-colors gate, every colour a product-card rule consumes carries a fallback, so the out-of-stock button's border keeps FluentCart's light grey when the theme styles the page instead of falling to the button's text colour.

## Colours live in the stylesheets, not in PHP (Integration)

Every FluentCart colour is written `var(--fct-x, <fallback>)` in the stylesheet
that uses it, so the stylesheet already holds the value. Restating those hexes
in the registry made PHP a second source of truth for the same colour — and one
that could be written to the page under the active theme's name when the theme
turned out to be unreadable, which is what made a store on Sydney look inherited
while wearing FluentCart's own palette.

- [x] 52. The registry names properties and roles, never colours.
- [x] 53. A theme whose palette reaches no anchor writes nothing at all.
- [x] 54. A role that cannot be measured is left unwritten rather than invented.
- [x] 55. A theme whose palette does reach an anchor is still written.
- [x] 56. The preview ships no colour of its own either.

## Saving the setting (Integration)

- [x] 31. An unknown colour source falls back to FluentCart's own colours.
- [x] 32. A submitted colour that is not a hex value is dropped rather than stored.
- [x] 33. A submitted key that is not in the registry is dropped rather than stored.
- [x] 34. Clearing a colour picker removes the colour instead of storing an empty one.

## The storefront preview (Integration)

- [ ] 38. The appearance settings carry a preview of the storefront.
- [ ] 39. Every surface the preview paints names a colour the registry knows.
- [ ] 41. Two colours that happen to share a semantic role stay independent in the preview.
- [ ] 42. With a theme that publishes colours, the preview shows the theme's colours.
- [ ] 43. With a theme that publishes CSS variables, the preview says it cannot show them.
- [ ] 44. With nothing to inherit from, the preview keeps FluentCart's colours and says so.
- [ ] 45. Setting the card background writes the property the product card reads.

## Border radius (Integration)

Inheriting a radius follows the same rule as inheriting a colour: the site
editor's statement first, then the active theme's own setting, and a role no
source states is not printed. Theme cases (200–249):

- [ ] 200. The site editor's button radius (`styles.elements.button.border.radius`) is the button radius.
- [ ] 201. A site-editor radius given as a preset (`var:preset|border-radius|x`) or a custom property (`var(--wp--custom--…)`) resolves to the length it names.
- [ ] 202. The site editor's text-input radius (`styles.elements.textInput.border.radius`, else `select`) is the form-input radius.
- [ ] 203. Per-corner radii that differ, or a value outside the grammar (`%`, `calc()`, a negative), state nothing.
- [ ] 204. The site editor's radius outranks the theme's own setting, role by role.
- [ ] 205. The `fluent_cart/theme/radius_roles` filter sees the theme, and what it returns is re-normalised: unknown roles and unwritable values are dropped.
- [ ] 206. Astra's button radius is read from its desktop corners and unit; an empty radius states nothing.
- [ ] 207. Astra corners that differ, or a `%` unit, state no button radius; an `em` unit is kept.
- [ ] 208. An untouched Blocksy states its own 3px button and 3px field radius.
- [ ] 209. Blocksy's button radius follows the owner's linked value (desktop of a responsive one) and its custom value; modern forms state a 0 field radius.
- [ ] 210. Kadence's button radius is its desktop size and unit; empty is its stylesheet's 3px; a `%` states nothing.
- [ ] 211. Divi's button radius is its Buttons border radius in px, 3px untouched.
- [ ] 212. Bricks' button radius is the primary button's border radius, else every button's; its form-field border radius is the input radius; a style that sets none states none.
- [ ] 213. GeneratePress, which has no radius setting, states no radius.
- [ ] 214. A theme reader the site switched off (`fluent_cart/theme/settings_roles` returning `[]`) states no radius either; the site editor's radius, which is not a reader, still counts.
- [ ] 215. A block theme's Button block radius (`styles.blocks.core/button.border.radius`) is the button radius when the button element states none.
- [ ] 216. Within one theme.json origin the button element's radius outranks the Button block's.
- [ ] 217. A `{"ref": "styles.…"}` pointer resolves against the merged theme.json data (a few hops at most); a ref is not four corners.
- [ ] 218. A spacing preset (`var:preset|spacing|x`, `var(--wp--preset--spacing--x)`) resolves through `settings.spacing.spacingSizes`; a fluid size (`min()`, `clamp()`) is dropped.
- [ ] 219. The Search block's border radius (`styles.blocks.core/search.border.radius`) is the input radius when no text-input or select element states one.
- [ ] 220. A style variation the owner picked (saved in the user origin) is read, over the theme's own values.
- [ ] 221. No theme.json path is read as the product-card radius (a featured-image radius is not a card).

What the storefront prints, and the setting behind it. Core cases (250+),
in `Integration/Services/Theme/StorefrontRadiusTest.php`:

- [x] 250. A radius value is one length — 0, or a non-negative number in px, rem or em — and anything else is refused.
- [x] 251. The radius registry names the three tokens the stylesheets read.
- [x] 252. A radius is typed as a length: a bare number means pixels, a number with px, rem or em is kept with its unit, and any other unit or text is dropped rather than stored.
- [x] 253. A negative radius is dropped; a large one, like a 9999px pill, is kept.
- [x] 254. An unknown radius key is dropped, an empty field means unset, and 0 is a real value.
- [x] 255. A stored radius is re-checked on read, so a stale or hand-edited option never reaches the page.
- [x] 256. With FluentCart's own look selected, no radius is written, even when radii are stored.
- [x] 257. Customised radii are written in px on their tokens, and an unset role is not written.
- [x] 258. Colours and radii share one :root block, and either prints without the other.
- [x] 259. Inheriting writes the radii the active theme states and nothing it does not.
- [x] 260. A filter's radii are re-checked before they are written: unknown roles and values outside the grammar are dropped.
- [x] 261. The no-colors body marker stays about colours: radii being written neither sets nor clears it.
- [x] 262. The radius setting is part of the store settings, empty by default.
- [x] 263. Saving the appearance settings with a radius the grammar refuses is rejected with a validation error, instead of saving without it.
- [x] 264. The settings preview shows the theme radii the storefront would print when inheriting, after the storefront filter.
- [x] 265. The Save button refuses to send the appearance settings while Customize is selected and a radius field is invalid, and focuses that field (Vitest).
- [x] 266. The settings form never receives a stored radius the grammar refuses: a stale or hand-edited value is dropped before the form loads.
- [x] 267. The radius fields are only validated on save while Customize is selected; under another source they are hidden, so a hidden value never blocks the save.
- [x] 268. Save only checks the radius fields while Customize is selected (Vitest).
- [x] 269. Radius errors live in the form's own validation state: an invalid edit adds one under `appearance_radius.<role>`, editing that field clears it (client or server), the field shows it, and Save refuses while one is held under Customize (Vitest).
- [x] 270. In the customer portal, Element Plus' primary (tabs, links, primary accents) wears the palette's button colour and falls back to Element Plus' blue; its lighter and darker shades mix that colour only while a palette prints (Vitest).
- [x] 271. The portal's secondary actions (Download, the dialogs' Cancel) are the plain secondary button: the palette's secondary pair, no theme link underline on a link-button, and the button radius token at every size (Vitest).
