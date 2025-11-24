<?php

namespace FluentCartPro\App\Modules\Integrations\LMS;

use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use FluentCart\App\Services\AuthService;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class LearnDashLMSConnect extends BaseIntegrationManager
{
    public $scopes = ['global', 'product'];

    public function __construct()
    {
        parent::__construct('LearnDash', 'learndash', 20);

        $this->description = __('Manage Course accesses with FluentCart + LearnDash', 'fluent-cart-pro');
        $this->logo = Vite::getAssetUrl('images/integrations/learndash.svg');
    }

    public function isConfigured()
    {
        return defined('LEARNDASH_VERSION');
    }

    public function getIntegrationDefaults($settings)
    {
        return [
            'enabled'                => 'yes',
            'name'                   => '',
            'course_ids'             => [],
            'group_ids'              => [],
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
                'key'            => 'course_ids',
                'label'          => __('Add to Courses', 'fluent-cart-pro'),
                'placeholder'    => __('Select Learndash Courses', 'fluent-cart-pro'),
                'inline_tip'     => __('Select the LearnDash Courses you would like to add.', 'fluent-cart-pro'),
                'component'      => 'rest_selector',
                'option_key'     => 'post_type',
                'sub_option_key' => 'sfwd-courses',
                'is_multiple'    => true,
                'required'       => false
            ],
            'group_ids'              => [
                'key'            => 'group_ids',
                'require_list'   => false,
                'label'          => __('Add to LearnDash Groups', 'fluent-cart-pro'),
                'placeholder'    => __('Select LearnDash Groups', 'fluent-cart-pro'),
                'is_multiple'    => true,
                'component'      => 'rest_selector',
                'option_key'     => 'post_type',
                'sub_option_key' => 'groups',
                'inline_tip'     => __('Select the groups you would like to add the customer to', 'fluent-cart-pro')
            ],
            'watch_on_access_revoke' => [
                'key'            => 'watch_on_access_revoke',
                'component'      => 'yes-no-checkbox',
                'checkbox_label' => __('Remove from selected Courses/Groups on Refund or Subscription Access Expiration ', 'fluent-cart-pro'),
                'inline_tip'     => __('If you enable this, on refund or subscription validity expiration, the selected groups and courses will be removed from the customer.', 'fluent-cart-pro')
            ]
        ];

        $fields = array_values($fields);

        $fields[] = $this->actionFields();

        return [
            'fields'              => $fields,
            'button_require_list' => false,
            'integration_title'   => __('LearnDash', 'fluent-cart-pro')
        ];
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
        $groupIds = array_filter((array)Arr::get($feedConfig, 'group_ids', []), 'intval');

        if (!$courseIds || !$groupIds) {
            return;
        }

        $userId = $customer->getWpUserId(true);

        if ($isRevokedHook) {
            if (!$userId) {
                return;
            }

            if ($courseIds) {
                $courses = learndash_user_get_enrolled_courses($userId);
                $removeCourseIds = array_intersect($courseIds, $courses);
                foreach ($removeCourseIds as $courseId) {
                    ld_update_course_access($userId, $courseId, true);
                }
            }

            if ($groupIds) {
                $groups = learndash_get_users_group_ids($userId);
                $removeGroupIds = array_intersect($groupIds, $groups);
                foreach ($removeGroupIds as $groupId) {
                    ld_update_group_access($userId, $groupId, true);
                }
            }

            $order->addLog(
                __('Accesses Removed from the connected student on order revoke', 'fluent-cart-pro'),
                $userId->get_error_message(),
                'info',
                'LearnDash Integration'
            );

            return;
        }

        if (!$userId) {
            $userId = AuthService::createUserFromCustomer($customer);
            if (is_wp_error($userId)) {
                $order->addLog(
                    __('User creation failed from LearnDash Integration', 'fluent-cart-pro'),
                    $userId->get_error_message(),
                    'error',
                    'LearnDash Integration'
                );
                return;
            }
        }

        if (!$userId) {
            return false;
        }

        if($courseIds) {
            $existingCourses = learndash_user_get_enrolled_courses($userId);
            $newCourses = array_diff($courseIds, $existingCourses);
            foreach ($newCourses as $courseId) {
                ld_update_course_access($userId, $courseId);
            }
        }

        if($groupIds) {
            $existingGroups = learndash_get_users_group_ids($userId);
            $newGroups = array_diff($groupIds, $existingGroups);
            foreach ($newGroups as $groupId) {
                ld_update_group_access($userId, $groupId);
            }
        }

        $order->addLog(
            __('LearnDash Integration Success', 'fluent-cart-pro'),
            sprintf(__('User has been added to courses: %s and groups: %s', 'fluent-cart-pro'), implode(', ', $courseIds), implode(', ', $groupIds)),
            'info',
            'LearnDash Integration'
        );
    }

}
