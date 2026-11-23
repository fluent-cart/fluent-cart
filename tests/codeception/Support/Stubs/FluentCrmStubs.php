<?php
/**
 * A stand-in for FluentCRM's `FluentCrmApi('contacts')`.
 *
 * The disposable test install never loads FluentCRM, so the FluentCRM feed
 * tests declare `FluentCrmApi()` once (guarded) and read back the contact
 * data the feed sent through FluentCrmContactsStub::$lastData.
 */

namespace {
    if (!class_exists('FluentCrmContactsStub', false)) {
        class FluentCrmContactsStub
        {
            /** @var array|null last data passed to createOrUpdate() */
            public static $lastData = null;

            public static function reset(): void
            {
                self::$lastData = null;
            }

            public function createOrUpdate($data, $forceUpdate = false)
            {
                self::$lastData = $data;

                return (object)['id' => 1, 'status' => 'subscribed'];
            }

            public function getContact($email)
            {
                return null;
            }
        }
    }

    if (!function_exists('FluentCrmApi')) {
        function FluentCrmApi($key = '')
        {
            return new FluentCrmContactsStub();
        }
    }
}

namespace FluentCrm\App\Models {
    if (!class_exists(SubscriberNote::class, false)) {
        class SubscriberNote
        {
            /** @var array|null last data passed to create() */
            public static $lastData = null;

            public static function create($data)
            {
                self::$lastData = $data;

                return (object)$data;
            }
        }
    }
}
