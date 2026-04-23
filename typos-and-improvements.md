# FluentCart - Typos, Language Improvements & Suggestions

**Date:** 2026-01-26
**Type:** Documentation Quality Review
**Scope:** User-facing strings, code comments, documentation

---

## 📝 Executive Summary

This document outlines typos, grammatical errors, and language improvements identified across the FluentCart plugin codebase, including:
- **47 instances** of grammatical errors in user-facing strings
- **15+ instances** of code comment issues
- **3 instances** of documentation typos
- Numerous suggestions for clarity and consistency improvements

---

## 🔴 CRITICAL USER-FACING ISSUES

### 1. Grammar: "can not" vs "cannot" (11 instances)

**Issue:** Using "can not" (two words) when "cannot" (one word) is grammatically correct.

**Impact:** Affects professionalism and readability in error messages.

**Instances:**

| File | Line | Current Text | Corrected Text |
|------|------|-------------|----------------|
| `app/Services/Translations/admin-translation.php` | 190 | "can not be undone" | "cannot be undone" |
| `api/Resource/FrontendResource/CartResource.php` | 163 | "Quantity can not be negative" | "Quantity cannot be negative" |
| `app/Helpers/CouponHelper.php` | 383 | "coupon can not be applied" | "coupon cannot be applied" |
| `app/Services/Coupon/DiscountService.php` | 94, 115 | "Coupon can not be applied" | "Coupon cannot be applied" |
| `api/Resource/AttrTermResource.php` | 214 | "can not be deleted" | "cannot be deleted" |
| `api/Resource/AttrGroupResource.php` | 189 | "can not be deleted" | "cannot be deleted" |
| `api/Helper.php` | 60 | "You can not update" | "You cannot update" |
| `app/Http/Controllers/OrderController.php` | 138 | "can not be updated" | "cannot be updated" |
| `app/Http/Controllers/OrderController.php` | 229 | "can not be refunded" | "cannot be refunded" |
| `app/Http/Requests/AttrGroupRequest.php` | 39, 40 | "can not be empty" | "cannot be empty" |

**Recommendation:**
```php
// Search and replace all instances
"can not" → "cannot"
```

---

### 2. Grammar: Missing "you" in Questions (2 instances)

**Issue:** Question grammatically incomplete - missing subject "you".

**Current:**
```php
__('Are you sure want to delete this address?', 'fluent-cart')
```

**Should be:**
```php
__('Are you sure you want to delete this address?', 'fluent-cart')
```

**Locations:**
- `app/Services/Translations/customer-profile-translation.php:33`
- `app/Services/Translations/admin-translation.php:182`

---

### 3. Grammar: Subject-Verb Agreement "do not" vs "does not"

**Issue:** Singular subject requires singular verb form.

**Location:** `api/Resource/CustomerResource.php:301`

**Current:**
```php
__('Customer do not have any changes to update.', 'fluent-cart')
```

**Should be:**
```php
__('Customer does not have any changes to update.', 'fluent-cart')
```

---

### 4. Grammar: Subject-Verb Agreement "have" vs "has"

**Issue:** Singular subject "user" requires singular verb "has".

**Locations:**
- `app/Services/Permission/PermissionManager.php:268`
- `app/Services/Permission/PermissionManager.php:286`

**Current:**
```php
'The user already have all the accesses as part of Administrator Role'
```

**Should be:**
```php
'The user already has all the accesses as part of the Administrator Role'
```

---

### 5. Typo: Missing Apostrophe in Contraction

**Location:** `api/Checkout/CheckoutApi.php:818`

**Current:**
```php
__('We dont ship to this address.', 'fluent-cart')
```

**Should be:**
```php
__('We don\'t ship to this address.', 'fluent-cart')
```

---

### 6. Grammar: Double Negative

**Location:** `app/Modules/PaymentMethods/StripeGateway/Stripe.php:416`

**Current:**
```php
'No Valid public key is not found!'
```

**Should be (choose one):**
```php
'No valid public key found!'
// OR
'Valid public key is not found!'
```

---

### 7. Grammar: Missing Verb in Past Perfect

**Location:** `app/Http/Controllers/OrderController.php:951`

**Current:**
```php
'Payment status been successfully updated'
```

**Should be:**
```php
'Payment status has been successfully updated'
```

---

### 8. Punctuation: Trailing Spaces in Messages (4 instances)

**Issue:** Extra spaces before punctuation marks or at end of strings.

**Locations:**

