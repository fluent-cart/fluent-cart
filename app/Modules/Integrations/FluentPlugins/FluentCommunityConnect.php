<?php

namespace FluentCart\App\Modules\Integrations\FluentPlugins;

use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use FluentCart\App\Services\AuthService;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCommunity\App\Models\Space;
use FluentCommunity\App\Models\SpaceUserPivot;
use FluentCommunity\App\Models\User;
use FluentCommunity\App\Models\XProfile;
use FluentCommunity\App\Services\Helper;
use FluentCommunity\Modules\Course\Model\Course;
use FluentCommunity\Modules\Course\Services\CourseHelper;

class FluentCommunityConnect extends BaseIntegrationManager
{

    protected $runOnBackgroundForProduct = false;

    public $category = 'lms';

    public function __construct()
    {
        parent::__construct('FluentCommunity', 'fluent_community', 12);

        $this->description = __('Create a fast and responsive community and LMS without slowing down your server – no bloat, just performance. Sale your course or memberships with FluentCart + FluentCommunity Integration.', 'fluent-cart');
        $this->logo = Vite::getAssetUrl('images/integrations/fluent-community.svg');
        $this->disableGlobalSettings = true;
        $this->installable = 'fluent-community/fluent-community.php';
    }

    public function isConfigured()
    {
        return defined('FLUENT_COMMUNITY_PLUGIN_VERSION');
    }

    public function getApiSettings()
    {
        return [
            'status'  => defined('FLUENT_COMMUNITY_PLUGIN_VERSION'),
            'api_key' => ''
        ];
    }

    public function getIntegrationDefaults($settings)
    {
        return [
            'enabled'                => 'yes',
            'name'                   => '',
            'space_ids'              => [],
            'remove_space_ids'       => [],
            'course_ids'             => [],
            'remove_course_ids'      => [],
            'event_trigger'          => [],
            'tag_ids_selection_type' => 'simple',
            'mark_as_verified'       => 'no',
            'watch_on_access_revoke' => 'yes'
        ];
    }

