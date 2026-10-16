<?php

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Cache;
use FluentCart\App\Services\Email\EmailNotificationMailer;
use FluentCart\App\Services\Email\EmailNotifications;

function fcWithEmailPreparationFixture(callable $test)
{
    $key = App::config()->get('slug') . '_' . EmailNotifications::META_KEY;
    $cached = wp_cache_get($key, 'fluent-cart');
    Cache::set(EmailNotifications::META_KEY, []);
    $renderer = new class extends EmailNotificationMailer {
        public $observations = [];
        public $scope = 'source';
        public $fail = false;

        public function prepare(array $notification, array $data, array $context): array
        {
            return $this->prepareEmailNotification($notification, $data, $context);
        }

        public function parseEmailContent($notification, $data, $templateData = null): array
        {
            $this->observations[] = 'body:' . $this->scope;
            if ($this->fail) {
                throw new RuntimeException('Fixture render failure');
            }
            return [$notification['body'], $notification['subject'], $notification['to']];
        }
    };
    $order = new Order();
    $notification = [
        'name' => 'fixture_customer', 'event' => 'fixture_event', 'recipient' => 'customer',
        'to' => 'original@example.test', 'is_async' => false,
        'settings' => ['subject' => 'Subject', 'email_body' => 'Body', 'is_default_body' => 'no',
            'attach_pdf_template' => 'order_receipt'],
    ];
    $data = ['order' => $order];
    $context = ['event' => 'fixture_event', 'mail_name' => 'fixture_customer',
        'recipient' => 'customer', 'data' => $data];
    $canGenerate = function () { return true; };
    $pdf = function ($path, $args) use ($renderer, $order) {
        FcTest::assertSame($order, $args['order'], 'PDF provider receives original model');
        FcTest::assertSame('order_receipt', $args['template_id'], 'selected PDF template retained');
        $renderer->observations[] = 'pdf:' . $renderer->scope;
        return 'fixture-' . $renderer->scope . '.pdf';
    };
    add_filter('fluent_cart/pdf/can_generate_receipt', $canGenerate);
    add_filter('fluent_cart/pdf/generate_receipt', $pdf, 10, 2);
    try {
        $test($renderer, $notification, $data, $context);
    } finally {
        remove_filter('fluent_cart/pdf/can_generate_receipt', $canGenerate);
        remove_filter('fluent_cart/pdf/generate_receipt', $pdf, 10);
        if ($cached === false) {
            Cache::forget(EmailNotifications::META_KEY);
        } else {
            Cache::set(EmailNotifications::META_KEY, $cached);
        }
    }
}

