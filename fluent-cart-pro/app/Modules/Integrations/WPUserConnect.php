<?php

namespace FluentCartPro\App\Modules\Integrations;

use FluentCart\App\Helpers\EditorShortCodeHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use FluentCart\App\Services\AuthService;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class WPUserConnect extends BaseIntegrationManager
{

    public $scopes = ['global', 'product'];

    public $integrationId = null;

    public function __construct()
    {
        parent::__construct(__('WP User Create/Update', 'fluent-cart-pro'), 'wp_user', 10);

        $this->description = __('Create / Update WP User with Custom Roles on order events', 'fluent-cart-pro');
        $this->logo = Vite::getAssetUrl('images/integrations/wp_user.svg');
        $this->disableGlobalSettings = true;
    }

    public function isConfigured()
    {
        return true;
    }

    public function getApiSettings()
    {
        return [
            'status'  => true,
            'api_key' => ''
        ];
    }

    public function getIntegrationDefaults($settings)
    {
        return [
            'enabled'               => 'yes',
            'name'                  => '',
            'user_role'             => 'subscriber',
            'replace_existing_role' => 'yes',
            'user_meta'             => [
                [
                    'name'  => '',
                    'value' => ''
                ]
            ],
            'event_trigger'         => [],
        ];
    }

    public function getSettingsFields($settings, $args = [])
    {
        $bodyOptions = (EditorShortCodeHelper::getShortCodes())['data'];

        $fields = [
            'name'                   => [
                'key'         => 'name',
                'label'       => __('Integration Title', 'fluent-cart-pro'),
                'required'    => true,
                'placeholder' => __('Name', 'fluent-cart-pro'),
                'component'   => 'text',
                'inline_tip'  => __('Name of this feed, it will be used to identify this integration in the list of integrations', 'fluent-cart-pro')
            ],
            'user_role'              => [
                'key'        => 'user_role',
                'label'      => __('User Role', 'fluent-cart-pro'),
                'required'   => true,
                'component'  => 'select',
                'options'    => $this->getUserRolesOptions(),
                'inline_tip' => __('Select the user role you want to assign to the user. The integartion will skip running if user exist in the selected role.', 'fluent-cart-pro')
            ],
            'replace_existing_role'  => [
                'key'            => 'replace_existing_role',
                'component'      => 'yes-no-checkbox',
                'checkbox_label' => __('Replace Existing Roles', 'fluent-cart-pro'),
                'inline_tip'     => __('If you enable this, existing roles of the user will be removed and only the selected role will be assigned to that user.', 'fluent-cart-pro')
            ],
            'user_meta'              => [
                'key'               => 'user_meta',
                'label'             => __('User Meta Maps', 'fluent-cart-pro'),
                'required'          => false,
                'component'         => 'custom_component',
                'render_template'   => $this->getUserMetaComponent($settings),
                'smartcode_options' => $bodyOptions,
                'inline_tip'        => __('Optionally, map user meta field with checkout/order data', 'fluent-cart-pro')
            ],
            'watch_on_access_revoke' => [
                'key'            => 'watch_on_access_revoke',
                'component'      => 'yes-no-checkbox',
                'checkbox_label' => __('Remove from selected User Role on Refund or Subscription Access Expiration ', 'fluent-cart-pro'),
                'inline_tip'     => __('If you enable this, on refund or subscription validity expiration, the selected role will be removed and assign "subscriber" role if not other role exists to that user.', 'fluent-cart-pro')
            ]
        ];

        $fields = array_values($fields);
        $fields[] = $this->actionFields();

        return [
            'fields'              => $fields,
            'button_require_list' => false,
            'integration_title'   => __('User Create / Update', 'fluent-cart-pro')
        ];
    }

    private function getUserMetaComponent($settings)
    {
        ob_start();
        ?>
        <div class="fct_webhook_header_config">
            <div class="fc-setting-form-fields self-center">
                <div class="mt-4">
                    <div
                        class="mb-2 font-medium"><?php esc_attr_e('Map User Meta (optional)', 'fluent-cart-pro'); ?></div>
                    <div v-for="(dataGroup, index) in settings.user_meta" :key="index"
                         class="flex items-center gap-2 mb-2">
                        <el-input size="small" v-model="dataGroup.name"
                                  placeholder="<?php esc_attr_e('Meta Key', 'fluent-cart-pro'); ?>"></el-input>
                        <el-select clearable size="small" v-model="dataGroup.value"
                                   placeholder="<?php esc_attr_e('User Meta Value', 'fluent-cart-pro'); ?>" filterable>
                            <el-option-group v-for="optionGroup in app.field.smartcode_options" :key="optionGroup.key"
                                             :label="optionGroup.title">
                                <el-option v-for="(option, optionKey) in optionGroup.shortcodes" :key="optionKey"
                                           :label="option" :value="optionKey"/>
                            </el-option-group>
                        </el-select>

                        <el-button size="small" :disabled="settings.user_meta.length === 1" type="danger"
                                   @click="settings.user_meta.splice(index, 1)">-
                        </el-button>
                    </div>
                    <el-button @click="settings.user_meta.push({ name: '', value: '' })">
                        <?php esc_attr_e('+ Add more', 'fluent-cart-pro'); ?>
                    </el-button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /*
     * For Handling Notifications broadcast
     */
    public function processAction($order, $eventData)
    {
        $feedConfig = Arr::get($eventData, 'feed', []);
        $isRevokedHook = Arr::get($eventData, 'is_revoke_hook') === 'yes';
        $customer = $order->customer;

        // User Role Map
        $userRole = (string)Arr::get($feedConfig, 'user_role', 'subscriber');
        if ($userRole === 'administrator') {
            return; // Prevent assigning administrator role
        }

        $userRoles = $this->getUserRolesOptions();

        if (!isset($userRoles[$userRole])) {
            return;
        }

        $userId = $customer->getWpUserId(true);

        if ($isRevokedHook) {
            if (!$userId || $userRole === 'subscriber') {
                return;
            }
            $user = get_user_by('ID', $userId);
            if ($user) {
                $user->remove_role($userRole);
                // Assign subscriber role if no other role exists
                if (empty($user->roles)) {
                    $user->add_role('subscriber');
                }
            }

            $order->addLog(
                __('User Role Removed', 'fluent-cart-pro'),
                sprintf(__('User role %s has been removed from the user'), $userRole),
                'info',
                'WP User Integration'
            );

            return;
        }

        $isCreated = false;
        if (!$userId) {
            $userId = AuthService::createUserFromCustomer($customer, true, $userRole);
            if (is_wp_error($userId)) {
                $order->addLog(
                    __('User creation failed from WP User Integration', 'fluent-cart-pro'),
                    $userId->get_error_message(),
                    'error',
                    'WP User Integration'
                );
                return;
            }
            $isCreated = true;
        }

        // Process the meta mappings
        $userMetaMaps = Arr::get($feedConfig, 'user_meta', []);
        $userMetaFormatted = [];

        foreach ($userMetaMaps as $metaField) {
            $metaData = $this->parseSmartCode($metaField['value'], $order);
            if (!$metaData) {
                continue;
            }

            if (is_string($metaData) && apply_filters('fluentcart/sanitize_user_meta', true, $metaField['name'], $metaData)) {
                $metaData = sanitize_text_field($metaData);
            }

            $userMetaFormatted[$metaField['name']] = $metaData;
        }

        $userMetaFormatted = array_filter($userMetaFormatted);
        if (!empty($userMetaFormatted)) {
            foreach ($userMetaFormatted as $metaKey => $metaValue) {
                update_user_meta($userId, $metaKey, $metaValue);
            }
        }

        if (!$isCreated) {
            // Update user role
            $user = get_user_by('ID', $userId);
            if ($user) {
                if (Arr::get($feedConfig, 'replace_existing_role', 'no') === 'yes') {
                    $user->set_role($userRole);
                } else {
                    $user->add_role($userRole);
                }
            }
        }

        $logDescription = sprintf(__('User has been updated and assigned user role: %s', 'fluent-cart-pro'), $userRole);
        if ($isCreated) {
            $logDescription = sprintf(__('User has been created and assigned user role: %s', 'fluent-cart-pro'), $userRole);
        }

        $order->addLog(
            __('WP User Integration Success', 'fluent-cart-pro'),
            $logDescription,
            'info',
            'WP User Integration'
        );
    }

    public function getUserRolesOptions()
    {

        if (!function_exists('get_editable_roles')) {
            require_once(ABSPATH . '/wp-admin/includes/user.php');
        }

        $roles = \get_editable_roles();
        $options = [];
        foreach ($roles as $roleKey => $roleData) {
            $options[$roleKey] = $roleData['name'];
        }

        unset($options['administrator']); // Prevent assigning administrator role

        return $options;
    }
}
