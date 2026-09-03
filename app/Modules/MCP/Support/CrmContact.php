<?php

namespace FluentCart\App\Modules\MCP\Support;

use FluentCart\App\Models\Customer;

/**
 * FluentCRM contact context on the MCP's single-record customer and order tools.
 *
 * The admin already shows this: FluentCRM injects a "Contact Info" widget into
 * FluentCart's single-customer and single-order sidebars (its CustomerWidget
 * hooks `fluent_cart/widgets/customer` and `fluent_cart/widgets/single_order_page`).
 * An agent reading the same records over MCP saw none of it and had no tool to
 * ask for it, so "is this buyer subscribed, and what has marketing sent them?"
 * was unanswerable — the exact question the widget exists to answer.
 *
 * Shape follows that widget: contact status, lists, tags, and the emails/opens/
 * clicks engagement counters, plus the CRM profile URL.
 *
 * Layering:
 *  - The compact identity block (contact_id, status, contact_type, profile_url)
 *    rides along on every get-customer / get-order call — ONE query, and it is
 *    what makes the contact discoverable at all.
 *  - lists, tags and engagement cost five more queries, so they arrive only on
 *    include[] = crm_contact. Same key, more fields — never a second shape.
 *
 * Reading CRM models from FluentCart follows the existing precedent in
 * app/Modules/Integrations/FluentPlugins/FluentCRMDeepIntegration.php, and the
 * whole surface is gated on FluentCRM's own `fcrm_read_contacts` capability
 * through its PermissionManager — the same check the widget makes — so MCP can
 * never expose CRM data to a role the CRM itself would refuse.
 */
class CrmContact
{
    const SECTION = 'crm_contact';

    /**
     * FluentCart detects FluentCRM by the FLUENTCRM constant everywhere else
     * (Services/Integration.php, FluentCRMConnect::isConfigured, AddonsController).
     * The model + PermissionManager checks additionally guard against a partially
     * booted CRM, since we read both directly.
     */
    public static function isAvailable()
    {
        return defined('FLUENTCRM')
            && class_exists('\FluentCrm\App\Models\Subscriber')
            && class_exists('\FluentCrm\App\Services\PermissionManager');
    }

    /**
     * Wire the section into both single-record tools. Called from MCPInit::init().
     *
     * The availability check CANNOT run here: MCPInit boots from
     * app/Hooks/actions.php at plugin-file load time, and WordPress loads plugin
     * files alphabetically — fluent-cart before fluent-crm — so FLUENTCRM is not
     * defined yet and every check would report FluentCRM absent on a site that
     * has it. Defer to plugins_loaded, by which point every plugin file has run.
     * This is the same reason Services/Integration.php defers its own
     * defined('FLUENTCRM') gate to a later hook.
     *
     * Still early enough: the include[] enum is read when definitions() runs on
     * wp_abilities_api_init (fired from `init`), and the data filters fire at
     * request time — both after plugins_loaded.
     */
    public static function register()
    {
        if (did_action('plugins_loaded')) {
            self::registerNow();
            return;
        }

        add_action('plugins_loaded', [self::class, 'registerNow'], 20);
    }

    /** Attach the hooks, once FluentCRM is known to be loaded. */
    public static function registerNow()
    {
        if (!self::isAvailable()) {
            return;
        }

        add_filter('fluent_cart/mcp_customer_include_sections', [self::class, 'addSection']);
        add_filter('fluent_cart/mcp_order_include_sections', [self::class, 'addSection']);
        add_filter('fluent_cart/mcp_customer_data', [self::class, 'attachToCustomer'], 10, 2);
        add_filter('fluent_cart/mcp_order_data', [self::class, 'attachToOrder'], 10, 2);
    }

    /**
     * @param array $sections
     * @return array
     */
    public static function addSection($sections)
    {
        $sections   = (array) $sections;
        $sections[] = self::SECTION;

        return $sections;
    }

    /**
     * @param array $data    the get-customer payload
     * @param array $context { customer: Customer, include: string[] }
     * @return array
     */
    public static function attachToCustomer($data, $context)
    {
        $customer = isset($context['customer']) ? $context['customer'] : null;
        if (!$customer) {
            return $data;
        }

        return self::attach($data, $customer, self::wantsFull($context));
    }

    /**
     * The order's buyer is the contact. The customer relation is already loaded
     * by getOrder(); a guest order with no customer row simply has no contact.
     *
     * @param array $data    the get-order payload
     * @param array $context { order: Order, include: string[] }
     * @return array
     */
    public static function attachToOrder($data, $context)
    {
        $order = isset($context['order']) ? $context['order'] : null;
        if (!$order || !$order->customer) {
            return $data;
        }

        return self::attach($data, $order->customer, self::wantsFull($context));
    }

    private static function wantsFull($context)
    {
        $include = isset($context['include']) ? (array) $context['include'] : [];

        return in_array(self::SECTION, $include, true);
    }