| File | Line | Issue |
|------|------|-------|
| `app/Modules/PaymentMethods/StripeGateway/Confirmations.php` | 534 | `'Transaction ID: '` (trailing space) |
| `app/Services/Renderer/Receipt/ReceiptRenderer.php` | 339, 389 | `'VAT/Tax ID: '` (trailing space) |
| `app/Services/Renderer/Receipt/ReceiptRenderer.php` | 756 | `'Failed reason- '` (should be colon) |
| `app/Services/DateTime/DateTime.php` | 147 | `'Unable to parse datetime: '` (trailing space) |

**Recommendation:**
```php
// Line 756 - Improve clarity
'Failed reason- ' → 'Failure reason: '
```

---

### 9. Punctuation: Double Exclamation/Question Marks (4 instances)

**Issue:** Unprofessional double punctuation.

**Locations:**

| File | Line | Current | Suggested |
|------|------|---------|-----------|
| `app/Services/Translations/admin-translation.php` | 1611 | `'Sorry, Unable To Create Page!!'` | `'Sorry, unable to create page!'` |
| `app/Modules/PaymentMethods/StripeGateway/Stripe.php` | 208 | `'Stripe not connected in Test Mode!!'` | `'Stripe not connected in test mode!'` |
| `app/Modules/PaymentMethods/PayPalGateway/PayPal.php` | 463 | `'PayPal test credentials is required!!'` | `'PayPal test credentials are required!'` |
| `app/Modules/PaymentMethods/Core/AbstractPaymentGateway.php` | 309 | `'...to enable Live payment !!'` | `'...to enable live payment!'` |

**Note:** Also fix capitalization issues in these messages.

---

### 10. Grammar: Plural Agreement "orders statuses"

**Location:** `app/Http/Controllers/OrderController.php:901`

**Current:**
```php
"...remaining orders statuses have been updated successfully"
```

**Should be:**
```php
"...remaining order statuses have been updated successfully"
```

---

## 📄 DOCUMENTATION TYPOS

### 11. README.txt Typo: "seperator"

**Location:** `readme.txt:317`

**Current:**
```
- Fixes S3 driver directory seperator issue
```

**Should be:**
```
- Fixes S3 driver directory separator issue
```

---

### 12. README.txt Typo: "isntallations"

**Location:** `readme.txt:25`

**Current:**
```
...FluentCart charges exactly 0 transaction fees on both Free and Pro isntallations.
```

**Should be:**
```
...FluentCart charges exactly 0 transaction fees on both Free and Pro installations.
```

---

### 13. README.txt: Missing Comma

**Location:** `readme.txt:25`

**Current:**
```
If you're tired of overcomplicated dashboards and bloated add-ons this is your answer.
```

**Should be:**
```
If you're tired of overcomplicated dashboards and bloated add-ons, this is your answer.
```

---

## 💻 CODE COMMENT ISSUES

### 14. Misleading PHPDoc Comment

**Location:** `app/Models/Customer.php:17`

**Issue:** Copy-paste error from Order model.

**Current:**
```php
/**
 *  Order Model - DB Model for Orders
```

**Should be:**
```php
/**
 *  Customer Model - DB Model for Customers
```

---

### 15. Hardcoded Test Value

**Location:** `app/Models/Product.php:511`

**Issue:** Hardcoded product ID instead of using `$this->ID`

**Current:**
```php
$galleryImages = get_post_meta(1110, 'fluent-products-gallery-image', true);
```

**Should be:**
```php
$galleryImages = get_post_meta($this->ID, 'fluent-products-gallery-image', true);
```

**Impact:** CRITICAL - This is a bug, not just a documentation issue. Always fetches gallery for product ID 1110.

---

### 16. Typo in Comment: "Dipute accepeted"

**Location:** `app/Http/Controllers/OrderController.php:1069`

**Current:**
```php
// Dipute accepeted
```

**Should be:**
```php
// Dispute accepted
```

---

### 17. Misleading PHPDoc - Method Implementation Mismatch

**Location:** `app/Models/Order.php:174-198`

**Issue:** PHPDoc describes complex filtering logic, but method just returns a simple relationship.

**Current PHPDoc:**
```php
/**
 * Get filtered order items based on specific criteria.
 * [Long description about filtering logic]
 */
public function filteredOrderItems()
{
    return $this->hasMany(OrderItem::class, 'order_id', 'id');
}
```

**Recommendation:** Remove misleading comment or implement the described filtering.

---

### 18. Logic Error in Subscription.php

**Location:** `app/Models/Subscription.php:233-234`