return [
    [
        'id' => 'email-preparation-hooks-defaults',
        'name' => 'email preparation preserves source subject body and recipients without listeners',
        'phase' => 30,
        'targets' => [EmailNotificationMailer::class . '::prepareEmailNotification'],
        'boundaries' => ['default callable', 'original recipient', 'optional PDF'],
        'run' => function () {
            fcWithEmailPreparationFixture(function ($renderer, $notification, $data, $context) {
                $result = $renderer->prepare($notification, $data, $context);
                FcTest::assertSame(['Body', 'Subject', 'original@example.test'], array_slice($result, 0, 3),
                    'default preparation output');
                FcTest::assertSame(defined('FLUENT_PDF') ? 'fixture-source.pdf' : null, $result[3], 'native PDF gate');
                FcTest::assertSame('fixture_customer', $result[4]['name'], 'notification identity retained');
            });
        },
    ],
    [
        'id' => 'email-preparation-hooks-scope',
        'name' => 'email formatting body and PDF prepare once in one temporary context',
        'phase' => 30,
        'targets' => [
            EmailNotificationMailer::class . '::prepareEmailNotification',
            EmailNotifications::class . '::formatNotification',
        ],
        'boundaries' => ['before-format context', 'one render', 'PDF context', 'source restoration'],
        'run' => function () {
            fcWithEmailPreparationFixture(function ($renderer, $notification, $data, $context) {
                $calls = 0;
                $wrap = function ($prepare, $received) use ($renderer, $context, &$calls) {
                    FcTest::assertSame($context, $received, 'explicit event/name/recipient/model context');
                    return function () use ($prepare, $renderer, &$calls) {
                        $calls++;
                        $previous = $renderer->scope;
                        $renderer->scope = 'translated';
                        try {
                            return $prepare();
                        } finally {
                            $renderer->scope = $previous;
                        }
                    };
                };
                $localize = function ($value, $viewData) use ($renderer, $data) {
                    FcTest::assertSame($data, $viewData, 'formatter receives original model data');
                    $renderer->observations[] = 'format:' . $renderer->scope;
                    $value['settings']['subject'] = $renderer->scope . ':' . $value['settings']['subject'];
                    $value['settings']['email_body'] = $renderer->scope . ':' . $value['settings']['email_body'];
                    return $value;
                };
                add_filter('fluent_cart/email_notification/prepare_callback', $wrap, 10, 2);
                add_filter('fluent_cart/email_notification/localize_notification', $localize, 10, 2);
                try {
                    $result = $renderer->prepare($notification, $data, $context);
                    FcTest::assertSame(['translated:Body', 'translated:Subject', 'original@example.test'],
                        array_slice($result, 0, 3), 'localization happens before formatting');
                    FcTest::assertSame(1, $calls, 'one preparation invocation');
                    $expected = ['format:translated', 'body:translated'];
                    if (defined('FLUENT_PDF')) {
                        $expected[] = 'pdf:translated';
                    }
                    FcTest::assertSame($expected, $renderer->observations, 'one matching formatting/body/PDF scope');
                    FcTest::assertSame('source', $renderer->scope, 'scope restored before transport could begin');
                    FcTest::assertSame('Body', $notification['settings']['email_body'], 'saved settings copy unchanged');
                } finally {
                    remove_filter('fluent_cart/email_notification/prepare_callback', $wrap, 10);
                    remove_filter('fluent_cart/email_notification/localize_notification', $localize, 10);
                }
            });
        },
    ],
    [
        'id' => 'email-preparation-hooks-failure',
        'name' => 'email render exception propagates after restoring temporary context',
        'phase' => 30,
        'targets' => [EmailNotificationMailer::class . '::prepareEmailNotification'],
        'boundaries' => ['render exception', 'context restoration', 'no partial PDF'],
        'run' => function () {
            fcWithEmailPreparationFixture(function ($renderer, $notification, $data, $context) {
                $renderer->fail = true;
                $wrap = function ($prepare) use ($renderer) {
                    return function () use ($prepare, $renderer) {
                        $renderer->scope = 'temporary';
                        try {
                            return $prepare();
                        } finally {
                            $renderer->scope = 'source';
                        }
                    };
                };
                add_filter('fluent_cart/email_notification/prepare_callback', $wrap);
                try {
                    $message = null;
                    try {
                        $renderer->prepare($notification, $data, $context);
                    } catch (RuntimeException $error) {
                        $message = $error->getMessage();
                    }
                    FcTest::assertSame('Fixture render failure', $message, 'original exception propagated');
                    FcTest::assertSame('source', $renderer->scope, 'exception-safe scope restoration');
                    FcTest::assertSame(['body:temporary'], $renderer->observations, 'PDF not prepared after body fails');
                } finally {
                    remove_filter('fluent_cart/email_notification/prepare_callback', $wrap);
                }
            });
        },
    ],
    [
        'id' => 'email-preparation-hooks-invalid',
        'name' => 'invalid email preparation extensions fail explicitly',
        'phase' => 30,
        'targets' => [EmailNotificationMailer::class . '::prepareEmailNotification'],
        'boundaries' => ['non-callable', 'malformed return tuple'],
        'run' => function () {
            fcWithEmailPreparationFixture(function ($renderer, $notification, $data, $context) {
                foreach ([false, function () { return []; }] as $invalid) {
                    $filter = function () use ($invalid) { return $invalid; };
                    add_filter('fluent_cart/email_notification/prepare_callback', $filter);
                    try {
                        $caught = false;
                        try {
                            $renderer->prepare($notification, $data, $context);
                        } catch (UnexpectedValueException $error) {
                            $caught = true;
                        }
                        FcTest::assertSame(true, $caught, 'invalid preparation is rejected');
                        FcTest::assertSame([], $renderer->observations, 'invalid callback does not render');
                    } finally {
                        remove_filter('fluent_cart/email_notification/prepare_callback', $filter);
                    }
                }
            });
        },
    ],
    [
        'id' => 'email-preparation-hooks-deferred',
        'name' => 'event selection can defer rendering without changing activation or delivery gates',
        'phase' => 30,
        'targets' => [
            EmailNotifications::class . '::getNotificationsOfEvent',
            EmailNotificationMailer::class . '::mailEmailsOfEvent',
        ],
        'boundaries' => ['active selection', 'default formatted API', 'suppressed event does not render'],
        'run' => function () {
            fcWithEmailPreparationFixture(function ($renderer, $notification, $data, $context) {
                $notification['settings']['active'] = 'yes';
                $inactive = $notification;
                $inactive['settings']['active'] = 'no';
                $definitions = function () use ($notification, $inactive) {
                    return ['fixture_customer' => $notification, 'fixture_inactive' => $inactive];
                };
                $renders = 0;
                $observe = function ($value) use (&$renders) {
                    $renders++;
                    return $value;
                };
                $suppress = function () { return false; };
                add_filter('fluent_cart/email_notifications', $definitions);
                add_filter('fluent_cart/email_notification/localize_notification', $observe);
                add_filter('fluent_cart/should_send_email_notification', $suppress);
                try {
                    $raw = EmailNotifications::getNotificationsOfEvent('fixture_event', $data, false);
                    FcTest::assertSame(['fixture_customer'], array_keys($raw), 'inactive notification remains excluded');
                    FcTest::assertSame(0, $renders, 'deferred selection does not format templates');
                    FcTest::assertSame('Body', $raw['fixture_customer']['settings']['email_body'], 'raw settings retained');
                    $formatted = EmailNotifications::getNotificationsOfEvent('fixture_event', $data);
                    FcTest::assertSame('Body', $formatted['fixture_customer']['body'], 'existing formatted default retained');
                    FcTest::assertSame(1, $renders, 'formatted default prepares selected notification once');
                    $data['order']->setAppends([]);
                    $renderer->mailEmailsOfEvent('fixture_event', $data);
                    FcTest::assertSame(1, $renders, 'suppressed notification never formats');
                    FcTest::assertSame([], $renderer->observations, 'suppressed notification never renders body/PDF');
                } finally {
                    remove_filter('fluent_cart/email_notifications', $definitions);
                    remove_filter('fluent_cart/email_notification/localize_notification', $observe);
                    remove_filter('fluent_cart/should_send_email_notification', $suppress);
                }
            });
        },
    ],
];
