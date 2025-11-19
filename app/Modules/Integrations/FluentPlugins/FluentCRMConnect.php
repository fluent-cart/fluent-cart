<?php

namespace FluentCart\App\Modules\Integrations\FluentPlugins;

use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCrm\App\Models\CustomContactField;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;

class FluentCRMConnect extends BaseIntegrationManager
{
    protected $runOnBackgroundForProduct = false;

    public function __construct()
    {
        parent::__construct('FluentCRM', 'fluentcrm', 12);

        $this->description = __('FluentCRM is a Self Hosted Email Marketing Automation Plugin for WordPress. Add/Remove tags, lists, run automations on order activities.', 'fluent-cart');
        $this->logo = Vite::getAssetUrl('images/integrations/fluentcrm.svg');
        $this->disableGlobalSettings = true;
        $this->installable = 'fluent-crm/fluent-crm.php';
        $this->integrationId = 1;


        if ($this->isConfigured()) {
            add_filter('fluent_cart/checkout_page_name_fields_schema', [$this, 'maybeSetNameEmailAtCheckout'], 10, 1);
        }
        
    }

    public function maybeSetNameEmailAtCheckout($fields)
    {
        if (!empty($fields['billing_full_name']['value']) || !empty($fields['billing_email']['value']) || get_current_user_id()) {
            return $fields;
        }

        $currentContent = fluentcrm_get_current_contact();
        if (!$currentContent) {
            return $fields;
        }

        if (isset($fields['billing_full_name']) && empty($fields['billing_full_name']['value'])) {
            $fields['billing_full_name']['value'] = $currentContent->full_name;
        }

        if (isset($fields['billing_email']) && empty($fields['billing_email']['value'])) {
            $fields['billing_email']['value'] = $currentContent->email;
        }

        return $fields;

    }

    public function isConfigured()
    {
        return defined('FLUENTCRM');
    }

    public function getApiSettings()
    {
        return [
            'status'  => defined('FLUENTCRM'),
            'api_key' => ''
        ];
    }

    public function getIntegrationDefaults($settings)
    {
        return [
            'enabled'                => 'yes',
            'list_name'              => '',
            'name'                   => '',
            'list_ids'               => [],
            'remove_list_ids'        => [],
            'other_fields'           => [],
            'tag_ids'                => [],
            'remove_tag_ids'         => [],
            'tag_routers'            => [],
            'event_trigger'          => [],
            'tag_ids_selection_type' => 'simple',
            'double_opt_in'          => 'yes',
            'watch_on_access_revoke' => 'no',
            'note'                   => ''
        ];
    }

    public function getSettingsFields($settings, $args = [])
    {
        $fieldOptions = [];
        foreach ((new CustomContactField)->getGlobalFields()['fields'] as $field) {
            $fieldOptions[$field['slug']] = $field['label'];
        }

        $crmLists = $this->getLists();
        $crmTags = $this->getTags();

        $fields = [
            'name'                   => [
                'key'         => 'name',
                'label'       => __('Feed Title', 'fluent-cart'),
                'required'    => true,
                'placeholder' => __('Name', 'fluent-cart'),
                'component'   => 'text',
                'inline_tip'  => __('Name of this feed, it will be used to identify this feed in the list of feeds', 'fluent-cart')
            ],
            'list_ids'               => [
                'key'         => 'list_ids',
                'label'       => __('Add to Lists', 'fluent-cart'),
                'placeholder' => __('Select FluentCRM Lists', 'fluent-cart'),
                'inline_tip'  => __('Select the FluentCRM Lists you would like to add your contact to.', 'fluent-cart'),
                'component'   => 'select',
                'is_multiple' => true,
                'required'    => false,
                'options'     => $crmLists
            ],
            'tag_ids'                => [
                'key'          => 'tag_ids',
                'require_list' => false,
                'label'        => __('Add to Tags', 'fluent-cart'),
                'placeholder'  => __('Select Tags', 'fluent-cart'),
                'component'    => 'select',
                'is_multiple'  => true,
                'options'      => $crmTags,
                'inline_tip'   => __('Select the tags you would like to add your contact to.', 'fluent-cart')
            ],
            'remove_list_ids'        => [
                'key'         => 'remove_list_ids',
                'label'       => __('Remove From Lists', 'fluent-cart'),
                'placeholder' => __('Select FluentCRM Lists', 'fluent-cart'),
                'inline_tip'  => __('Select the FluentCRM Lists you would like to remove from your contact.', 'fluent-cart'),
                'component'   => 'select',
                'is_multiple' => true,
                'required'    => false,
                'options'     => $crmLists
            ],
            'remove_tag_ids'         => [
                'key'          => 'remove_tag_ids',
                'require_list' => false,
                'label'        => __('Remove From Tags', 'fluent-cart'),
                'placeholder'  => __('Select Tags', 'fluent-cart'),
                'component'    => 'select',
                'is_multiple'  => true,
                'options'      => $crmTags,
                'inline_tip'   => __('Select the tags you would like to remove from your contact.', 'fluent-cart')
            ],
//            'other_fields'    => [
//                'key'          => 'other_fields',
//                'require_list' => false,
//                'label'        => __('Custom Fields', 'fluent-cart'),
//                'tips'         => esc_html__('Select which FluentCart fields pair with their respective FluentCRM fields.', 'fluent-cart'),
//                'component'    => 'dropdown_many_fields',
//                'remote_text'  => __('FluentCRM Field', 'fluent-cart'),
//                'local_text'   => __('Value', 'fluent-cart'),
//                'options'      => $fieldOptions
//            ],
            'note'                   => [
                'key'        => 'note',
                'label'      => __('Note', 'fluent-cart'),
                'inline_tip' => __('This note will be added to the Contact\'s Profile', 'fluent-cart'),
                'component'  => 'value_textarea'
            ],
            'double_opt_in'          => [
                'key'            => 'double_opt_in',
                'component'      => 'yes-no-checkbox',
                'checkbox_label' => __('Enable Double Opt-in', 'fluent-cart'),
                'inline_tip'     => __('When the double opt-in option is enabled. FluentCRM will send a double opt-in email to the contact for new and not subscribed contacts.', 'fluent-cart')
            ],
            'watch_on_access_revoke' => [
                'key'            => 'watch_on_access_revoke',
                'component'      => 'yes-no-checkbox',
                'checkbox_label' => __('Remove from selected Tags/Lists on Refund or Subscription Access Expiration ', 'fluent-cart'),
                'inline_tip'     => __('If you enable this, on refund or subscription validity expiration, the selected tags and lists will be removed from the contact.', 'fluent-cart')
            ]
        ];

        $fields = array_values($fields);
        $fields[] = $this->actionFields();

        return [
            'fields'              => $fields,
            'button_require_list' => false,
            'integration_title'   => __('FluentCRM', 'fluent-cart')
        ];
    }

