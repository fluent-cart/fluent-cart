# Automated Reminders Feature Flow

## Overview
The Automated Reminders feature covers three reminder families:

1. Invoice due/overdue reminders for unpaid orders.
2. Subscription renewal reminders before `next_billing_date`.
3. Trial ending reminders before trial conversion.

The system is driven by:
- Store settings (`fluent_cart_store_settings`)
- Hourly scheduler scan (`fluent_cart/scheduler/hourly_tasks`)
- Action Scheduler async callbacks
- Email notification events/templates

## Main Components
- Settings + hooks registration: `app/Hooks/Handlers/ReminderHandler.php`
- Reminder orchestration + shared state management: `app/Services/Reminders/ReminderService.php`
- Invoice reminder scanning, queueing, sending: `app/Services/Reminders/InvoiceReminderService.php`
- Subscription renewal + trial reminder scanning, queueing, sending: `app/Services/Reminders/SubscriptionReminderService.php`
- Email notification config: `app/Services/Email/EmailNotifications.php`
- Email event listener/dispatch: `app/Services/Email/EmailNotificationMailer.php`
- Email templates:
  - `app/Views/emails/order/reminder/due/customer.php`
  - `app/Views/emails/order/reminder/due/admin.php`
  - `app/Views/emails/order/reminder/overdue/customer.php`
  - `app/Views/emails/order/reminder/overdue/admin.php`
  - `app/Views/emails/subscription/reminder/customer.php`
  - `app/Views/emails/subscription/reminder/admin.php`
  - `app/Views/emails/subscription/trial_end/customer.php`
  - `app/Views/emails/subscription/trial_end/admin.php`

## Settings Contract
Store settings live in WP option key `fluent_cart_store_settings`.

### Default Keys
- `reminders_enabled` = `no` (master switch)
- `invoice_reminders_enabled` = `no`
- `invoice_reminder_due_days` = `0`
- `invoice_reminder_overdue_days` = `1,3,7`
- `yearly_renewal_reminders_enabled` = `yes`
- `yearly_renewal_reminder_days` = `30`
- `trial_end_reminders_enabled` = `yes`
- `trial_end_reminder_days` = `3`
- `monthly_renewal_reminders_enabled` = `no`
- `monthly_renewal_reminder_days` = `7`
- `quarterly_renewal_reminders_enabled` = `no`
- `quarterly_renewal_reminder_days` = `14`
- `half_yearly_renewal_reminders_enabled` = `no`
- `half_yearly_renewal_reminder_days` = `21`

### Effective Validation Rules in Service
- `invoice_reminder_due_days`: minimum `0`.
- `invoice_reminder_overdue_days`: CSV list, each day `1..365`.
- `yearly_renewal_reminder_days`: `7..90`.
- `monthly_renewal_reminder_days`: `3..28`.
- `quarterly_renewal_reminder_days`: `7..60`.
- `half_yearly_renewal_reminder_days`: `7..60`.
- `trial_end_reminder_days`: `1..14`.

## Admin UI Behavior (Scheduling Tab)
Defined by `ReminderHandler::addStoreSettingFields()`:

- Master toggle: `reminders_enabled` (`yes|no`).
- All reminder sections are conditionally shown only when `reminders_enabled = yes`.
- Invoice section includes:
  - `invoice_reminders_enabled`
  - `invoice_reminder_due_days`
  - `invoice_reminder_overdue_days`
- Subscription section includes:
  - Yearly renewal controls
  - Trial ending controls
  - Additional cycles (monthly/quarterly/half yearly)
- UI notice explicitly warns that emails must also be enabled under:
  - `Settings -> Email Notifications -> Scheduler / Reminder Actions`

## Scheduling API Flow
Scheduling is displayed under Email Configuration in admin:

- UI route: `/settings/email_mailing_settings/scheduling`
- Frontend component: `resources/admin/Modules/Settings/StoreSettings.vue`
- Frontend API behavior:
  - for `scheduling` route: uses `email-notification/scheduling`
  - for other store setting tabs: uses `settings/store`

Backend endpoints:

- `GET /wp-json/fluent-cart/v2/email-notification/scheduling`
  - controller: `EmailNotificationController::getSchedulingSettings()`
  - returns `fields.scheduling` and current store settings payload
- `POST /wp-json/fluent-cart/v2/email-notification/scheduling`
  - controller: `EmailNotificationController::saveSchedulingSettings()`
  - uses generic request payload (no `settings_name` required)
  - sanitizes values via `fluent_cart/store_settings/sanitizer`
  - saves only reminder/scheduling keys allowlisted in controller
  - does not execute `FluentMetaRequest` rule validation (so unrelated rules like `store_setup` are not applied)

Storage contract is unchanged:

- Scheduling values are still persisted in `fluent_cart_store_settings` (via `StoreSettings` API).

