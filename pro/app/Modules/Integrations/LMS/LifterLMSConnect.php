<?php

namespace FluentCartPro\App\Modules\Integrations\LMS;

use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use FluentCart\App\Services\AuthService;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class LifterLMSConnect extends BaseIntegrationManager
{
    public $scopes = ['global', 'product'];

    public $integrationId = null;

    public $disableGlobalSettings = true;

    public function __construct()
    {
        parent::__construct('LifterLMS', 'lifterlms', 20);

        $this->description = __('Manage Course accesses with FluentCart + LifterLMS', 'fluent-cart-pro');
        $this->logo = Vite::getAssetUrl('images/integrations/lifterlms.svg');

        add_filter('fluent_cart/integration/integration_options_lifter_courses', [$this, 'getCourseOptions'], 10, 2);
        add_filter('fluent_cart/integration/integration_options_lifter_memberships', [$this, 'getMembershipOptions'], 10, 2);
    }

    public function isConfigured()
    {
        return defined('LLMS_VERSION');
    }

    public function getIntegrationDefaults($settings)
    {
        return [
            'enabled'                => 'yes',
            'name'                   => '',
            'course_ids'             => [],
            'membership_ids'         => [],
            'event_trigger'          => [],
            'watch_on_access_revoke' => 'yes'
        ];
    }

    public function getSettingsFields($settings, $args = [])
    {
        $fields = [
            'name'                   => [
                'key'         => 'name',
                'label'       => __('Feed Title', 'fluent-cart-pro'),
                'required'    => true,
                'placeholder' => __('Name', 'fluent-cart-pro'),
                'component'   => 'text',
                'inline_tip'  => __('Name of this feed, it will be used to identify this feed in the list of feeds', 'fluent-cart-pro')
            ],
            'course_ids'             => [
                'key'         => 'course_ids',
                'label'       => __('Add to Courses', 'fluent-cart-pro'),
                'placeholder' => __('Select LifterLMS Courses', 'fluent-cart-pro'),
                'inline_tip'  => __('Select the LifterLMS Courses you would like to add.', 'fluent-cart-pro'),
                'component'   => 'rest_selector',
                'is_multiple' => true,
                'option_key'  => 'lifter_courses',
                'required'    => false,
                'cacheable'   => true
            ],
            'membership_ids'         => [
                'key'          => 'membership_ids',
                'require_list' => false,
                'label'        => __('Add to Memberships', 'fluent-cart-pro'),
                'placeholder'  => __('Select Memberships', 'fluent-cart-pro'),
                'is_multiple'  => true,
                'component'    => 'rest_selector',
                'cacheable'    => true,
                'option_key'   => 'lifter_memberships',
                'inline_tip'   => __('Select the memberships you would like to add the customer to', 'fluent-cart-pro')
            ],
            'watch_on_access_revoke' => [
                'key'            => 'watch_on_access_revoke',
                'component'      => 'yes-no-checkbox',
                'checkbox_label' => __('Remove from selected Courses/Memberships on Refund or Subscription Access Expiration ', 'fluent-cart-pro'),
                'inline_tip'     => __('If you enable this, on refund or subscription validity expiration, the selected memberships and courses will be removed from the customer.', 'fluent-cart-pro')
            ]
        ];

        $fields = array_values($fields);

        $fields[] = $this->actionFields();

        return [
            'fields'              => $fields,
            'button_require_list' => false,
            'integration_title'   => __('LifterLMS', 'fluent-cart-pro')
        ];
    }

    public function getCourseOptions($options, $args = [])
    {
        $courses = get_posts(array(
            'post_type'   => 'course',
            'numberposts' => -1
        ));

        $formattedCourses = [];
        foreach ($courses as $course) {
            $formattedCourses[] = [
                'id'    => strval($course->ID),
                'title' => $course->post_title
            ];
        }

        return $formattedCourses;
    }

    public function getMembershipOptions($options, $args = [])
    {
        $courses = get_posts(array(
            'post_type'   => 'llms_membership',
            'numberposts' => -1
        ));

        $formattedCourses = [];
        foreach ($courses as $course) {
            $formattedCourses[] = [
                'id'    => strval($course->ID),
                'title' => $course->post_title
            ];
        }

        return $formattedCourses;
    }

    /*
     * For Handling Notifications broadcast
     */
    public function processAction($order, $eventData)
    {
        $feedConfig = Arr::get($eventData, 'feed', []);
        $isRevokedHook = Arr::get($eventData, 'is_revoke_hook') === 'yes';
        $customer = $order->customer;

        // check exits
        $courseIds = array_filter((array)Arr::get($feedConfig, 'course_ids', []), 'intval');
        $membershipIds = array_filter((array)Arr::get($feedConfig, 'membership_ids', []), 'intval');
        $accessIds = array_filter(array_merge($courseIds, $membershipIds));

        if (!$accessIds) {
            return;
        }

        $userId = $customer->getWpUserId(true);

        if ($isRevokedHook) {
            if (!$userId) {
                return;
            }
            $student = llms_get_student($userId);

            if ($student) {
                foreach ($accessIds as $accessId) {
                    $student->unenroll($accessId);
                }
            }

            $order->addLog(
                __('Accesses Removed from the connected student on order revoke', 'fluent-cart-pro'),
                $userId->get_error_message(),
                'info',
                'LifterLMS Integration'
            );

            return;
        }

        if (!$userId) {
            $userId = AuthService::createUserFromCustomer($customer);
            if (is_wp_error($userId)) {
                $order->addLog(
                    __('User creation failed from LifterLMS Integration', 'fluent-cart-pro'),
                    $userId->get_error_message(),
                    'error',
                    'LifterLMS Integration'
                );
                return;
            }
        }

        if (!$userId) {
            return false;
        }

        $student = llms_get_student($userId);

        if (!$student) {
            return;
        }

        foreach ($accessIds as $accessId) {
            $student->enroll($accessId);
        }

        $order->addLog(
            __('LifterLMS Integration Success', 'fluent-cart-pro'),
            sprintf(__('User has been added to courses: %s and memberships: %s', 'fluent-cart-pro'), implode(', ', $courseIds), implode(', ', $membershipIds)),
            'info',
            'LifterLMS Integration'
        );
    }
}
