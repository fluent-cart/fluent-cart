import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import AppConfig from "@/utils/Config/AppConfig";

function getIntervalOptions() {
    const intervals = window.fluentCartAdminApp?.subscription_intervals ?? [];
    const intervalMap = {};
    
    intervals.forEach(interval => {
        let unit = interval.value;
        if (unit === 'yearly') unit = 'year';
        else if (unit === 'monthly') unit = 'month';
        else if (unit === 'weekly') unit = 'week';
        else if (unit === 'daily') unit = 'day';
        else if (unit === 'quarterly') unit = 'quarter';
        else if (unit === 'half_yearly') unit = 'half_year';
        
        intervalMap[interval.value] = unit;
    });
    
    return intervalMap;
}

/**
 *
 * @param $raw - a very specific type of data in specific structure
 *
 * @returns {*[]}
 */
function prepareProductList($raw, $orderId) {

    let product = [], item, variant;

    if ($raw) {

        for (const $elm of $raw) {
            item = {};
            item.id = $elm.detail.id; // this is the order_items_table id.
            item.order_id = $orderId; // this is order_table id
            item.post_id = $elm.ID; // this is wp_posts table id
            item.object_id = $elm.detail.default_variation_id;  // for order_items table taking default_variation_id
            item.featured_media = $elm.detail.featured_media?.url; // this is featured_media
            item.price = $elm.detail.item_price;
            item.unit_price = $elm.detail.item_price;
            item.title = $elm.post_title;
            item.post_title =  $elm.post_title;
            item.quantity = 1;
            item.fulfillment_type = $elm.detail.fulfillment_type;
            item.manage_stock = $elm.detail.manage_stock;
            item.stockStatus = $elm.detail.manage_stock == 1 ? $elm.detail.stock_availability : 'in-stock';
            item.soldIndividually = $elm.detail.sold_individually;
            item.settings = $elm.detail.settings;
            item.checked = false;
            item.min_price = $elm.detail.min_price;
            item.max_price = $elm.detail.max_price;

            if ($elm.detail.variants && $elm.detail.variants.length) {
                item.children = [];
                for (const key in $elm.detail.variants) {
                    variant = $elm.detail.variants[key];
                    item.title = variant.variation_title;
                    let shouldAdd = $elm.detail.default_variation_id == variant.id;
                    if(!shouldAdd && $elm.detail.variation_type === 'simple') {
                        shouldAdd = true; // for simple variations we always add the first variant
                    }
                    if (shouldAdd) {
                        // item.available = $elm.detail.manage_stock == 1 ? variant.available : 999999;
                        item.available = (variant.manage_stock.toString() === '1' ? variant.available : Infinity);
                        item.price = variant.item_price;
                        item.unit_price = variant.item_price;
                        item.object_id = variant.id;
                        item.cost = variant.item_cost;
                        item.other_info = variant.other_info;
                        item.payment_type = variant.other_info.payment_type;
                    }

                    if ($elm.detail.variants && $elm.detail.variation_type === 'simple_variations') {
                        let createVariant = {};
                        variant = $elm.detail.variants[key];

                        createVariant.id = variant.id;
                        createVariant.order_id = $orderId;
                        createVariant.post_id = variant.post_id;
                        createVariant.object_id = variant.id;
                        createVariant.price = variant.item_price;
                        createVariant.unit_price = variant.item_price;
                        createVariant.item_cost = variant.item_cost;
                        createVariant.featured_media = (variant.media && variant.media.meta_value && variant.media.meta_value[0]) ? variant.media.meta_value[0]['url'] : item.featured_media;
                        createVariant.quantity = 1;
                        createVariant.post_title = $elm.post_title;
                        createVariant.title = variant.variation_title;
                        createVariant.fulfillment_type = $elm.detail.fulfillment_type;
                        // createVariant.manage_stock = $elm.detail.manage_stock;
                        // createVariant.stockStatus = $elm.detail.manage_stock == 1 ? variant.stock_status : 'in-stock';
                        // createVariant.available = $elm.detail.manage_stock == 1 ? variant.available : 999999;
                        createVariant.manage_stock = variant.manage_stock;
                        createVariant.stockStatus = variant.manage_stock == 1 ? variant.stock_status : 'in-stock';
                        createVariant.available = variant.manage_stock == 1 ? variant.available : Infinity;
                        createVariant.soldIndividually = variant.sold_individually;
                        createVariant.settings = variant.settings;
                        createVariant.checked = false;
                        createVariant.disable = canSelectRow(createVariant);
                        createVariant.other_info = variant.other_info;
                        createVariant.payment_type = variant.other_info.payment_type;

                        item.children.push(createVariant);
                    }
                }
            }
            item.disable = canSelectRow(item);
            product.push(item)
        }
    }

    return product;
}

const canSelectRow = (row) => {
    if(row.stockStatus === 'in-stock') {
        if((Array.isArray(row.children) && row.children.length === 0) || !row.children) {
            return false;
        }
    }

    return true;
}

/**
 *
 * @param amount
 * @param currency
 * @param hideEmpty
 * @returns {string}
 */
const formatMoney = (amount, currency, hideEmpty = false) => {
    if (!amount && hideEmpty) {
        return '';
    }
    if (!amount) {
        amount = '0.00';
    }
    return AppConfig.get('shop.currency_sign') + ' ' + amount;
}