## Runtime Execution Flow
1. `ReminderHandler` registers:
   - Hourly scan hook: `fluent_cart/scheduler/hourly_tasks`
   - Async handlers:
     - `fluent_cart/reminders/send_invoice`
     - `fluent_cart/reminders/send_subscription_renewal`
     - `fluent_cart/reminders/send_trial_end`
2. Hourly scan calls `ReminderService::runHourlyScan()`, which delegates to `InvoiceReminderService` and `SubscriptionReminderService`.
3. Scan exits early if `reminders_enabled !== yes`.
4. `InvoiceReminderService::queueActions()` runs first (if invoice reminders enabled), then `SubscriptionReminderService::queueActions()`.
5. Scan is runtime-capped (20 seconds).
6. Matching stages are queued via Action Scheduler async actions.
   - If Action Scheduler helper functions are unavailable, reminders are sent synchronously in-process.
7. Async callbacks send reminder events:
   - `fluent_cart/invoice_reminder_due`
   - `fluent_cart/invoice_reminder_overdue`
   - `fluent_cart/subscription_renewal_reminder`
   - `fluent_cart/subscription_trial_end_reminder`
8. `EmailNotificationMailer` listens to these events and sends configured notifications.

## Eligibility and Stage Generation
### Invoice reminders
- Eligible payment statuses:
  - `pending`, `partially_paid`, `failed`, `authorized`
- Order must still have outstanding amount (`total_amount - total_paid > 0`).
- Due timestamp: `order.created_at + invoice_reminder_due_days`.
- Due stage:
  - `before_0` is queued once due time is reached.
  - This covers "Use 0 for immediate due" behavior on the Due In Days setting.
- Stage rules (time-windowed — only ONE stage active at any time):
  - Overdue days setting is sorted descending (e.g. `1,3,7` → `[7, 3, 1]`).
  - Each stage fires only within its designated window:
    - `overdue_1`: fires when `now >= dueAt + 1d` AND `now < dueAt + 3d`
    - `overdue_3`: fires when `now >= dueAt + 3d` AND `now < dueAt + 7d`
    - `overdue_7`: fires when `now >= dueAt + 7d` AND `now < dueAt + 9d` (2-day grace)
  - The window upper bound for each stage = the next larger stage's target time.
  - The largest (last) stage gets a fixed 2-day grace period for late cron, then hard stop.
  - After the last stage's grace window expires, **no more reminders** are sent for that order.
  - Example timeline (`invoice_reminder_overdue_days = 1,3,7`):
    ```
    Day 0 ──── Day 1 ──── Day 3 ──── Day 7 ── Day 9
    │ (none)   │ overdue_1 │ overdue_3 │ overdue_7│ STOP
    │          │  window   │  window   │ +2d     │
    ```
  - With `overdue_days = 7,15`:
    ```
    Day 0 ──── Day 7 ───── Day 15 ── Day 17
    │ (none)   │ overdue_7  │overdue_15│ STOP
    │          │  window    │ +2d     │
    ```

### Subscription renewal reminders
- Eligible statuses for scan:
  - `active`, `trialing`
- `next_billing_date` must exist.
- Billing cycle is derived from `billing_interval`.
- Stage rule:
  - `before_{n}` when `now >= billing_at - n days` and `now < billing_at`

### Trial ending reminders
- Subscription must be trialing and not simulated trial days:
  - `status = trialing`
  - `config.is_trial_days_simulated !== yes`
- Trial timing is based on `next_billing_date` (used as trial end timestamp).
- When trial reminders are enabled for a trialing subscription, trial flow is prioritized over renewal flow.
- Stage rule:
  - `trial_end_{n}` when `now >= trial_end_at - n days` and `now < trial_end_at`

## Billing Interval Mapping
`SubscriptionReminderService::getBillingCycle()` maps:
- `daily`, `weekly`, `monthly`, `quarterly`, `half_yearly`, `yearly`

Cycle-specific settings exist for:
- `monthly`, `quarterly`, `half_yearly`, `yearly`

`daily` and `weekly` cycles are intentionally skipped for renewal reminders.
Unknown/unsupported cycles are also skipped for renewal reminders.

## Queueing and Dedupe
### Meta keys
- Invoice state: `fct_order_meta.meta_key = invoice_reminder_state`
- Renewal state: `fct_subscription_meta.meta_key = renewal_reminder_state`
- Trial state: `fct_subscription_meta.meta_key = trial_reminder_state`

### State structure
Each reminder state meta stores cycles keyed by a hash (cycle key):

```json
{
  "cycles": {
    "c58a049fba283a65...": {
      "sent": {
        "overdue_1": "2026-03-09 09:12:03",
        "overdue_3": "2026-03-11 10:00:05",
        "overdue_7": "2026-03-15 10:00:02"
      },
      "queue": {}
    }
  },
  "updated_at": "2026-03-15 10:00:02"
}
```

