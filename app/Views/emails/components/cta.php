<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div style="padding: 25px 0; text-align: center;">
    <div style="color: #333333; font-size: 14px; font-weight: bold; margin-bottom: 10px;">
        {{settings.store_name}}
    </div>
    <div style="color: #555555; font-size: 14px;">
        {{settings.store_address}}
        {{settings.store_state}}<br/>
        {{settings.store_city||concat_last|,}} {{settings.store_postcode||concat_last|,}}
        {{settings.store_country}}<br/>
        <a href="{{wp.site_url}}" target="_blank">{{wp.site_url}}</a>
    </div>
</div>
