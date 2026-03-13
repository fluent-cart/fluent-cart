# Address Validation — Rules & Architecture

## Two Validation Contexts

There are two separate places where address validation happens. They have different rules.

### 1. Creating/Editing Address (CustomerController)

**File:** `app/Http/Controllers/FrontendControllers/CustomerController.php`
**Methods:** `validateAddressData()`, `validateAddressField()`, `validateStateField()`

This validates user INPUT when creating or editing an address via the checkout modal.

**Rules:**
- Only validate fields that are **enabled** in checkout settings (`_fc_checkout_fields`)
- `CheckoutFieldsSchema::getCheckoutFieldsRequirements()` returns only enabled fields with their requirement level
- Name fields (full_name, first_name, last_name, company_name) are excluded — validated via basic_info, not address sections
- **Country**: validate against `LocalizationManager->countries()`
- **State**: validate against the country's state list. If country field is **disabled** by admin, use store country as implicit country (user was never shown country input). If country is **enabled** but empty, do NOT assume store country.
- **Postcode**: validate format against `PostcodeVerification->isValid($value, $country)`
- **Other fields**: simple required/presence check
- Optional fields with values should still be validated (e.g., optional state provided but invalid should fail)

### 2. Selecting/Filtering Existing Addresses (AddressHelper)

**File:** `app/Helpers/AddressHelper.php`
**Method:** `getCustomerValidatedAddresses()`

This filters a customer's saved addresses to show only valid ones in the address modal/selector.

**Rules:**
- **NEVER assume store country as the address's country.** Existing addresses are validated only against data they actually have.
- If address has no country, state/postcode format validation is skipped (no country = nothing to validate against)
- If address has a country, validate state against that country's state list
- Country filter (when country field is disabled): addresses with no country OR matching store country pass; addresses with a different country are excluded
- Addresses with extra data beyond requirements should still be valid (more data != invalid)
- Name fields (full_name, first_name, last_name, company_name) are excluded from `requirementsFields` — validated via basic_info instead

## Key Principle: Store Country Fallback

| Context | Country field disabled | Country field enabled, empty |
|---|---|---|
| **Creating address** | Use store country for state/postcode validation | Do NOT assume — skip or fail naturally |
| **Filtering existing addresses** | Never assume store country for the address | Never assume store country for the address |

The store country fallback is ONLY for the creation form when the admin has disabled the country field entirely — the user never saw a country input, so the store country is the implicit country.

## Settings Structure

```php
// Stored in: fluent_cart_get_option('_fc_checkout_fields')
[
    'basic_info' => [
        'full_name'  => ['required' => 'yes', 'enabled' => 'yes'],
        'first_name' => ['required' => 'no',  'enabled' => 'no'],
        // ...
    ],
    'billing_address' => [
        'country'   => ['required' => 'yes', 'enabled' => 'yes'],
        'state'     => ['required' => 'yes', 'enabled' => 'yes'],
        'city'      => ['required' => 'yes', 'enabled' => 'yes'],
        'postcode'  => ['required' => 'yes', 'enabled' => 'yes'],
        'address_1' => ['required' => 'yes', 'enabled' => 'yes'],
        // ...
    ],
    'shipping_address' => [ /* same structure */ ],
]
```

**How to check if country field is disabled:**
```php
$fieldSettings = CheckoutFieldsSchema::getFieldsSettings();
$countryEnabled = Arr::get($fieldSettings, $type . '_address.country.enabled', 'no') === 'yes';
```

**How requirements work:**
- `getCheckoutFieldsRequirements($type, $productType, $withShipping)` returns only **enabled** fields
- Disabled fields are excluded entirely (not in the returned array)
- Values: `'required'` or `'optional'` (truthy) — falsy values are filtered out

## Name Field Fallbacks

`CustomerAddresses` model stores `first_name`/`last_name` in `meta.other_data`, not as DB columns.

Resolution order for `first_name`:
1. `$address['first_name']` (direct)
2. `$address['meta']['other_data']['first_name']` (meta)
3. Split `$address['name']` via `guessFirstNameAndLastName()`

Resolution order for `full_name`:
1. `$address['full_name']` (direct)
2. `$address['name']`
3. Concatenate meta `first_name` + `last_name`

## Locale Validation Services

- **Countries:** `LocalizationManager::getInstance()->countries()` — returns `['US' => 'United States', ...]`
- **States:** `LocalizationManager::getInstance()->statesOptions($country)` — returns `[['label' => 'Alabama', 'value' => 'AL'], ...]`
- **State codes:** `LocalizationManager::getInstance()->countryStates($country)` — returns `['AL' => 'Alabama', ...]`
- **Postcode:** `LocalizationManager::getInstance()->postcode->isValid($postcode, $country)` — returns `true` (valid or unknown format), `false` (invalid format)
- State codes are **ambiguous across countries** — always validate state+country together

## Previously Known Issues (all fixed)

- ~~AddressHelper hardcoded `'physical'` product type~~ — now uses `Arr::get($config, 'product_type')`
- ~~Country filter rejected null/empty countries~~ — now checks `!empty($addressCountry)` first
- ~~CustomerController `validateState()` had no store country fallback or optional-state validation~~ — refactored into `validateStateField()` with both
- ~~AddressHelper missing required-state, postcode format, and country code validation~~ — added via `isFieldValid()` / `isStateValid()`
- ~~Name fields (first_name etc.) included in address validation causing "First Name is required" on shipping~~ — excluded from both CustomerController and AddressHelper (validated via basic_info)

## Test Suite

**File:** `dev/test-address-validation.php`
**Run via CLI:** `wp eval-file wp-content/plugins/fluent-cart/dev/test-address-validation.php`
**Run via browser:** `?fct_test_address=1` on any page (admin only)
**Playwright:** `window.__fct_test_results` available in browser mode

12 test groups, 44 tests:

| # | Group | What it covers |
|---|---|---|
| 1 | All fields disabled | Only name required, address fields off |
| 2 | Country enabled + required | Valid/invalid/missing country |
| 3 | State validation | With/without country, cross-country mismatch, store country fallback |
| 4 | Postcode validation | Format check, required check |
| 5 | Extra data | Addresses with more data than required stay valid |
| 6 | Country filter | Disabled country field + store country matching |
| 7 | Billing type | Name fields excluded from billing requirements |
| 8 | Optional fields | Optional fields don't block when empty or filled |
| 9 | Optional state | Empty OK, valid OK, invalid still fails |
| 10 | Postcode edge cases | Countries without rules, country disabled fallback |
| 11 | Name fallbacks | full_name/first_name resolution from meta/name field |
| 12 | Multiple fields | Combined required fields, partial failures |

> **Note:** Tests validate against the behavior described in this doc.