### Cycle key composition
- **Invoice**: `md5(order | order_id | dueAt | total_amount | total_paid)`
  - Changes when a partial payment is made → new cycle → reminders restart for new balance.
- **Subscription**: `md5(subscription | subscription_id | billingAt | status | recurring_total)`
  - Changes when billing date advances (after renewal) or status/amount changes.

### Dedupe mechanics
- A stage is skipped if already marked sent in the current cycle.
- A queued stage is throttled for 6 hours (`isStageQueuedRecently`).
- Existing scheduled action check: `as_next_scheduled_action(...)`.
- On enqueue failure (`as_enqueue_async_action(...) === 0`), queued flag is rolled back.
- Only last 5 cycle snapshots are retained per reminder state (auto-pruned).
- **Time windowing** (invoice): each stage's window closes when the next stage opens, preventing all stages from firing at once even if cron is delayed.

### Safeguards summary
| Safeguard                        | Purpose                                                    |
|----------------------------------|------------------------------------------------------------|
| Time windows (invoice)           | Each stage fires only in its designated period, not all at once |
| `isStageAlreadySent`             | Prevents re-sending a stage already marked sent            |
| `isStageQueuedRecently` (6h)     | Prevents re-queueing if Action Scheduler is slow           |
| `as_next_scheduled_action`       | Prevents duplicate Action Scheduler actions                |
| Cycle key re-verification in `send()` | Aborts if order/subscription state changed since queueing |
| 2-day grace on last invoice stage| Handles late cron, then hard stop                          |
| Scan cutoff date                 | Ignores orders older than `due_days + max_overdue + 7` days|
| Upper time bound (subscriptions) | Stages only fire while `now < billingAt` / `now < trialEndAt` |

### State cleanup
- Invoice state is cleared on:
  - `fluent_cart/order_paid`
  - `fluent_cart/order_refunded`
- Subscription state (both renewal and trial) is cleared on:
  - `fluent_cart/payments/subscription_canceled`
  - `fluent_cart/payments/subscription_expired`

## Performance Notes
### Meta preloading
Batch scans preload meta to avoid N+1 lookups:
- `InvoiceReminderService::preloadMeta()`
- `SubscriptionReminderService::preloadRenewalMeta()`
- `SubscriptionReminderService::preloadTrialMeta()`

### Invoice scan cutoff
Older orders are excluded from scan query:
- `max_age_days = due_days + max(overdue_days) + 7`
- minimum window = `30` days
- query constraint: `created_at >= cutoff_date`

### Database index
`database/DBMigrator.php` adds:
- Index name: `fct_payment_status`
- Definition: `(payment_status, id)` on `fct_orders`

## Filter Hooks
- `fluent_cart/reminders/scan_batch_size`
- `fluent_cart/reminders/invoice_due_days`
- `fluent_cart/reminders/invoice_overdue_days`
- `fluent_cart/reminders/billing_cycle`
- `fluent_cart/reminders/yearly_before_days`
- `fluent_cart/reminders/monthly_before_days`
- `fluent_cart/reminders/quarterly_before_days`
- `fluent_cart/reminders/half_yearly_before_days`
- `fluent_cart/reminders/trial_end_days`

## Reminder Payload Contract
### Invoice
- `order`
- `customer`
- `reminder.stage` (`before_0`, `overdue_3`, ...)
- `reminder.order_id`
- `reminder.order_ref`
- `reminder.due_at` (GMT datetime)
- `reminder.due_amount` (integer amount in minor units)
- `reminder.payment_link`

### Subscription renewal
- `subscription`
- `order`
- `customer`
- `reminder.stage`
- `reminder.billing_cycle`
- `reminder.billing_date` (GMT datetime)

### Trial ending
- `subscription`
- `order`
- `customer`
- `reminder.stage` (e.g. `trial_end_3`)
- `reminder.trial_end_date` (GMT datetime)

## Email Notification Defaults (Reminder Events)
Event names and default notification IDs:

- `invoice_reminder_due`
  - `invoice_reminder_due_customer` (default active: `yes`)
  - `invoice_reminder_due_admin` (default active: `no`)
- `invoice_reminder_overdue`
  - `invoice_reminder_overdue_customer` (default active: `yes`)
  - `invoice_reminder_overdue_admin` (default active: `no`)
- `subscription_renewal_reminder`
  - `subscription_renewal_reminder_customer` (default active: `yes`)
  - `subscription_renewal_reminder_admin` (default active: `no`)
- `subscription_trial_end_reminder`
  - `subscription_trial_end_reminder_customer` (default active: `yes`)
  - `subscription_trial_end_reminder_admin` (default active: `no`)