    /**
     * @param array    $data
     * @param Customer $customer
     * @param bool     $full
     * @return array
     */
    private static function attach($data, $customer, $full)
    {
        // Silence over a permission error: the contact block is supplementary,
        // and a role without CRM access asking for an order should still get the
        // order. Only say something when the section was explicitly requested.
        if (!\FluentCrm\App\Services\PermissionManager::currentUserCan('fcrm_read_contacts')) {
            if ($full) {
                $data[self::SECTION . '_omitted'] = __('FluentCRM contact data requires the fcrm_read_contacts capability.', 'fluent-cart');
            }
            return $data;
        }

        $contact = self::resolve($customer);
        if (!$contact) {
            // Explicit null rather than an absent key: "this buyer is not in the
            // CRM" is an answer, and an agent that sees nothing cannot tell it
            // apart from "FluentCRM is not installed".
            $data[self::SECTION] = null;
            return $data;
        }

        $block = [
            'contact_id'   => (int) $contact->id,
            'status'       => $contact->status,
            'contact_type' => $contact->contact_type,
            'profile_url'  => admin_url('admin.php?page=fluentcrm-admin#/subscribers/' . (int) $contact->id),
        ];

        if ($full) {
            $contact->load('lists', 'tags');

            $block['name']          = $contact->full_name;
            $block['source']        = $contact->source;
            $block['created_at']    = self::utcDate($contact->created_at);
            $block['last_activity'] = self::utcDate($contact->last_activity);
            $block['lists']         = self::terms($contact->lists);
            $block['tags']          = self::terms($contact->tags);
            $block['engagement']    = self::engagement($contact);
        }

        $data[self::SECTION] = $block;

        return $data;
    }

    /**
     * Match a FluentCart customer to a CRM contact.
     *
     * Email first, so this can never disagree with the admin widget (which
     * matches on email alone). user_id is a fallback for the case the widget
     * misses: a buyer whose CRM contact was created under a different email but
     * the same WordPress account.
     *
     * @param Customer $customer
     * @return object|null
     */
    private static function resolve($customer)
    {
        $subscriber = '\FluentCrm\App\Models\Subscriber';

        if (!empty($customer->email)) {
            $contact = $subscriber::where('email', $customer->email)->first();
            if ($contact) {
                return $contact;
            }
        }

        if (!empty($customer->user_id)) {
            return $subscriber::where('user_id', (int) $customer->user_id)->first();
        }

        return null;
    }

    /**
     * CRM timestamps, shifted to UTC.
     *
     * FluentCRM writes its timestamps with current_time('mysql') — WordPress
     * SITE time — and its ORM hydrates them carrying the site offset. Every other
     * date in an MCP payload is UTC (FluentCart stores GMT), and the store
     * context tells agents dates are ISO-8601 UTC. Passing these through
     * unconverted would put a contact's last_activity and an order's created_at
     * on different clocks in the same response — off by the site's offset, with
     * nothing in the payload to reveal it.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function utcDate($value)
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $dt = new \DateTime($value->format('Y-m-d H:i:s'), $value->getTimezone());
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('c');
        }

        // A plain string has no offset attached, so read it as site time — the
        // timezone it was written in — before shifting.
        if (is_string($value) && strpos($value, '0000-00-00') !== 0) {
            try {
                $dt = new \DateTime($value, wp_timezone());
                return $dt->setTimezone(new \DateTimeZone('UTC'))->format('c');
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /** Lists/tags as {id, title} — the shape the admin widget renders as chips. */
    private static function terms($collection)
    {
        $out = [];
        if (!$collection) {
            return $out;
        }
        foreach ($collection as $term) {
            $out[] = ['id' => (int) $term->id, 'title' => $term->title];
        }

        return $out;
    }

    /**
     * Email engagement, with the two rates the widget computes in the browser
     * done here instead — an agent comparing "opens" across contacts with
     * different send volumes needs the rate, not the raw count.
     *
     * @param object $contact
     * @return array|null null when the CRM cannot produce stats for this contact
     */
    private static function engagement($contact)
    {
        $stats = [];
        try {
            $stats = (array) $contact->stats();
        } catch (\Throwable $e) {
            return null;
        }

        $sent   = isset($stats['emails']) ? (int) $stats['emails'] : 0;
        $opens  = isset($stats['opens']) ? (int) $stats['opens'] : 0;
        $clicks = isset($stats['clicks']) ? (int) $stats['clicks'] : 0;

        return [
            'emails_sent'       => $sent,
            'opens'             => $opens,
            'clicks'            => $clicks,
            // null, not 0, when nothing was sent: a 0% open rate reads as
            // "never opens our email", which is a different claim.
            'open_rate_percent'  => $sent > 0 ? round($opens / $sent * 100, 2) : null,
            'click_rate_percent' => $sent > 0 ? round($clicks / $sent * 100, 2) : null,
        ];
    }
}
