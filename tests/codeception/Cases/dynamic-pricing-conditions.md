# Dynamic pricing conditions

A pricing rule applies only when its conditions are met. A condition is three
fields — attribute, operator, value — and they are stored inside the rule's
`config` JSON as groups: the outer list is ORed, each inner list is ANDed.

Everything here needs FluentCart Pro loaded, so the tests live in the
**IntegrationPro** suite rather than Integration or Functional. Method names
restate these sentences verbatim, which is how each one is located.

## What a condition can be built from (IntegrationPro)

`tests/IntegrationPro/DynamicPricing/ConditionRegistryTest.php`

- [x] 1. Every group publishes a label, a value and children.
- [x] 2. Every attribute declares a value, an input type and operators.
- [x] 3. A select attribute names the data set its options come from.
- [x] 4. The cart subtotal is a currency attribute compared by magnitude.
- [x] 5. A flattened attribute is reachable by the key a saved condition holds.
- [x] 6. An unregistered key resolves to nothing rather than failing.
- [x] 7. An operator valid elsewhere is still rejected on the wrong attribute.
- [x] 8. Only an attribute declared multiple holds a list.
- [x] 9. Select attributes carry only the operators that mean something against an id.
- [x] 10. The published registry is a list so the picker can iterate it.
- [x] 11. The published registry carries only whitelisted fields.
- [x] 12. A resolver an add-on hangs on a definition stays on the server.
- [x] 13. An add-on can register an attribute that validation then accepts.
- [x] 14. A key registered twice keeps its first registration.
- [x] 15. A filter that forgets to return cannot take the admin down.
- [x] 16. A malformed registration is skipped rather than breaking its neighbours.

## What a condition value is allowed to be (IntegrationPro)

`tests/IntegrationPro/DynamicPricing/ConditionValueSanitizerTest.php`

Driven through the controller's real `getSafe(sanitize())` call, not by calling
the sanitizers directly — the framework consumes the sanitize map, and a map
entry that reads correctly can still be unusable.

- [x] 17. A money threshold is stored exactly as the cents it arrived as.
- [x] 18. A money threshold posted as a numeric string becomes an integer.
- [x] 19. A fractional cent is rounded to the nearest whole cent.
- [x] 20. A zero money threshold survives because it is a real value.
- [x] 21. A negative money threshold is refused.
- [x] 22. A money threshold that overflows to infinity is refused.
- [x] 23. A money threshold beyond the representable range is refused.
- [x] 24. A money threshold that is not a number is refused.
- [x] 25. A count is stored as the number it was typed as.
- [x] 26. A count keeps its decimals when it has them.
- [x] 27. A count larger than a float can hold is not quietly altered.
- [x] 28. A zero count survives because it is a real value.
- [x] 29. A count that overflows to infinity is refused.
- [x] 30. A count posted as a list is refused rather than stored as one.
- [x] 31. A multi-select stores every chosen id as an integer.
- [x] 32. A multi-select stores each id once.
- [x] 33. A multi-select drops ids that cannot identify a record.
- [x] 34. A multi-select is capped so a posted list cannot grow without bound.
- [x] 35. A select with nothing valid chosen is refused.
- [x] 83. An id list is bounded by what it reads, not by what it keeps.
- [x] 84. A text list is bounded like every other posted list.
- [x] 36. A text value is stripped of markup.
- [x] 37. A text value posted as a list keeps only its scalar entries.
- [x] 38. An empty value is refused whatever the input type.
- [x] 73. A role is stored as the slug it was picked by.
- [x] 74. A role the site does not have is refused.
- [x] 75. A real role survives alongside one the site does not have.
- [x] 76. A role chosen twice is stored once.
- [x] 77. A role condition with nothing valid chosen is refused.
- [x] 82. A role list is bounded by what it reads, not by what it keeps.

## How rows and groups survive being saved (IntegrationPro)

`tests/IntegrationPro/DynamicPricing/ConditionStructureSanitizerTest.php`

