<?php

namespace Tests\Support\Traits;

use FluentCart\App\Services\Email\EmailNotificationMailer;

/**
 * Inspection helpers for EmailNotificationMailer hook-wiring tests: find the
 * priority a mailer instance registered a hook at, build a spy mailer that
 * records which event name register()'s closures route into mailEmailsOfEvent,
 * force the FLUENT_PDF constant gate for PDF-attachment paths, and read
 * Cc/Bcc headers back out of a captured pre_wp_mail $atts array.
 */
trait SpiesOnEmailMailer
{
    /** Find priority of the mailer's registered closure for $hook. */
    protected function getActionPriority(string $hook, EmailNotificationMailer $mailer): ?int
    {
        global $wp_filter;
        if (!isset($wp_filter[$hook])) {
            return null;
        }
        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $cb) {
                if (is_array($cb['function']) && isset($cb['function'][0]) && $cb['function'][0] === $mailer) {
                    return $priority;
                }
                if ($cb['function'] instanceof \Closure) {
                    $boundThis = (new \ReflectionFunction($cb['function']))->getClosureThis();
                    if ($boundThis === $mailer) {
                        return $priority;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Capture the event name passed by register()'s closure into mailEmailsOfEvent.
     * Spies on the mailer by overriding mailEmailsOfEvent in an anonymous subclass.
     * Clears the listed action hooks first so the production-registered mailer (wired at
     * boot via app/Hooks/actions.php) does not fire a second time and emit view warnings.
     */
    protected function spyMailerCapturingDispatchedEvent(array $hooksToClear = []): array
    {
        $defaultHooks = [
            'fluent_cart/order_placed_offline',
            'fluent_cart/order_paid',
            'fluent_cart/order_paid_done',
            'fluent_cart/subscription_renewed',
            'fluent_cart/subscription_canceled',
            'fluent_cart/order_refunded',
            'fluent_cart/shipping_status_changed_to_shipped',
            'fluent_cart/shipping_status_changed_to_delivered',
            'fluent_cart/renewal_reminder_overdue',
            'fluent_cart/subscription_renewal_reminder',
            'fluent_cart/subscription_trial_end_reminder',
        ];
        foreach ($hooksToClear ?: $defaultHooks as $hook) {
            remove_all_actions($hook);
        }

        $captured = ['events' => [], 'data' => []];
        $mailer = new class($captured) extends EmailNotificationMailer {
            private array $box;
            public function __construct(array &$box) { $this->box = &$box; }
            public function mailEmailsOfEvent($event, $data, $asyncHook = '', $asyncData = []) {
                $this->box['events'][] = $event;
                $this->box['data'][]   = $data;
            }
        };
        $mailer->register();
        return ['mailer' => $mailer, 'captured' => &$captured];
    }

    /**
     * PDF-attachment branches in mailEmailsOfEvent are gated on the FLUENT_PDF
     * constant. Define it at runtime if not already defined; the constant alone
     * does not open OrderService::canGenerateReceiptPdf() (Pro stays inactive),
     * so combine with the fluent_cart/pdf/can_generate_receipt filter.
     */
    protected function forceFluentPdfConstant(): void
    {
        if (!defined('FLUENT_PDF')) {
            define('FLUENT_PDF', true);
        }
    }

    /**
     * Find a header line (e.g. "Cc", "Bcc") in a captured pre_wp_mail $atts
     * array. Headers there are the raw array Mailer::send() built, so this
     * matches on the "Name:" prefix rather than parsing full RFC822 headers.
     */
    protected function findHeader(array $mail, string $name): string
    {
        $headers = $mail['headers'] ?? [];
        $headers = is_array($headers) ? $headers : [$headers];
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return $header;
            }
        }
        return '';
    }

    protected function assertNoCcHeader(array $mail): void
    {
        $this->assertSame('', $this->findHeader($mail, 'Cc'), 'Unexpected Cc header present');
    }

    protected function assertNoBccHeader(array $mail): void
    {
        $this->assertSame('', $this->findHeader($mail, 'Bcc'), 'Unexpected Bcc header present');
    }
}
