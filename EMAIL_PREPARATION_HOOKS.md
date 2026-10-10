# Email preparation filters

The existing `fluent_cart/email_notification/mailer` filter runs after the
subject, body and optional receipt PDF have been prepared. That is useful for
adjusting transport, but too late to select one temporary rendering context
for all three.

## Preparation callback

`fluent_cart/email_notification/prepare_callback` receives:

1. A zero-argument callable returning
   `[body, subject, to, pdfPath, formattedNotification]`.
2. A context array containing `event`, `mail_name`, `recipient` and `data`.
   `data` retains its original models.

Event sends and named/background sends use the same preparation helper.
The callback runs only after native event selection, activation, delivery
suppression and async dispatch decisions. It does not send mail. Native
attachment handling, the existing mailer filter, transport and PDF cleanup
still run afterwards.

```php
add_filter('fluent_cart/email_notification/prepare_callback', function ($prepare, $context) {
    return function () use ($prepare, $context) {
        $previous = my_extension_render_context();
        my_extension_set_render_context(my_extension_recipient_context($context));
        try {
            return $prepare();
        } finally {
            my_extension_set_render_context($previous);
        }
    };
}, 10, 2);
```

Extensions should invoke the original callable exactly once and restore their
context even when rendering fails. Do not send mail inside the wrapper.
Non-callable filters or malformed return tuples throw `UnexpectedValueException`
before native transport.

## Related rendering filters

| Filter | Arguments | Return value |
| --- | --- | --- |
| `fluent_cart/email_notification/localize_notification` | Raw notification, original view data | Notification array, before settings/template formatting |
| `fluent_cart/email_notification/footer` | Configured footer | Footer string, before shortcode expansion |
| `fluent_cart/email_notification/billing_interval` | Original subscription interval | Display label, escaped by the existing template |
| `fluent_cart/email_notification/preview_callback` | Zero-argument preview callable, context (`template`, `data`) | Callable returning preview HTML |

The preview filter scopes native dummy-data rendering only; it never sends mail.
It retains template validation and disabled links outside the wrapper.
The interval filter changes presentation, not subscription billing settings.
No filter persists translated settings, intercepts `wp_mail` or guesses a
recipient from a call stack.

`EmailNotifications::getNotificationsOfEvent($event, $data)` retains its
formatted default. The optional third argument `false` lets the native sender
select raw, enabled notifications without rendering discarded or queued
templates. The sender formats the selected notification inside the wrapper,
without looking it up again.

## Tests

```sh
bash tests/bin/run-all.sh pure email-preparation-hooks
```

Cases use real notification formatting and in-memory order models, with body
rendering and PDF-provider doubles. They cover unchanged default output,
consistent formatting/body/PDF context, exception restoration, invalid
extensions, deferred event selection and the existing suppression gate.
No customer transaction, real PDF file, mail transport or database write is
needed. The PDF assertions also support installations without FluentPDF.