- [x] 39. The OR-of-ANDs nesting is preserved as posted.
- [x] 40. A row keeps only the three fields a condition is made of.
- [x] 41. A row naming an unregistered attribute is dropped without taking the rule with it.
- [x] 42. A row using an operator its attribute does not declare is dropped.
- [x] 43. A row whose attribute or operator was never chosen is dropped.
- [x] 44. A group left with no valid rows is dropped entirely.
- [x] 45. Surviving groups are reindexed so the stored list has no holes.
- [x] 46. A group that is not a list of rows is skipped.
- [x] 47. A row that is not a set of fields is skipped.
- [x] 48. Conditions posted as something other than a list become none.
- [x] 85. The number of groups read is bounded.
- [x] 86. The number of rows read within a group is bounded.
- [x] 89. Rows beyond the cap are never inspected.
- [x] 90. Groups beyond the cap are never inspected.
- [x] 49. The rest of the rule configuration passes through untouched.
- [x] 50. A configuration carrying no conditions at all is left alone.
- [x] 51. A configuration that is not a set of settings becomes empty.
- [x] 52. Condition sanitization actually runs on the path the controller uses.
- [x] 53. A single-value select stores the bare id rather than a list.
- [x] 54. The price type is held against the values the rule understands.
- [x] 55. Where a rule applies is held against the places that exist.
- [x] 56. The rule name is stripped of markup.

## Where a picker's options come from (IntegrationPro)

`tests/IntegrationPro/DynamicPricing/ConditionOptionSourceTest.php`

- [x] 57. Each source owns the data key its condition attribute names.
- [x] 58. Registering a source wires both its options and its permission check.
- [x] 59. A registered source answers the endpoint under its own key.
- [x] 60. A source without a key registers nothing.
- [x] 61. An absent or unusable limit falls back to a page of options.
- [x] 62. A requested limit is honoured up to the maximum and capped beyond it.
- [x] 63. Products come back as the value and label pairs the picker consumes.
- [x] 64. A products request returns no more rows than it asked for.
- [x] 65. An already-selected product is kept visible when it falls outside the page.
- [x] 66. A product pinned repeatedly is still shown once.
- [x] 67. A pinned id that names no record returns nothing.
- [x] 68. A coupon is offered under the code an administrator recognises.
- [x] 69. A customer is offered by name with their email beneath it.
- [x] 70. A customer who never gave a name is offered by email alone.
- [x] 71. An administrator is allowed every source.
- [x] 72. A visitor who is not signed in is refused every source.
- [x] 78. Roles come back as slugs rather than ids.
- [x] 79. A role search matches the name an administrator reads.
- [x] 80. A role already chosen stays visible when the search excludes it.
- [x] 81. A role that does not exist cannot be forced into the list.
- [x] 87. The pinned role list is bounded by what it reads.
- [x] 88. The role source answers the endpoint once registered.

## Mutations these were confirmed to catch

A passing test proves nothing on its own. These specific mutations were applied
to the production code and each was confirmed to turn a test red:

| Mutation | Caught by |
|---|---|
| `Helper::roundCent()` → `Helper::toCent()` | 17, 18, 19 |
| `resolvePermission()` default `false` → `true` | advanced-filter 10, 11 |
| `ProductOptions::canRead()` → `return false` | 71 |
| `withPinned()` never pins | 65 |
| the registered-role whitelist removed | 74, 75 |
| the role branch removed (roles fall to the id path) | 73, 75, 76 |
| the role list bounded on accepted values instead of read values | 82 |
| the id list bounded on accepted values instead of read values | 83 |
| the text list left unbounded | 84 |
| condition groups and rows left unbounded | 85, 86 |
| every row sanitized and the OUTPUT truncated afterwards | 89, 90 (85 and 86 still pass) |
| the pinned role list left unbounded | 87 |
| the role attribute pointed at a data key nothing answers | 57 |
| the role source registering options but not its permission hook | 88 |

The rest were written against the same standard but have not each been
individually mutation-checked.

## Not covered yet

- The pricing engine. Conditions are stored and validated, but nothing
  evaluates them against a cart yet — there is no `ConditionEvaluator`,
  `OperatorComparator` or `DynamicPricingService` to test.
- The admin UI. `CurrencyInput.vue` and `ResourceSelector.vue` have no
  automated coverage; there is no JS test runner in this repository.
- The role matrix for each option source. Expected allow or deny per role must
  come from the human-authored permissions contract, which is not written yet.
- `PricingRuleRequest::rules()` — the validation half (`required`, `maxLength`,
  the `in:` enums). Only the `sanitize()` half is exercised.
- `array_slice(..., MAX_SELECT_VALUES)` inside the option sources. The cap is
  pinned in the request sanitizer, not in the sources.
- The `array_unique()` on `include_ids` in each source. Its effect is not
  observable through the returned options — a repeated id yields one row either
  way, because the query itself deduplicates.
