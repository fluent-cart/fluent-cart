<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Starter body for the per-shipment customer email (Pro's block editor).
 * Mirrors emails/order/shipment/customer.php with {{shipment.*}} codes, so it
 * lists only this shipment's items. No physical alignment, so RTL flips cleanly.
 */

/* translators: %s: customer first name smartcode */
$fctHello = sprintf(esc_html__('Hello %s,', 'fluent-cart'), '{{order.customer.first_name}}');
$fctIntro = esc_html__('Great news! A shipment from your order is on its way to you 📦.', 'fluent-cart');
$fctOrderLabel = esc_html__('Order #', 'fluent-cart');
$fctTrackingDetails = esc_html__('Tracking Details', 'fluent-cart');
$fctCarrier = esc_html__('Carrier', 'fluent-cart');
$fctService = esc_html__('Service', 'fluent-cart');
$fctTrackingNumber = esc_html__('Tracking Number', 'fluent-cart');
$fctTrackShipment = esc_html__('Track Shipment', 'fluent-cart');
$fctItemsHeading = esc_html__('Items in this Shipment', 'fluent-cart');
$fctNotesHeading = esc_html__('Shipment Notes', 'fluent-cart');
$fctThanks = esc_html__('Thank you for choosing us! If you have any questions about this shipment, feel free to reach out.', 'fluent-cart');
$fctViewOrder = esc_html__('View Order', 'fluent-cart');

$fctLabelAttrs = '{"style":{"typography":{"fontSize":"12px","textTransform":"uppercase","letterSpacing":"1px"},"color":{"text":"#7f8c8d"}}}';
$fctLabelStyle = 'color:#7f8c8d;font-size:12px;text-transform:uppercase;letter-spacing:1px';
$fctValueAttrs = '{"style":{"typography":{"fontSize":"14px"},"color":{"text":"#2c3e50"}}}';
$fctValueStyle = 'color:#2c3e50;font-size:14px';
$fctHeadingAttrs = '{"style":{"typography":{"fontSize":"16px","fontWeight":"500"},"color":{"text":"#2c3e50"}}}';
$fctHeadingStyle = 'color:#2c3e50;font-size:16px;font-weight:500';

return <<<BLOCKS
<!-- wp:group {"layout":{"type":"constrained","contentSize":"620px"},"style":{"spacing":{"padding":{"top":"0","bottom":"0","left":"0","right":"0"}}}} -->
<div class="wp-block-group" style="padding-top:0;padding-right:0;padding-bottom:0;padding-left:0">

<!-- wp:fluent-cart/email-header {"style":{"spacing":{"padding":{"top":"20px","bottom":"20px","left":"0","right":"0"}}}} -->
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"70%"} -->
<div class="wp-block-column" style="flex-basis:70%"><!-- wp:heading {"style":{"typography":{"fontSize":"24px","fontWeight":"700"},"color":{"text":"#111827"}}} -->
<h2 class="wp-block-heading has-text-color" style="color:#111827;font-size:24px;font-weight:700">{{settings.store_brand}}</h2>
<!-- /wp:heading --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"30%"} -->
<div class="wp-block-column" style="flex-basis:30%"><!-- wp:paragraph -->
<p><span style="color:#94a3b8;font-size:12px">{$fctOrderLabel}</span><br><strong>{{order.invoice_no}}</strong></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:fluent-cart/email-header -->

<!-- wp:group {"style":{"color":{"background":"#ffffff"},"border":{"radius":"8px"},"spacing":{"padding":{"top":"32px","bottom":"32px","left":"32px","right":"32px"}}}} -->
<div class="wp-block-group has-background" style="background-color:#ffffff;border-radius:8px;padding-top:32px;padding-right:32px;padding-bottom:32px;padding-left:32px">

<!-- wp:paragraph -->
<p>{$fctHello}</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>{$fctIntro}</p>
<!-- /wp:paragraph -->

<!-- wp:fluent-cart/shortcode-condition {"shortcode":"{{shipment.carrier_name}}{{shipment.service_name}}{{shipment.tracking_link}}","condition":"not_empty"} -->
<!-- wp:fluent-cart/condition-content -->
<!-- wp:paragraph {$fctHeadingAttrs} -->
<p class="has-text-color" style="{$fctHeadingStyle}">{$fctTrackingDetails}</p>
<!-- /wp:paragraph -->

<!-- wp:group {"style":{"color":{"background":"#f9fafb"},"border":{"radius":"8px"},"spacing":{"padding":{"top":"16px","bottom":"16px","left":"16px","right":"16px"}}}} -->
<div class="wp-block-group has-background" style="background-color:#f9fafb;border-radius:8px;padding-top:16px;padding-right:16px;padding-bottom:16px;padding-left:16px">