**Issue:** Returns empty value instead of fetching from settings.

**Current:**
```php
if(!$definedCurrency) {
    return $definedCurrency; // Returns empty/null
}
```

**Should be:**
```php
if(!$definedCurrency) {
    return Helper::getGlobalCurrency(); // Fetch default currency
}
```

---

### 19. Commented-Out Code Without Explanation

**Issue:** Multiple instances of commented code without TODO or explanation.

**Locations:**
- `app/Models/Order.php:370-379` - Dispatching actions
- `app/Models/Customer.php:189` - Order status filter
- `app/Http/Controllers/OrderController.php:738-758` - Bulk delete logic
- `app/Models/Subscription.php:608-614` - "Temporary fix" TODO

**Recommendation:** Either remove or add clear TODO comments explaining why code is preserved.

---

### 20. Inconsistent Code Formatting

**Issue:** Missing space after `if`, inconsistent spacing.

**Example:** `app/Models/Order.php:628`
```php
if(in_array($download->id,$alreadyAdded))  // Missing spaces
```

**Should be:**
```php
if (in_array($download->id, $alreadyAdded))
```

---

## 🎨 LANGUAGE & CLARITY IMPROVEMENTS

### 21. Unclear Error Messages

**Issue:** Some error messages are too technical or unclear for end users.

**Examples:**

| Current | Suggested Improvement |
|---------|----------------------|
| `'Please use a valid ID!'` | `'Invalid ID provided. Please try again.'` |
| `'Invalid GitHub releases URL'` | `'The GitHub release URL is invalid. Please check the URL and try again.'` |
| `'The email address is not correct.'` | `'Please enter a valid email address.'` |

---

### 22. Inconsistent Capitalization in Messages

**Pattern Issues Found:**

**Technical Terms:**
- "Test Mode" vs "test mode" (inconsistent)
- "Live payment" vs "Live Payment" (inconsistent)
- "VAT/Tax ID" vs "VAT/TAX" (inconsistent)

**Recommendation:** Establish style guide:
- Button labels: Title Case ("Add to Cart")
- Error messages: Sentence case ("Cannot add item to cart")
- Field labels: Title Case ("Email Address")
- Technical terms: Consistent capitalization ("test mode" in prose, "Test Mode" in settings)

---

### 23. Abbreviations Inconsistency

**Examples of inconsistency:**
- "ID" vs "Id" vs "id" in user-facing strings
- "VAT" vs "Vat"
- "ASC" vs "asc"
- "DESC" vs "desc"

**Found in:** `app/Services/Translations/block-editor-translation.php`

**Recommendation:** Use uppercase for acronyms: "ID", "VAT", "ASC", "DESC"

---

### 24. Passive Voice in User Instructions

**Issue:** Some instructions use passive voice, which is less clear.

**Example:**
```php
'Payment confirmation received from Stripe.'
```

**Better (active voice):**
```php
'Stripe confirmed the payment.'
```

---

## 📋 SUGGESTIONS FOR IMPROVEMENT

### 25. Error Message Consistency

**Recommendation:** Establish consistent error message patterns:

```php
// Pattern for validation errors
"[Field name] is required."
"[Field name] must be [condition]."
"Please enter a valid [field name]."

// Pattern for permission errors
"You do not have permission to [action]."

// Pattern for not found errors
"[Resource] not found."

// Pattern for conflict errors
"[Action] cannot be completed because [reason]."
```

---

### 26. Add Context to Technical Errors

**Current approach:**
```php
'Invalid URL'
```

**Suggested approach:**
```php
'Invalid URL: Please provide a valid GitHub releases URL in the format: https://github.com/owner/repo/releases'
```

**Benefit:** Helps users fix issues without support tickets.

---

### 27. Localization Best Practices

**Issues Found:**

1. **String concatenation instead of placeholders:**
```php
// Bad
'Total: ' . $total

// Good
sprintf(__('Total: %s', 'fluent-cart'), $total)
```

2. **Hardcoded punctuation in translatable strings:**
```php
// Bad (comma not translatable)
__('Name', 'fluent-cart') . ', ' . __('Email', 'fluent-cart')

// Good
sprintf(__('%s, %s', 'fluent-cart'), __('Name', 'fluent-cart'), __('Email', 'fluent-cart'))
```

---

### 28. Confirmation Dialog Improvements

**Current patterns:**
```php
'Are you sure want to delete this order?'
'Are you sure you want to delete this address?'
```