    /*
     * For Handling Notifications broadcast
     */
    public function processAction($order, $eventData)
    {

        $feedConfig = Arr::get($eventData, 'feed', []);

        $customer = $order->customer;
        $contactData = [
            'email'      => $customer->email,
            'first_name' => $customer->first_name,
            'last_name'  => $customer->last_name,
        ];

        $isRevokedHook = Arr::get($eventData, 'is_revoke_hook') === 'yes';

        $listIds = array_filter((array)Arr::get($feedConfig, 'list_ids', []), 'intval');
        $tagIds = array_filter((array)Arr::get($feedConfig, 'tag_ids', []), 'intval');
        $removeListIds = array_filter((array)Arr::get($feedConfig, 'remove_list_ids', []), 'intval');
        $removeTagIds = array_filter((array)Arr::get($feedConfig, 'remove_tag_ids', []), 'intval');
        $note = Arr::get($feedConfig, 'note', '');
        $doubleOptIn = Arr::get($feedConfig, 'double_opt_in', false) === 'yes';

        if ($isRevokedHook) {
            $contact = FluentCrmApi('contacts')->getContact($customer->email);
            if (!$contact) {
                return;
            }

            if ($listIds) {
                $contact->detachLists($listIds);
            }

            if ($tagIds) {
                $contact->detachTags($tagIds);
            }

            return;
        }

        if ($order->billing_address) {
            $contactData['address_line_1'] = $order->billing_address->address_1;
            $contactData['address_line_2'] = $order->billing_address->address_2;
            $contactData['city'] = $order->billing_address->city;
            $contactData['postcode'] = $order->billing_address->postcode;
            $contactData['country'] = $order->billing_address->country;
        }

        $contactData['timezone'] = Arr::get($order->config, 'user_tz', '');
        $contactData = array_filter($contactData);

        // check exits
        if (!$doubleOptIn) {
            $contactData['status'] = 'subscribed';
        }

        $contact = FluentCrmApi('contacts')->createOrUpdate($contactData, !$doubleOptIn);
        if ($doubleOptIn && $contact->status != 'subscribed') {
            // if double opt-in is enabled
            $contact->sendDoubleOptinEmail();
        }

        if ($removeListIds) {
            $contact->detachLists($removeListIds);
        }

        if ($removeTagIds) {
            $contact->detachTags($tagIds);
        }

        if ($listIds) {
            $contact->attachLists($listIds);
        }

        if ($tagIds) {
            $contact->attachTags($tagIds);
        }

        if ($note) {
            $note = $this->parseSmartCode($note, $order);
            if ($note) {
                $note = wp_kses_post(wpautop($note));
                \FluentCrm\App\Models\SubscriberNote::create([
                    'subscriber_id' => $contact->id,
                    'description'   => $note,
                    'type'          => 'info'
                ]);
            }
        }

        $order->addLog(
            __('FluentCRM Contact Created', 'fluent-cart'),
            __('Contact has been created or updated in FluentCRM.', 'fluent-cart') . ' ' . __('Contact ID: ', 'fluent-cart') . $contact->id,
            'info',
            'FluentCRM Integration'
        );

    }

    /**
     *  Internal methods
     */
    private function getTags()
    {
        $tags = Tag::orderBy('title', 'ASC')->get();
        $formattedTags = [];
        foreach ($tags as $tag) {
            $formattedTags[(string)$tag->id] = $tag->title;
        }

        return $formattedTags;
    }

    private function getLists()
    {
        $lists = Lists::query()->orderBy('title', 'ASC')
            ->get();

        $formattedLists = [];
        foreach ($lists as $list) {
            $formattedLists[(string)$list->id] = $list->title;
        }

        return $formattedLists;
    }
}