<!-- wp:fluent-cart/shortcode-condition {"shortcode":"{{shipment.carrier_name}}","condition":"not_empty"} -->
<!-- wp:fluent-cart/condition-content -->
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"40%"} -->
<div class="wp-block-column" style="flex-basis:40%"><!-- wp:paragraph {$fctLabelAttrs} -->
<p class="has-text-color" style="{$fctLabelStyle}">{$fctCarrier}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"60%"} -->
<div class="wp-block-column" style="flex-basis:60%"><!-- wp:paragraph {$fctValueAttrs} -->
<p class="has-text-color" style="{$fctValueStyle}">{{shipment.carrier_name}}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:fluent-cart/condition-content -->
<!-- /wp:fluent-cart/shortcode-condition -->

<!-- wp:fluent-cart/shortcode-condition {"shortcode":"{{shipment.service_name}}","condition":"not_empty"} -->
<!-- wp:fluent-cart/condition-content -->
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"40%"} -->
<div class="wp-block-column" style="flex-basis:40%"><!-- wp:paragraph {$fctLabelAttrs} -->
<p class="has-text-color" style="{$fctLabelStyle}">{$fctService}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"60%"} -->
<div class="wp-block-column" style="flex-basis:60%"><!-- wp:paragraph {$fctValueAttrs} -->
<p class="has-text-color" style="{$fctValueStyle}">{{shipment.service_name}}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:fluent-cart/condition-content -->
<!-- /wp:fluent-cart/shortcode-condition -->

<!-- wp:fluent-cart/shortcode-condition {"shortcode":"{{shipment.tracking_number}}","condition":"not_empty"} -->
<!-- wp:fluent-cart/condition-content -->
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"40%"} -->
<div class="wp-block-column" style="flex-basis:40%"><!-- wp:paragraph {$fctLabelAttrs} -->
<p class="has-text-color" style="{$fctLabelStyle}">{$fctTrackingNumber}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"60%"} -->
<div class="wp-block-column" style="flex-basis:60%"><!-- wp:paragraph {$fctValueAttrs} -->
<p class="has-text-color" style="{$fctValueStyle}">{{shipment.tracking_link}}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:fluent-cart/condition-content -->
<!-- /wp:fluent-cart/shortcode-condition -->

<!-- wp:fluent-cart/shortcode-condition {"shortcode":"{{shipment.tracking_url}}","condition":"not_empty"} -->
<!-- wp:fluent-cart/condition-content -->
<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"width":"40%"} -->
<div class="wp-block-column" style="flex-basis:40%"><!-- wp:paragraph {$fctLabelAttrs} -->
<p class="has-text-color" style="{$fctLabelStyle}">{$fctTrackShipment}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"60%"} -->
<div class="wp-block-column" style="flex-basis:60%"><!-- wp:paragraph {$fctValueAttrs} -->
<p class="has-text-color" style="{$fctValueStyle};word-break:break-all"><a href="{{shipment.tracking_url}}" style="color:#017EF3;text-decoration:none" target="_blank" rel="noreferrer noopener">{{shipment.tracking_url}}</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
<!-- /wp:fluent-cart/condition-content -->
<!-- /wp:fluent-cart/shortcode-condition -->

</div>
<!-- /wp:group -->
<!-- /wp:fluent-cart/condition-content -->
<!-- /wp:fluent-cart/shortcode-condition -->

<!-- wp:paragraph {$fctHeadingAttrs} -->
<p class="has-text-color" style="{$fctHeadingStyle}">{$fctItemsHeading}</p>
<!-- /wp:paragraph -->

<!-- wp:html -->
{{shipment.items}}
<!-- /wp:html -->

<!-- wp:fluent-cart/shortcode-condition {"shortcode":"{{shipment.notes}}","condition":"not_empty"} -->
<!-- wp:fluent-cart/condition-content -->
<!-- wp:paragraph {$fctHeadingAttrs} -->
<p class="has-text-color" style="{$fctHeadingStyle}">{$fctNotesHeading}</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"typography":{"fontSize":"14px"},"color":{"text":"#374151"}}} -->
<p class="has-text-color" style="color:#374151;font-size:14px">{{shipment.notes}}</p>
<!-- /wp:paragraph -->
<!-- /wp:fluent-cart/condition-content -->
<!-- /wp:fluent-cart/shortcode-condition -->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

<!-- wp:fluent-cart/email-order-addresses -->
<!-- /wp:fluent-cart/email-order-addresses -->

<!-- wp:paragraph {"align":"center","style":{"spacing":{"margin":{"top":"16px","bottom":"16px"}}}} -->
<p class="has-text-align-center" style="margin-top:16px;margin-bottom:16px">{$fctThanks}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons"><!-- wp:button {"style":{"border":{"radius":"6px"},"spacing":{"padding":{"top":"12px","bottom":"12px","left":"24px","right":"24px"}},"color":{"background":"#0f172a","text":"#ffffff"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-text-color has-background wp-element-button" href="{{order.customer_dashboard_link}}" style="border-radius:6px;color:#ffffff;background-color:#0f172a;padding-top:12px;padding-right:24px;padding-bottom:12px;padding-left:24px">{$fctViewOrder}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->
BLOCKS;
