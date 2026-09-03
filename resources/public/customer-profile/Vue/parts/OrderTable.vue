<script setup>
import {dateTimeI18, translateNumber} from "../../translator/Translator";
import translate from "../../translator/Translator";
import {pluralizeTranslate} from "../../translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Badge from "../parts/Badge.vue";
import getStatusText from "../../utils/statusLabels";
import BundleProducts from "@/Bits/Components/BundleProducts.vue";

const props = defineProps({
    orders: {
        type: Array,
        required: true,
    },
    showTableHeader: {
        type: Boolean,
        default: false
    },
    itemsColumnWidth: {
        type: Number,
        default: 300
    },
    showInvoiceButton: {
        type: Boolean,
        default: true
    }
});

const filteredOrderItems = (order) => {
    // Find the subscription item
    const subscriptionItem = order.order_items.find(item => item.payment_type === 'subscription');

    // Flag to track if an 'adjustment' item exists
    const hasAdjustmentItem = order.order_items.some(item => item.payment_type === 'adjustment');

    return order.order_items.filter(item => {
        // Skip signup_fee and fee items
        if (item.payment_type === 'signup_fee' || item.payment_type === 'fee') {
            return false;
        }

        // Check for adjustment item and assign the subscription item's image to the product's featured media
        if (item.payment_type === 'adjustment' && subscriptionItem) {
            item.product.detail.featured_media = getImageUrl(subscriptionItem);
        }

        // If the item is 'subscription' item and 'adjustment' item also exists, then skip it
        if (hasAdjustmentItem && item.payment_type === 'subscription') {
            return false;
        }

        return true;
    });
};

const getImageUrl = (product) => {
    if (product.variants && product.variants.media) {
        return product?.variants?.media?.meta_value[0]['url'];
    }
    if (product.variants && !product.variants.media) {
        if (product.product.detail && product.product.detail.featured_media && product.product.detail.featured_media !== null && typeof product.product.detail.featured_media === 'object') {
            return product.product.detail.featured_media.url;
        }
    }
    return placeholderImage;
}

const placeholderImage = fluentcart_customer_profile_vars.placeholder_image;

const orderLinkTag = (uuid) => uuid ? 'router-link' : 'span';
const orderLinkProps = (uuid) => uuid ? { to: { name: 'view_order', params: { order_id: uuid } } } : {};



</script>