## Quick Verification Checklist
1. Enable `Scheduling & Automation` and save settings.
2. Ensure matching email notifications are enabled in `Scheduler / Reminder Actions`.
3. Create eligible data:
   - unpaid order and/or subscription with future `next_billing_date`.
4. Run scan:

```bash
wp eval "print_r((new \FluentCart\App\Services\Reminders\ReminderService())->runHourlyScan());"
```

5. Inspect queued async actions:

```bash
PREFIX=$(wp db prefix)
wp db query "SELECT action_id,hook,status,scheduled_date_gmt,last_attempt_gmt FROM ${PREFIX}actionscheduler_actions WHERE hook IN ('fluent_cart/reminders/send_invoice','fluent_cart/reminders/send_subscription_renewal','fluent_cart/reminders/send_trial_end') ORDER BY action_id DESC LIMIT 30;"
```

6. Execute reminder hooks manually:

```bash
wp action-scheduler run --hook=fluent_cart/reminders/send_invoice
wp action-scheduler run --hook=fluent_cart/reminders/send_subscription_renewal
wp action-scheduler run --hook=fluent_cart/reminders/send_trial_end
```

7. Inspect reminder state meta:

```bash
PREFIX=$(wp db prefix)
wp db query "SELECT order_id,meta_key,meta_value FROM ${PREFIX}fct_order_meta WHERE meta_key='invoice_reminder_state' ORDER BY id DESC LIMIT 10;"
wp db query "SELECT subscription_id,meta_key,meta_value FROM ${PREFIX}fct_subscription_meta WHERE meta_key IN ('renewal_reminder_state','trial_reminder_state') ORDER BY id DESC LIMIT 20;"
```

## Trial Reminder Test Flow
1. Prepare a trial subscription:

```php
$subscription = \FluentCart\App\Models\Subscription::find($subscription_id);
$subscription->update([
    'status' => 'trialing',
    'trial_ends_at' => gmdate('Y-m-d H:i:s', strtotime('+3 days')),
    'next_billing_date' => gmdate('Y-m-d H:i:s', strtotime('+3 days')),
]);
```

2. Run scan:

```bash
wp eval "print_r((new \FluentCart\App\Services\Reminders\ReminderService())->runHourlyScan());"
```

3. Verify trial hook was queued:

```bash
PREFIX=$(wp db prefix)
wp db query "SELECT action_id,hook,args,status FROM ${PREFIX}actionscheduler_actions WHERE hook='fluent_cart/reminders/send_trial_end' ORDER BY action_id DESC LIMIT 5;"
```

4. Run trial reminder hook:

```bash
wp action-scheduler run --hook=fluent_cart/reminders/send_trial_end
```

## Troubleshooting
### Scan reports queued counts but no emails
- Queue and email delivery are separate steps.
- Run Action Scheduler hook workers manually.
- Confirm reminder notifications are enabled in email settings.
- Verify email transport:

```bash
wp eval "var_dump(wp_mail(get_option('admin_email'),'FluentCart mail test','test'));"
```

### Trial reminders not sending
- Confirm `trial_end_reminders_enabled = yes`.
- Subscription must be `trialing`.
- `next_billing_date` must be valid and in the future.
- If `config.is_trial_days_simulated = yes`, reminder is intentionally skipped.
- Check `trial_end_reminder_days` within `1..14`.

### Renewal reminders missing for a cycle
- Ensure cycle toggle is enabled:
  - monthly: `monthly_renewal_reminders_enabled`
  - quarterly: `quarterly_renewal_reminders_enabled`
  - half yearly: `half_yearly_renewal_reminders_enabled`
  - yearly: `yearly_renewal_reminders_enabled`
- Validate `billing_interval` value (`monthly`, `quarterly`, `half_yearly`, `yearly`, `weekly`, `daily`).
- Note: `weekly`/`daily` and unknown intervals are intentionally skipped by reminder queue logic.

### Invoice scan finds no candidates
- Ensure payment status is one of:
  - `pending`, `partially_paid`, `failed`, `authorized`
- Ensure outstanding amount is greater than zero.
- Ensure order is not older than cutoff window.

### Error logs include cycle key mismatch
- This is expected if order/subscription amounts or dates changed after queueing.
- Stale queued stage is skipped safely.

## Debug Commands
Check subscription reminder states:

```bash
wp eval "$sub = \FluentCart\App\Models\Subscription::find($subscription_id); print_r($sub ? $sub->getMeta('renewal_reminder_state') : null); print_r($sub ? $sub->getMeta('trial_reminder_state') : null);"
```

Clear subscription reminder states for testing:

```bash
wp eval "$sub = \FluentCart\App\Models\Subscription::find($subscription_id); if($sub){$sub->deleteMeta('renewal_reminder_state'); $sub->deleteMeta('trial_reminder_state'); echo 'Cleared';}"
```