    public function getSettingsFields($settings, $args = [])
    {
        $spaces = Space::orderBy('title', 'ASC')->select(['id', 'title', 'parent_id'])
            ->with(['group'])
            ->get();
        $formattedSpaces = [];
        foreach ($spaces as $space) {
            $title = $space->title;

            if ($space->group) {
                $title .= ' (' . $space->group->title . ')';
            }

            $formattedSpaces[(string)$space->id] = $title;
        }

        $formattedCourses = [];

        $isCourseEnabled = Helper::isFeatureEnabled('course_module');

        if ($isCourseEnabled) {
            $courses = Course::orderBy('title', 'ASC')->select(['id', 'title'])->get();

            $formattedCourses = [];
            foreach ($courses as $course) {
                $formattedCourses[(string)$course->id] = $course->title;
            }
        }

        $fields = [
            'name'                   => [
                'key'         => 'name',
                'label'       => __('Feed Title', 'fluent-cart'),
                'required'    => true,
                'placeholder' => __('Name', 'fluent-cart'),
                'component'   => 'text',
                'inline_tip'  => __('Name of this feed, it will be used to identify this feed in the list of feeds', 'fluent-cart')
            ],
            'space_ids'              => [
                'key'         => 'space_ids',
                'label'       => __('Add to Spaces', 'fluent-cart'),
                'placeholder' => __('Select FluentCommunity Spaces', 'fluent-cart'),
                'inline_tip'  => __('Select the FluentCommunity Spaces you would like to add.', 'fluent-cart'),
                'component'   => 'select',
                'is_multiple' => true,
                'required'    => false,
                'options'     => $formattedSpaces
            ],
            'course_ids'             => [
                'key'          => 'course_ids',
                'require_list' => false,
                'label'        => __('Add to Courses', 'fluent-cart'),
                'placeholder'  => __('Select Courses', 'fluent-cart'),
                'component'    => 'select',
                'is_multiple'  => true,
                'options'      => $formattedCourses,
                'inline_tip'   => __('Select the courses you would like to enroll the customer to', 'fluent-cart')
            ],
            'remove_space_ids'       => [
                'key'         => 'remove_space_ids',
                'label'       => __('Remove From Spaces', 'fluent-cart'),
                'placeholder' => __('Select Spaces', 'fluent-cart'),
                'inline_tip'  => __('Select the Spaces you would like to remove from your spaces.', 'fluent-cart'),
                'component'   => 'select',
                'is_multiple' => true,
                'required'    => false,
                'options'     => $formattedSpaces
            ],
            'remove_course_ids'      => [
                'key'          => 'remove_course_ids',
                'require_list' => false,
                'label'        => __('Remove From Courses', 'fluent-cart'),
                'placeholder'  => __('Select Courses', 'fluent-cart'),
                'component'    => 'select',
                'is_multiple'  => true,
                'options'      => $formattedCourses,
                'inline_tip'   => __('Select the courses you would like to remove from the customer', 'fluent-cart')
            ],
            'mark_as_verified'       => [
                'key'            => 'mark_as_verified',
                'component'      => 'yes-no-checkbox',
                'checkbox_label' => __('Mark the community profile as verified', 'fluent-cart'),
                'inline_tip'     => __('If you enable this, the user will be marked as verified in FluentCommunity', 'fluent-cart')
            ],
            'watch_on_access_revoke' => [
                'key'            => 'watch_on_access_revoke',
                'component'      => 'yes-no-checkbox',
                'checkbox_label' => __('Remove from selected Courses/Spaces on Refund or Subscription Access Expiration ', 'fluent-cart'),
                'inline_tip'     => __('If you enable this, on refund or subscription validity expiration, the selected spaces and courses will be removed from the customer.', 'fluent-cart')
            ]
        ];

        if (!$isCourseEnabled) {
            unset($fields['course_ids']);
            unset($fields['remove_course_ids']);
        }

        $fields = array_values($fields);

        $fields[] = $this->actionFields();

        return [
            'fields'              => $fields,
            'button_require_list' => false,
            'integration_title'   => __('FluentCommunity', 'fluent-cart')
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
        $spaceIds = array_filter((array)Arr::get($feedConfig, 'space_ids', []), 'intval');
        $removeCourseIds = array_filter((array)Arr::get($feedConfig, 'remove_course_ids', []), 'intval');
        $removeSpaceIds = array_filter((array)Arr::get($feedConfig, 'remove_space_ids', []), 'intval');
        $markAsVerified = Arr::get($feedConfig, 'mark_as_verified', '') == 'yes';

        $allSpaceCourseIds = array_merge($courseIds, $spaceIds);

        $userId = $customer->getWpUserId(true);

        if ($isRevokedHook) {
            if (!$userId || !$allSpaceCourseIds) {
                return;
            }

            $xProfile = XProfile::where('user_id', $userId)->first();
            if (!$xProfile) {
                return; // no xprofile found
            }

            $pivots = SpaceUserPivot::whereIn('space_id', $allSpaceCourseIds)
                ->where('user_id', $userId)
                ->get();

            $removedIds = [];

            foreach ($pivots as $pivot) {
                $prevOrderIds = Arr::get($pivot->meta, 'fct_ids', []);
                if ($prevOrderIds || in_array($order->id, $prevOrderIds)) {
                    // remove the order id from the meta
                    $newOrderIds = array_filter($prevOrderIds, function ($id) use ($order) {
                        return $id != $order->id;
                    });
                    if ($newOrderIds) {
                        $existingMeta = $pivot->meta;
                        $existingMeta['fct_ids'] = array_values($newOrderIds);
                        $pivot->meta = $existingMeta;
                        $pivot->save();
                        // we are just skipping it as other orders are still giving access
                    } else {
                        Helper::removeFromSpace($pivot->space_id, $userId, 'by_admin');
                        $removedIds[] = $pivot->space_id;
                    }
                    continue;
                }
                Helper::removeFromSpace($pivot->space_id, $userId, 'by_admin');
                $removedIds[] = $pivot->space_id;
            }

            if ($removedIds) {
                $order->addLog(
                    __('FluentCommunity Access Removed', 'fluent-cart'),
                    sprintf(
                        /* translators: %s is the space ids */
                        __('User has been removed from spaces and courses: %s', 'fluent-cart'), implode(', ', $removedIds)),
                    'info',
                    'FluentCommunity'
                );
            }

            return;
        }

        if (!$userId) {
            $userId = AuthService::createUserFromCustomer($customer);
            if (is_wp_error($userId)) {
                $order->addLog(
                    __('User creation failed from FluentCommunity Integration', 'fluent-cart'),
                    $userId->get_error_message(),
                    'error',
                    'FluentCommunity'
                );
                return;
            }
        }

        if (!$userId) {
            return false;
        }

        $communityUser = User::find($userId);

        if (!$communityUser) {
            return;
        }

        $xprofile = $communityUser->syncXProfile(true);

        if (!$xprofile) {
            return;
        }

        if ($markAsVerified) {
            $xprofile->is_verified = 1;
            $xprofile->save();
        }

        // we will remove the spaces and courses from the user
        if ($removeSpaceIds) {
            foreach ($removeSpaceIds as $spaceId) {
                Helper::removeFromSpace($spaceId, $userId, 'by_admin');
            }
        }

        if ($removeCourseIds) {
            CourseHelper::leaveCourses($removeCourseIds, $userId, 'by_admin');
        }


        if ($spaceIds) {
            foreach ($spaceIds as $spaceId) {
                $pivot = SpaceUserPivot::where('space_id', $spaceId)
                    ->where('user_id', $userId)
                    ->first();
                if ($pivot) {
                    $existingMeta = $pivot->meta;
                    $prevOrderIds = Arr::get($existingMeta, 'fct_ids', []);
                    $prevOrderIds[] = $order->id;
                    $existingMeta['fct_ids'] = array_values(array_unique($prevOrderIds));
                    $pivot->meta = $existingMeta;
                    $pivot->save();
                    // we are just updating the meta with this order id
                } else {
                    Helper::addToSpace($spaceId, $userId, 'member', 'by_admin');
                }
            }
        }

        if ($courseIds) {
            foreach ($courseIds as $courseId) {
                $pivot = SpaceUserPivot::where('space_id', $courseId)
                    ->where('user_id', $userId)
                    ->first();
                if ($pivot) {
                    $existingMeta = $pivot->meta;
                    $prevOrderIds = Arr::get($existingMeta, 'fct_ids', []);
                    $prevOrderIds[] = $order->id;
                    $existingMeta['fct_ids'] = array_values(array_unique($prevOrderIds));
                    $pivot->meta = $existingMeta;
                    $pivot->save();
                    // we are just updating the meta with this order id
                } else {
                    Helper::addToSpace($courseId, $userId, 'student', 'by_admin');
                }
            }
        }

        $order->addLog(
            __('FluentCommunity Integration Success', 'fluent-cart'),
            sprintf(
                /* translators: 1: space ids, 2: course ids */
                __('User has been added to spaces: %1$s and courses: %2$s', 'fluent-cart'), implode(', ', $spaceIds), implode(', ', $courseIds)),
            'info',
            'FluentCommunity'
        );
    }

}