/**
 *
 * @param amount
 * @param currency
 * @param hideEmpty
 * @returns {string}
 */
const formatCents = (amount, currency, hideEmpty = false) => {
    if (!amount && hideEmpty) {
        return '';
    }

    if (!amount) {
        amount = '0.00';
    } else {
        amount = (amount * 100).toFixed(2);
    }

    if(!currency) {
        return amount;
    }

    amount = new Intl.NumberFormat('en-US').format(amount)

    return AppConfig.get('shop.currency_sign') + ' ' + amount;
};

const formatNumber = (amount, currency, hideEmpty = false) => {
    return CurrencyFormatter.formatNumber(amount, currency, hideEmpty);
};

const formatCentsWithoutCurrencySign = (amount, hideEmpty = false) => {

    if (!amount && hideEmpty) {
        return '';
    }

    return amount ? (amount / 100).toFixed(2) : '0.00';
}


const getMargin = (pricing) => {

    if (!pricing.item_price || !pricing.item_cost) {
        return '--';
    }

    const profit = pricing.item_price - pricing.item_cost;

    return ((profit / pricing.item_price) * 100).toFixed(2) + '% ';

}


const getProfit = (pricing) => {

    if (!pricing.item_price || !pricing.item_cost) {
        return '--';
    }
    const calculatePrice = pricing.item_price - pricing.item_cost;
    return CurrencyFormatter.formatNumber(calculatePrice * 100, true);
}

const formatCapitalized = (input) => {
    const words = input.split('-');
    const capitalizedWords = words.map(word => word.charAt(0).toUpperCase() + word.slice(1));
    return capitalizedWords.join(' ');
}

const updatePostStatus = (status) => {
    const className = (status === 'publish') ? 'success' : ((status === 'draft') ? 'info' :  ((status === 'future') ? 'warning' : ''));
    status = (status === 'publish') ? 'published' : status;
    return jQuery('.badge').html(status).removeClass().addClass(`badge ${className}`);
};

const formatBillingSummary = (item) => {
    let otherInfo = item?.other_info;
    let paymentType = item?.payment_type;
    let paymentInfo = '';
    let quantity = item?.quantity;
    if(paymentType == 'adjustment') {
        quantity = item?.original_quantity;
    }
    if (otherInfo !== null && ['subscription', 'adjustment'].includes(paymentType)) {
        const itemPrice = formatNumber(item?.unit_price * quantity, true);
        const repeatInterval = otherInfo.repeat_interval;
        let occurrence = parseInt(otherInfo.times) || 0;
        const intervalOptions = getIntervalOptions();

        let interval = ' per ' + (intervalOptions[repeatInterval] ?? '');
        let time = occurrence > 1 ? intervalOptions[repeatInterval] + 's' : intervalOptions[repeatInterval];
        //the translations coming from file CanRenderProductPricingViews
        //_x('%1$s%2$s, billed %3$s for %4$s %5$s', 'Payment info format', 'fluent-cart')
        /* translators: %1$s - item price, %2$s - interval, %3$s - repeat interval, %4$s - occurrence, %5$s - time */
        paymentInfo = translate(
            '%1$s%2$s, billed %3$s for %4$s %5$s',
            itemPrice,
            interval,
            repeatInterval,
            occurrence,
            time
        );

        if (occurrence === 0 || occurrence === '') {
            //the translations coming from file CanRenderProductPricingViews
            //_x('%1$s%2$s, billed %3$s until cancel', 'Payment info format', 'fluent-cart')
            /* translators: %1$s - item price, %2$s - interval, %3$s - repeat interval */
            paymentInfo = translate(
                '%1$s%2$s, billed %3$s until cancel',
                itemPrice , interval , repeatInterval
            );
        }
    }
    return paymentInfo;
}

const getUniqueOrderItemCount = (order) => {
    const uniqueItems = new Set();
    order.order_items.forEach((item) => {
        if (Number(item.object_id) !== 0 || item.object_id === null) {
            uniqueItems.add(item.object_id);
        }
    });
    return uniqueItems.size;
}

// Calculate adjustment item original price with setup fee (adjustment is a combination of regular item and setup fee item - that means mixed item)
const getAdjustmentItemOriginalPrice = (item) => {
    const originalQuantity = parseInt(item?.original_quantity) || 1;
    const originalPrice = parseInt(item?.unit_price) || 0;
    const otherInfo = item?.other_info;

    let totalOriginalPrice = originalPrice * originalQuantity;
    let signupFee = 0;

    if (otherInfo.manage_setup_fee == 'yes') {
        signupFee = parseInt(otherInfo?.signup_fee) || 0;

        if (otherInfo.setup_fee_per_item == 'yes') {
            signupFee = signupFee * originalQuantity;
        }

        totalOriginalPrice += signupFee;
    }

    return totalOriginalPrice;
}

export {
    prepareProductList,
    formatMoney,
    formatCents,
    formatNumber,
    formatCentsWithoutCurrencySign,
    getMargin,
    getProfit,
    formatCapitalized,
    updatePostStatus,
    formatBillingSummary,
    getAdjustmentItemOriginalPrice,
    getUniqueOrderItemCount
};
