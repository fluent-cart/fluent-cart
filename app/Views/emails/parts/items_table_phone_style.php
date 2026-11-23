<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<style>
    /* The item column gets the Qty column's width; quantity and labels become plain lines. */
    @media only screen and (max-width: 480px) {
        .fct_items_qty {
            display: none !important;
        }

        .fct_items_qty_inline {
            display: block !important;
        }

        .fct_items_qty_prefix {
            display: inline !important;
        }

        .fct_items_label {
            display: inline !important;
            background: none !important;
            padding: 0 !important;
            border-radius: 0 !important;
            font-size: 13px !important;
            font-weight: 400 !important;
            color: rgb(107, 114, 128) !important;
        }
    }
</style>