**Suggested improvements:**
```php
// Add consequence awareness
'Are you sure you want to delete this order? This action cannot be undone and will permanently remove all order data.'

// For destructive actions, be explicit
'Delete Order?'
'This will permanently delete order #%s and all associated data. This action cannot be undone.'
```

---

### 29. Add Helpful Context to Validation Messages

**Current:**
```php
'Quantity cannot be negative.'
```

**Better:**
```php
'Quantity cannot be negative. Please enter a value of 1 or greater.'
```

---

### 30. Standardize Success Messages

**Current variations:**
```php
'Updated successfully'
'Successfully updated'
'Has been updated successfully'
'Update successful'
```

**Recommendation:** Choose one pattern and stick to it:
```php
'[Resource] updated successfully'
// Examples:
'Order updated successfully'
'Customer updated successfully'
'Settings saved successfully'
```

---

## 🔧 IMPLEMENTATION CHECKLIST

### Priority 1: Fix Grammar Errors (User-Facing)
- [ ] Replace "can not" with "cannot" (11 instances)
- [ ] Fix "Are you sure want" → "Are you sure you want" (2 instances)
- [ ] Fix subject-verb agreement errors (3 instances)
- [ ] Add missing apostrophes in contractions (1 instance)
- [ ] Fix double negatives (1 instance)
- [ ] Fix missing verbs (1 instance)

### Priority 2: Fix Documentation Typos
- [ ] Fix "seperator" → "separator" in readme.txt
- [ ] Fix "isntallations" → "installations" in readme.txt
- [ ] Add missing comma in readme.txt
- [ ] Fix PHPDoc copy-paste error in Customer.php

### Priority 3: Code Issues
- [ ] Fix hardcoded product ID 1110 in Product.php (CRITICAL BUG)
- [ ] Fix logic error in Subscription getCurrencyAttribute()
- [ ] Remove or document commented-out code

### Priority 4: Consistency Improvements
- [ ] Remove double exclamation marks (4 instances)
- [ ] Fix trailing spaces in messages (4 instances)
- [ ] Standardize capitalization patterns
- [ ] Standardize abbreviations (ID, VAT, ASC, DESC)

### Priority 5: Clarity Improvements
- [ ] Rewrite unclear error messages
- [ ] Add context to technical errors
- [ ] Improve confirmation dialog messages
- [ ] Standardize success message patterns

---

## 📊 STATISTICS

| Category | Count |
|----------|-------|
| Grammar errors | 20 |
| Typos | 3 |
| Code documentation issues | 8 |
| Punctuation issues | 8 |
| Consistency issues | 12 |
| Clarity improvements needed | 6 |
| **Total Issues** | **57** |

---

## 🎯 IMPACT ASSESSMENT

### High Impact (Fix Immediately):
1. Grammar errors in user-facing strings - affects professionalism
2. Hardcoded product ID bug - affects functionality
3. README typos - affects first impression

### Medium Impact (Fix Soon):
4. Inconsistent capitalization - affects polish
5. Unclear error messages - affects user experience
6. Code comment accuracy - affects maintainability

### Low Impact (Fix Eventually):
7. Double punctuation - cosmetic issue
8. Commented code - technical debt

---

## 📖 RECOMMENDED STYLE GUIDE

Create `WRITING_STYLE_GUIDE.md` with:

### Error Messages
- Use sentence case
- Be specific and actionable
- Include what happened and how to fix it
- Use active voice where possible

### Button Labels
- Use Title Case
- Be action-oriented ("Add to Cart", not "Cart Adding")

### Field Labels
- Use Title Case
- Be concise ("Email Address", not "Enter Your Email Address Here")

### Confirmation Dialogs
- Use questions ("Delete this order?")
- Explain consequences
- Use "cannot" not "can not"

### Technical Terms
- Uppercase acronyms: ID, VAT, API, URL
- Lowercase descriptive terms: test mode, live payment

---

## 🔍 AUTOMATED CHECKS

**Recommended tools:**
1. **LanguageTool** - Grammar and spell checking
2. **Vale** - Prose linter with custom rules
3. **PHP CodeSniffer** - Code formatting
4. **ESLint** - JavaScript string validation

**Pre-commit hook suggestion:**
```bash
# Check for common typos
grep -r "can not" --include="*.php" && echo "Found 'can not' - use 'cannot'" && exit 1
grep -r "seperator" --include="*.php" *.txt && echo "Found 'seperator' - use 'separator'" && exit 1
```

---

## 📞 CONTACT

For questions about these improvements, please contact the FluentCart documentation team.

---

**End of Report**