<template>
    <div class="fct-customer-dashboard-table">
      <!-- mobile view -->
      <div class="order-content-only-mobile" role="list">
        <!-- translators: %s is the order number -->
        <div v-if="orders.length > 0" class="order-content-only-mobile-item" v-for="order in orders" :key="order.id" role="listitem"
          :aria-label="$t('Order summary for order number %s', order.invoice_no)"
        >
          <div class="item-header">
            <h3 class="sr-only">{{ $t('Order Number') }}</h3>

            <component :is="orderLinkTag(order.uuid)" v-bind="orderLinkProps(order.uuid)" class="link text-sm" :aria-label="$t('View order') + ' #' + order.invoice_no">
              <span class="block truncate max-w-[100px]">#{{ order.invoice_no }}</span>
            </component>
            <span class="text-system-light text-sm">{{ dateTimeI18(order.created_at, 'MMM DD, YYYY') }}</span>
          </div><!-- item-header -->

          <div class="item-body">
            <div class="fct-customer-orders-items fct-customer-orders-items-2">
              <div v-if="filteredOrderItems(order).length === 1">
                <component :is="orderLinkTag(order.uuid)" v-bind="orderLinkProps(order.uuid)" class="fct-customer-orders-items-title">
                  {{ filteredOrderItems(order)[0]?.post_title }}
                  <span
                      v-if="filteredOrderItems(order)[0]?.title && filteredOrderItems(order)[0]?.title !== filteredOrderItems(order)[0]?.post_title"
                      class="text-gray-800 text-sm"
                  >
                       &#8211; {{ filteredOrderItems(order)[0]?.variation_display_title || filteredOrderItems(order)[0]?.title }}
                  </span>
                </component>

                <span class="fct-customer-orders-items-sub-title !max-w-full" v-if="order.renewals_count > 0">
                  {{ pluralizeTranslate('%s Renewal', '%s Renewals', order.renewals_count) }}
                </span>
              </div>

              <component :is="orderLinkTag(order.uuid)" v-bind="orderLinkProps(order.uuid)" :aria-label="$t('View order') + ' #' + order.invoice_no" v-else>
                {{
                  /* translators: %s is the number of items in the order */
                  translate("%s items", filteredOrderItems(order).length)
                }}
              </component>
              <el-popover
                  placement="top"
                  :width="360"
                  trigger="click"
                  v-if="filteredOrderItems(order).length > 1"
              >
                <div class="fct-popover-content">
                  <div class="fct-product-orders-items">
                    <p v-for="item in filteredOrderItems(order)" :key="item.id">
                      <component :is="orderLinkTag(order.uuid)" v-bind="orderLinkProps(order.uuid)" class="title">
                        {{ item.post_title }}
                      </component>
                      <span class="variation-title">
                        <b>{{ translateNumber(item.quantity) }} </b> x {{ item.variation_display_title || item.title }}
                      </span>
                    </p>

                    <p v-if="filteredOrderItems(order).length === 0">
                      {{ translate('No products found!') }}
                    </p>
                  </div>
                </div>

                <template #reference>
                  <!-- translators: %s is the order number -->
                  <div class="fct-action-link-btn" :aria-expanded="false"
                  :aria-label="$t('Show items for order %s', [order.invoice_no])"
                  role="button">
                    <DynamicIcon name="ChevronDown"/>
                  </div>
                </template>
              </el-popover>
            </div>
          </div><!-- item-body -->

          <div class="item-footer">
            <div class="item-footer-content">
              <Badge :type="order.status" size="small">
                {{ order.status }}
              </Badge>
              <span v-html="formatNumber(order.total_amount, true, false, order.currency)"></span>
            </div>
          </div><!-- item-footer -->

        </div>

        <div v-else class="text-center p-5" role="note">
          {{translate('Your purchases will be shown here!')}}
        </div>
      </div><!-- order-content-only-mobile -->


      <!-- desktop view -->
        <el-table class="order-content-only-desktop" :empty-text="$t('Your purchases will be shown here!')" :data="orders" :show-header="showTableHeader" role="table"
      :aria-label="$t('Customer order history')">
            <el-table-column label="#" :width="120">
                <template #default="scope">
                  <div class="invoice-id-date">
                    <component :is="orderLinkTag(scope.row.uuid)" v-bind="orderLinkProps(scope.row.uuid)" class="link text-sm">
                      <span class="block truncate max-w-[100px]">#{{ scope.row.invoice_no }}</span>
                    </component>
                    <span class="text">
                        {{ dateTimeI18(scope.row.created_at, 'MMM DD, YYYY') }}
                    </span>
                  </div>
                </template>
            </el-table-column>

            <el-table-column :width="itemsColumnWidth" :label="translate('Items')" :aria-label="$t('Order items')">
                <template #default="scope">
                    <div class="fct-customer-orders-items" :class="filteredOrderItems(scope.row).length === 1 ? 'grid' : ''">
                        <template v-if="filteredOrderItems(scope.row).length === 1">
                            <component :is="orderLinkTag(scope.row.uuid)" v-bind="orderLinkProps(scope.row.uuid)" class="fct-customer-orders-items-title" :aria-label="$t('View single item for this order')">
                                {{ filteredOrderItems(scope.row)[0]?.post_title }}
                            </component>

                            <!-- Renewal and variant name -->
                            <div class="inline-flex items-center gap-2">
                                <span
                                    v-if="filteredOrderItems(scope.row)[0]?.title && filteredOrderItems(scope.row)[0]?.title !== filteredOrderItems(scope.row)[0]?.post_title"
                                    class="fct-customer-orders-items-sub-title leading-[1]"
                                >
                                    &#8211; {{ filteredOrderItems(scope.row)[0]?.variation_display_title || filteredOrderItems(scope.row)[0]?.title }}
                                </span>

                                <Badge v-if="scope.row.renewals_count > 0" size="small" class="fct-renewal-badge">
                                    <DynamicIcon name="Refresh"/>
                                    {{ pluralizeTranslate('%s Renewal', '%s Renewals', translateNumber(scope.row.renewals_count)) }}
                                </Badge>
                            </div>
                        </template>

                        <component :is="orderLinkTag(scope.row.uuid)" v-bind="orderLinkProps(scope.row.uuid)"
                            :aria-label="
                            /* translators: %s is the number of items in the order */
                            translate('%s items in this order') + filteredOrderItems(scope.row).length
                            "
                            v-else
                        >
                          <!-- translators: %s is the number of items -->
                            {{ translate("%s items", filteredOrderItems(scope.row).length) }}
                        </component>
                        <el-popover
                            placement="top"
                            :width="360"
                            trigger="click"
                            v-if="filteredOrderItems(scope.row).length > 1"
                        >
                            <div class="fct-popover-content">
                                <div class="fct-product-orders-items">
                                    <p v-for="item in filteredOrderItems(scope.row)" :key="item.id">
                                        <component :is="orderLinkTag(scope.row.uuid)" v-bind="orderLinkProps(scope.row.uuid)" class="title" :aria-label="$t('View item details:') + item.post_title">
                                            {{ item.post_title }}
                                        </component>
                                        <span class="variation-title">
                                            <b>{{ translateNumber(item.quantity) }} </b> x {{ item.variation_display_title || item.title }}
                                        </span>
                                    </p>

                                    <p v-if="filteredOrderItems(scope.row).length === 0">
                                        {{ translate('No products found!') }}
                                    </p>
                                </div>
                            </div>

                            <template #reference>
                                <div class="fct-action-link-btn" role="button"
                                    :aria-expanded="false"
                                    :aria-label="$t('Show all items for this order')">
                                    <DynamicIcon name="ChevronDown"/>
                                </div>
                            </template>
                        </el-popover>
                    </div>

                  <template v-if="scope.row.order_items">
                    <BundleProducts v-for="item in scope.row.order_items" :key="item.id" :product="item"/>
                  </template>
                </template>
            </el-table-column>

            <el-table-column :label="translate('Status')" width="120"  :aria-label="$t('Order status')">
                <template #default="scope">
                    <Badge :type="scope.row.status" size="small">
                        {{ getStatusText(scope.row.status) }}
                    </Badge>
                </template>
            </el-table-column>

            <el-table-column :label="translate('Amount')" width="90" :aria-label="$t('Total amount')">
                <template #default="scope">
                    <span class="text" v-html="formatNumber(scope.row.total_amount, true, false, scope.row.currency)"></span>
                </template>
            </el-table-column>
        </el-table>
    </div>
</template>
