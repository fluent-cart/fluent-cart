# Move Email Block Editor to Pro Plugin

## Context
The Gutenberg email block editor (used for customizing email notification templates) should become a Pro-only feature. The Vue settings pages stay in the free plugin. Physical files that are purely block-editor (handler, JS, CSS) move to the pro plugin. The free plugin uses filters so pro can hook in for save/render.

---

## Phase 1: Move `FluentCartBlockEditorHandler` to Pro
**Move:** `fluent-cart/app/Hooks/Handlers/FluentCartBlockEditorHandler.php` → `fluent-cart-pro/app/Hooks/Handlers/FluentCartBlockEditorHandler.php`

### Free plugin changes:
- **`app/Hooks/actions.php` (line 45):** Remove the registration line entirely:
  ```php
  // REMOVE:
  (new \FluentCart\App\Hooks\Handlers\FluentCartBlockEditorHandler())->register();
  ```

### Pro plugin changes:
- **`fluent-cart-pro/app/Hooks/actions.php`:** Add registration:
  ```php
  (new \FluentCartPro\App\Hooks\Handlers\FluentCartBlockEditorHandler())->register();
  ```
- Update the namespace in the moved file from `FluentCart\App\Hooks\Handlers` → `FluentCartPro\App\Hooks\Handlers`
- Update any `use` imports accordingly (App, Vite, etc. → check which ones need `FluentCart\` vs `FluentCartPro\`)

This removes: iframe editor, `fct-dummy` CPT, autosave endpoint, block editor asset enqueuing — all from free.

---

## Phase 2: Move Editor Frame URL to Pro
**Free plugin:** `app/Hooks/Handlers/MenuHandler.php` (line 456)

- Remove `fct_editor_frame` from the localized data (or set to empty string `''`)

**Pro plugin:** Hook into `fluent_cart/admin_app_data` filter to add it:
```php
add_filter('fluent_cart/admin_app_data', function ($data) {
    $data['fct_editor_frame'] = site_url('?fluent_cart_block_editor=1&_fct_nonce=' . wp_create_nonce('fct_block_editor'));
    return $data;
});
```

This way pro owns the editor frame URL entirely.

---

## Phase 3: Filter on Save — `prepare_email_template_data`
**File:** `app/Http/Controllers/EmailNotificationController.php` → `update()` method (line 58-75)

- Before passing settings to `updateNotification()`, apply filter
- Strip `email_body` and force `is_default_body` to `'yes'` in the base (1st param)
- Pass original full settings as 2nd param
- Pro's filter returns data with template and `is_default_body` as the actual value

```php
$settings = Arr::get($data, 'settings', []);

$settingsWithoutTemplate = $settings;
unset($settingsWithoutTemplate['email_body']);
$settingsWithoutTemplate['is_default_body'] = 'yes';  // force default in free

$settings = apply_filters('fluent_cart/prepare_email_template_data', $settingsWithoutTemplate, $settings);

$updated = EmailNotifications::updateNotification($notification, $settings);
```

**Pro hook (in pro actions/filters):**
```php
add_filter('fluent_cart/prepare_email_template_data', function ($stripped, $full) {
    return $full; // return data with template + is_default_body as user set it
}, 10, 2);
```

---

## Phase 4: Filter on Render — Email Sending
**File:** `app/Services/Email/EmailNotificationMailer.php` → `parseEmailContent()` (line 200-239)

- Replace direct `FluentBlockParser` usage with a filter
- If filter returns empty (no pro), fall back to default template rendering

```php
// Let pro parse block content; returns empty string if no pro
$parsedBody = apply_filters('fluent_cart/parse_email_block_content', '', $body, $data);

if ($isCustom && $parsedBody) {
    // Pro parsed the blocks — use block editor template
    // ... (block_editor_template view)
} else {
    // Default template path (no pro, or not custom)
    // ... (general_template view)
}
```

**Pro hook:**
```php
add_filter('fluent_cart/parse_email_block_content', function ($parsed, $blockContent, $data) {
    return (new FluentBlockParser($data))->parse($blockContent);
}, 10, 3);
```

---

## Phase 5: Move Preview API to Pro
**Free plugin:** `app/Http/Routes/api.php` (line 677)

- Remove the preview route:
  ```php
  // REMOVE:
  $router->post('/preview', [EmailNotificationController::class, 'preview']);
  ```
- Remove `preview()` method from `EmailNotificationController.php` (or leave as empty/error)

**Pro plugin:** `fluent-cart-pro/app/Http/Routes/api.php`

- Add the preview route under same prefix/policy:
  ```php
  $router->prefix('email-notification')
      ->withPolicy('FluentCart\App\Http\Policies\StoreSensitivePolicy')
      ->group(function ($router) {
          $router->post('/preview', [EmailNotificationProController::class, 'preview']);
      });
  ```
- Create `EmailNotificationProController` (or add to existing pro controller) with the preview logic that uses `FluentBlockParser`

---

## Phase 6: Gate Vue Email Editor UI
**File:** `resources/admin/Modules/Settings/EmailNotification/EditEmailNotification.vue`

- Check `appVars.isProActive` before showing the "Customized Body" option
- Changes:
  1. **Segmented buttons (~line 281-289):** Hide or disable "Customized Body" button when not pro, show lock/upgrade indicator
  2. **Editor frame (~line 317-326):** Add `&& appVars.isProActive` to the `v-if` for `<NewEditorFrame>`
  3. **Fallback:** If `is_default_body === 'no'` without pro, show upgrade message instead of editor

---

## Phase 7: No change needed for `previewDefaultTemplate`
Default template preview renders PHP templates directly — no block parser involved. Stays in free.

---

## Summary of Changes

### Free Plugin (fluent-cart)
| File | Change |
|------|--------|
| `app/Hooks/actions.php` | Remove `FluentCartBlockEditorHandler::register()` |
| `app/Hooks/Handlers/MenuHandler.php` | Remove/empty `fct_editor_frame` |
| `app/Http/Controllers/EmailNotificationController.php` | Add `prepare_email_template_data` filter on save |
| `app/Http/Routes/api.php` | Remove `POST /preview` route |
| `app/Services/Email/EmailNotificationMailer.php` | Replace `FluentBlockParser` with `parse_email_block_content` filter |
| `EditEmailNotification.vue` | Pro gate on editor UI |

### Pro Plugin (fluent-cart-pro)
| File | Change |
|------|--------|
| `app/Hooks/Handlers/FluentCartBlockEditorHandler.php` | **Moved from free** (update namespace) |
| `app/Hooks/actions.php` | Register handler + add filters |
| `app/Http/Routes/api.php` | Add `POST email-notification/preview` route |
| New controller or method | Preview logic with `FluentBlockParser` |

## Verification
1. **Without pro:** Edit page shows only "Default Body", saving works for subject/active, default emails send correctly, no JS errors
2. **With pro:** Full block editor loads, custom templates save and render, preview works, all existing functionality unchanged
3. **Edge case:** Custom template saved with pro → pro deactivated → mailer falls back to default template (no crash)
