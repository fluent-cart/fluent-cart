<template>
    <div 
        class="fct-customer-dashboard-single-order-wrap"
        role="main"
        :aria-labelledby="orderTitleId"
        :aria-busy="loading"
    >
        <div 
            class="fct-customer-dashboard-breadcrumb mb-4"
            role="navigation"
            :aria-label="$t('Breadcrumb navigation')"
        >
            <el-breadcrumb :separator-icon="ArrowRight">
                <el-breadcrumb-item :to="{ name: 'purchase-history' }">
                    {{ $t('Purchase History') }}
                </el-breadcrumb-item>
                <el-breadcrumb-item aria-current="page">#{{ order?.invoice_no }}</el-breadcrumb-item>
            </el-breadcrumb>
        </div>

        <template v-if="order">
            <h1 :id="orderTitleId" class="sr-only">{{ $t('Order Details') }} <span v-if="order.invoice_no">#{{ order.invoice_no }}</span></h1>

            <el-alert
                v-if="order.custom_payment_link && (order.total_amount - order.total_paid - order.total_refund) > 0"
                class="mb-4"
                :title="$t('This order has some due amount. Please complete the payment.')"
                type="error"
                :show-icon="true"
                :closable="false"
            >
                <el-button
                    class="el-button--x-small"
                    tag="a"
                    :href="order.custom_payment_link"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {{ $t('Pay Now') }}
                </el-button>
            </el-alert>

            <article class="fct-single-order-box" role="region" aria-labelledby="order-summary-title">
                <div v-if="sectionParts.before_summary" v-html="sectionParts.before_summary"></div>
                <div class="fct-single-order-body">
                    <section class="fct-customer-dashboard-order-items" aria-labelledby="order-summary-title">
                        <div class="fct-order-summary-wrap">
                            <header class="fct-order-summary-header">
                                <h2 id="order-summary-title" class="fct-order-summary-header-title">
                                    {{ $t('Summary') }}
                                </h2>

                                <div v-if="order" :aria-label="$t('Order status information')" class="fct-order-status-badges gap-1 flex">
                                    <Badge
                                        :type="order.payment_status"
                                    >
                                      {{ getStatusText(order.payment_status) }}
                                    </Badge>
                                  <template v-if="order.fulfillment_type == 'physical'">
                                    <Badge
                                        :type="order.status"
                                    >
                                      {{ getStatusText(order.status) }}
                                    </Badge>
                                    <Badge
                                        :type="order.shipping_status"
                                    >
                                      {{ getStatusText(order.shipping_status) }}
                                    </Badge>
                                  </template>
                                  <Badge
                                      v-if="order.is_b2b_order"
                                      type="blue"
                                      :text="$t('B2B')"
                                  />
                                </div>
                            </header>

                            <div class="fct-order-summary-body">
                                <ul class="fct-order-summary-items" role="list">
                                    <li v-for="(filteredOrderItem, i) in productItems" :key="i"
                                     class="fct-order-summary-item gap-0" role="listitem">
                                        <div class="fct-order-item-row">
                                            <div class="fct-product-info-card">
                                                <div class="fct-product-info-card-inner">
                                                    <div class="fct-media">
                                                        <a
                                                            target="_blank"
                                                            :href="filteredOrderItem.url"
                                                            rel="noopener noreferrer"
                                                           :aria-label="$t('View product') + ' ' + filteredOrderItem.title"
                                                        >
                                                            <img
                                                                :src="getImage(filteredOrderItem)"
                                                                :alt="$t('Product image for') + ' ' + filteredOrderItem.title"
                                                                loading="lazy"
                                                            />
                                                        </a>
                                                    </div>
                                                    <div class="product-info-content">
                                                        <div class="title">
                                                            <a
                                                                target="_blank"
                                                                :href="filteredOrderItem.url"
                                                                rel="noopener noreferrer"
                                                               :aria-label="$t('View product') + ' ' + filteredOrderItem.title"
                                                            >
                                                                <span class="text-gray-600"
                                                                    v-if="filteredOrderItem.quantity > 1">
                                                                    {{
                                                                        translateNumber(filteredOrderItem.quantity)
                                                                    }} x</span>
                                                                {{ filteredOrderItem.post_title }}
                                                            </a>
                                                        </div>
                                                        <div
                                                            v-if="(filteredOrderItem.variation_display_title || filteredOrderItem.title) && filteredOrderItem.title !== filteredOrderItem.post_title"
                                                            class="variation-title"
                                                        >
                                                            {{ filteredOrderItem.variation_display_title || filteredOrderItem.title }}
                                                        </div>

                                                        <div
                                                            v-if="shouldShowUnitPriceRoundingTooltip(filteredOrderItem)"
                                                            class="fct-unit-price-breakdown"
                                                        >
                                                            <span>{{ formatOrderAmount(filteredOrderItem.unit_price) }} × {{ translateNumber(filteredOrderItem.quantity) }}</span>
                                                            <el-tooltip
                                                                effect="dark"
                                                                :content="$t('Unit price is rounded for display. The line total is calculated at full precision.')"
                                                                placement="top"
                                                                popper-class="fct-tooltip"
                                                            >
                                                                <el-icon class="fct-unit-price-rounding-icon"><InfoFilled /></el-icon>
                                                            </el-tooltip>
                                                        </div>

                                                      <BundleProducts
                                                          class="!mt-2.5"
                                                          v-if="filteredOrderItem?.bundle_items?.length"
                                                          :product="filteredOrderItem"
                                                      />

                                                      <a
                                                          v-if="filteredOrderItem.url && filteredOrderItem.can_review"
                                                          class="fct-order-item-review-btn"
                                                          :href="reviewUrl(filteredOrderItem)"
                                                          target="_blank"
                                                          rel="noopener noreferrer"
                                                          :aria-label="$t('Write a review for') + ' ' + filteredOrderItem.post_title"
                                                      >
                                                          {{ $t('Write a Review') }} <span aria-hidden="true">&#9997;&#xFE0E;</span>
                                                      </a>

                                                      <UpgradePlan
                                                          v-if="filteredOrderItem.has_upgrade_paths"
                                                          class="mt-2"
                                                          button-type="primary"
                                                          :button-text="$t('Upgrade Plan')"
                                                          :variation_id="filteredOrderItem.variation_id"
                                                          :order_hash="order.uuid"
                                                      />
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="fct-order-total text-right" :aria-label="$t('Subtotal for this item')">
                                                <template v-if="filteredOrderItem.coupon_discount > 0">
                                                    <div class="flex justify-end items-center gap-1">
                                                        <span class="text-xs font-normal leading-tight opacity-60 line-through">{{ formatOrderAmount(filteredOrderItem.subtotal) }}</span>
                                                        <span>{{ formatOrderAmount(filteredOrderItem.line_total) }}</span>
                                                    </div>
                                                </template>
                                                <template v-else>
                                                    {{ formatOrderAmount(filteredOrderItem.line_total) }}
                                                </template>
                                              
                                            </div>
                                        </div>
                                        <div
                                            v-if="getItemTaxRates(filteredOrderItem).length && order.tax_summary?.displayMode !== 'simplified'"
                                            class="fct-order-item-tax-rates"
                                        >
                                            <div
                                                v-for="(rate, idx) in getItemTaxRates(filteredOrderItem)"
                                                :key="'tax-rate-' + idx"
                                                class="fct-order-item-tax-rate"
                                                :class="filteredOrderItem.line_meta && filteredOrderItem.line_meta.tax_config && filteredOrderItem.line_meta.tax_config.inclusive ? 'is-inclusive' : 'is-exclusive'"
                                            >
                                                <span class="fct-tax-badge">{{ formatTaxRateBadge(rate) }}</span>
                                                <span class="fct-tax-amount" :class="{'fct-tax-amount--reversed': order.is_reverse_charge_tax_order && (!filteredOrderItem.line_meta?.tax_config?.inclusive || order.reverse_charge_price_mode === 'dynamic')}">
                                                    <template v-if="filteredOrderItem.line_meta && filteredOrderItem.line_meta.tax_config && filteredOrderItem.line_meta.tax_config.inclusive">{{ $t('incl.') }} </template>{{ formatOrderAmount(rate.tax_amount) }}
                                                </span>
                                            </div>
                                        </div>
                                        <div
                                            class="product-subscription-info flex flex-col gap-1 mt-1 pl-[52px]"
                                            v-if="filteredOrderItem.meta_lines && filteredOrderItem.meta_lines.length > 0"
                                        >
                                            <span v-for="(line, lineIndex) in filteredOrderItem.meta_lines"
                                                :key="'meta-line-' + i + '-' + lineIndex"
                                                class="leading-none text-xs flex justify-between"
                                                :aria-label="line.label + ': ' + line.value"

                                            >
                                              <span>
                                                {{line.label}} :
                                              </span>

                                              <span>{{ line.value }}</span>
                                            </span>
                                        </div>
                                        <div
                                            v-if="(getSubscriptionSetupFeeTaxRates(filteredOrderItem).length || (filteredOrderItem.other_info && filteredOrderItem.other_info.signup_fee_tax > 0)) && order.tax_summary?.displayMode !== 'simplified'"
                                            class="fct-order-item-tax-rates"
                                        >
                                            <template v-if="getSubscriptionSetupFeeTaxRates(filteredOrderItem).length">
                                                <div
                                                    v-for="(rate, idx) in getSubscriptionSetupFeeTaxRates(filteredOrderItem)"
                                                    :key="'setup-tax-rate-' + idx"
                                                    class="fct-order-item-tax-rate"
                                                    :class="isSubscriptionSetupFeeInclusive(filteredOrderItem) ? 'is-inclusive' : 'is-exclusive'"
                                                >
                                                    <span class="fct-tax-badge">{{ $t('Setup fee') }}: {{ formatTaxRateBadge(rate) }}</span>
                                                    <span class="fct-tax-amount" :class="{'fct-tax-amount--reversed': order.is_reverse_charge_tax_order && (!isSubscriptionSetupFeeInclusive(filteredOrderItem) || order.reverse_charge_price_mode === 'dynamic')}">
                                                        <template v-if="isSubscriptionSetupFeeInclusive(filteredOrderItem)">{{ $t('incl.') }} </template>{{ formatOrderAmount(rate.tax_amount) }}
                                                    </span>
                                                </div>
                                            </template>
                                            <template v-else-if="filteredOrderItem.other_info && filteredOrderItem.other_info.signup_fee_tax > 0">
                                                <div class="fct-order-item-tax-rate is-exclusive">
                                                    <span class="fct-tax-badge">{{ $t('Setup fee tax') }}</span>
                                                    <span class="fct-tax-amount" :class="{'fct-tax-amount--reversed': order.is_reverse_charge_tax_order && (!isSubscriptionSetupFeeInclusive(filteredOrderItem) || order.reverse_charge_price_mode === 'dynamic')}">{{ formatOrderAmount(filteredOrderItem.other_info.signup_fee_tax) }}</span>
                                                </div>
                                            </template>
                                        </div>
                                    </li>
                                </ul>
                                <div
                                    v-if="(order.manual_discount_total + order.coupon_discount_total) > 0 || order.shipping_total > 0 || order.fee_total > 0 || order.tax_total > 0 || order.is_reverse_charge_tax_order || (order.tax_summary && order.tax_summary.shouldRender)"
                                    class="fct-order-summary-order-calculation">
                                    <div v-if="(order.manual_discount_total + order.coupon_discount_total) || order.subtotal > 0"
                                         class="tr">
                                        {{ $t('Subtotal') }} 
                                        <span>{{ formatOrderAmount(order.subtotal) }}</span>
                                    </div>
                                    <div v-if="order.shipping_total > 0" class="tr">
                                        <span>
                                            {{ $t('Shipping') }}
                                            <span v-if="order.shipping_method_title"
                                                  class="fct-shipping-method-name">{{ order.shipping_method_title }}</span>
                                        </span>
                                        <span>{{
                                            formatOrderAmount(order.shipping_total - (order.tax_summary && order.tax_summary.rcShippingAdjustment ? order.tax_summary.rcShippingAdjustment : 0))
                                        }}</span>
                                    </div>

                                    <template v-if="order.fee_total > 0">
                                        <div v-for="feeItem in feeItems" :key="'fee-' + feeItem.id" class="tr">
                                            {{ feeItem.title }} <span>{{ formatOrderAmount(feeItem.subtotal) }}</span>
                                        </div>
                                    </template>

                                    <div v-if="order.is_reverse_charge_tax_order && order.tax_summary && order.tax_summary.showRcShippingRow && order.tax_summary.reversedShippingTax > 0 && !(order.tax_summary.foldedRateLines && order.tax_summary.foldedRateLines.length) && order.tax_summary.displayMode !== 'simplified'" class="tr">
                                        {{ $t('Added on shipping') }}
                                        <span class="fct-tax-amount fct-tax-amount--reversed">{{ formatOrderAmount(order.tax_summary.reversedShippingTax) }}</span>
                                    </div>
                                    <template v-if="order.tax_summary && order.tax_summary.displayMode === 'simplified' && order.tax_summary.simpleLine">
                                        <div class="tr fct-customer-tax-simple-line">
                                            <span style="display:flex;align-items:center;gap:6px;">
                                                <span>{{ order.tax_summary.simpleLine.label }}</span>
                                                <el-popover v-if="order.tax_summary.simpleLine.hasDetails" placement="bottom-start" :width="420" trigger="click" :teleported="true" :popper-style="{ padding: '0', maxWidth: '92vw' }">
                                                    <template #reference>
                                                        <a href="javascript:void(0)" class="fct-customer-tax-see-details">{{ $t('See details') }} &#9662;</a>
                                                    </template>
                                                    <CustomerTaxBreakdownBox :order="order" />
                                                </el-popover>
                                            </span>
                                            <span>{{ order.tax_summary.simpleLine.value }}</span>
                                        </div>
                                    </template>
                                    <template v-else-if="order.tax_summary && order.tax_summary.foldedRateLines && order.tax_summary.foldedRateLines.length">
                                        <CustomerTaxBreakdownBox :order="order" />
                                    </template>
                                    <div v-else-if="order.is_reverse_charge_tax_order" class="tr">
                                        {{ $t('Tax') }}
                                        <span v-if="order.tax_summary && order.tax_summary.reversedTaxTotal > 0">
                                            <!-- translators: %1$s: formatted reversed tax amount -->
                                            {{ $t('Tax reversed: %1$s', formatOrderAmount(order.tax_summary.reversedTaxTotal)) }}
                                        </span>
                                        <span v-else>{{ $t('Charge reversed') }}</span>
                                    </div>
                                    <template v-else-if="order.tax_summary && order.tax_summary.shouldRender">
                                            <template v-if="customerTaxRateLines.length && customerTaxShouldShowBreakdown">
                                                <div v-for="taxLine in customerTaxRateLines"
                                                     :key="'tax-rate-line-' + taxLine.rate_id + '-' + taxLine.label"
                                                     :class="['tr', taxLine.inclusive ? 'fct-customer-tax-row--muted' : '']">
                                                    {{ taxLine.label }}
                                                    <span>{{ formatOrderAmount(taxLine.order_tax) }}</span>
                                                </div>
                                            </template>
                                            <div v-if="!customerTaxRateLines.length && order.tax_summary.inclusiveTax > 0 && customerTaxShouldShowBreakdown" class="tr fct-customer-tax-row--muted">
                                                {{ $t('Included in item prices') }}
                                                <span>{{ formatOrderAmount(order.tax_summary.inclusiveTax) }}</span>
                                            </div>
                                            <div v-if="!customerTaxRateLines.length && order.tax_summary.exclusiveTax > 0 && customerTaxShouldShowBreakdown" class="tr">
                                                {{ $t('Added on products') }}
                                                <span>{{ formatOrderAmount(order.tax_summary.exclusiveTax) }}</span>
                                            </div>
                                            <template v-if="order.tax_summary.feeTaxLines && order.tax_summary.feeTaxLines.length && customerTaxShouldShowBreakdown">
                                                <template v-for="(feeLine, idx) in order.tax_summary.feeTaxLines" :key="idx">
                                                    <div v-if="feeLine.tax_amount > 0"
                                                         :class="['tr', feeLine.inclusive ? 'fct-customer-tax-row--muted' : '']">
                                                        {{ feeLine.inclusive ? $t('Included in %1$s', feeLine.label) : $t('Added on %1$s', feeLine.label) }}
                                                        <span>{{ formatOrderAmount(feeLine.tax_amount) }}</span>
                                                    </div>
                                                </template>
                                            </template>
                                            <template v-if="order.tax_summary.shippingTax > 0 && customerTaxShouldShowBreakdown">
                                                <template v-if="order.tax_summary.shippingTaxLines && order.tax_summary.shippingTaxLines.length">
                                                    <div v-for="shLine in order.tax_summary.shippingTaxLines"
                                                         :key="shLine.label"
                                                         :class="['tr', order.tax_summary.isShippingInclusive ? 'fct-customer-tax-row--muted' : '']">
                                                        {{ shLine.label }}
                                                        <span>{{ formatOrderAmount(shLine.shipping_tax) }}</span>
                                                    </div>
                                                </template>
                                                <div v-else
                                                     :class="['tr', order.tax_summary.isShippingInclusive ? 'fct-customer-tax-row--muted' : '']">
                                                    {{ order.tax_summary.isShippingInclusive ? $t('Included in shipping prices') : $t('Added on shipping') }}
                                                    <span>{{ formatOrderAmount(order.tax_summary.shippingTax) }}</span>
                                                </div>
                                            </template>
                                            <div v-if="order.tax_summary.payableTax > 0" class="tr">
                                                {{ $t('Total payable tax') }}
                                                <span>{{ formatOrderAmount(order.tax_summary.payableTax) }}</span>
                                            </div>
                                            <div v-if="order.tax_summary.inclusiveTax > 0 || order.tax_summary.inclusiveFeeTax > 0" class="tr fct-customer-tax-row--muted">
                                                {{ $t('Total tax in this order') }}
                                                <span>{{ formatOrderAmount(order.tax_summary.totalOrderTax) }}</span>
                                            </div>
                                    </template>
                                    <div v-else-if="order.tax_total > 0" class="tr">
                                        {{ $t('Tax') }} {{ parseInt(order.tax_behavior) == 2 ? $t('(Included)') : $t('(Excluded)') }}
                                        <span>{{ formatOrderAmount(order.tax_total) }}</span>
                                    </div>

                                    <div v-if="order.shipping_tax > 0 && !(order.tax_summary && order.tax_summary.shouldRender)" class="tr">
                                      {{ $t('Shipping Tax') }} {{order.tax_behavior == 2 ? $t('(Included)') : $t('(Excluded)')}}
                                      <span>
                                        {{ formatOrderAmount(order.shipping_tax) }}
                                      </span>
                                    </div>

                                    <div v-if="order.coupon_discount_total > 0"
                                         class="tr">
                                        {{ $t('Coupon Discount') }}
                                        <span>-{{
                                                formatOrderAmount(order.coupon_discount_total)
                                            }}</span>
                                    </div>

                                    <!-- When the prorate credit IS the whole manual discount, label the
                                         row directly instead of "Discount" + a redundant breakdown row. -->
                                    <div v-if="order.manual_discount_total > 0"
                                         class="tr">
                                        {{ order.prorate_credit > 0 && (order.manual_discount_total - order.prorate_credit) <= 0 ? $t('Prorate Credit') : $t('Discount') }}
                                        <span>-{{
                                                formatOrderAmount(order.manual_discount_total)
                                            }}</span>
                                    </div>

                                    <!-- Breakdown of the Discount above. manual_discount_total already
                                         includes the prorate credit, so these are detail rows that sum to
                                         it — NOT additional deductions (would double-subtract otherwise). -->
                                    <div v-if="order.prorate_credit > 0 && (order.manual_discount_total - order.prorate_credit) > 0"
                                         class="tr fct-customer-tax-row--muted fct-customer-tax-row--indented">
                                        {{ $t('Upgrade Discount') }}
                                        <span>{{
                                                formatOrderAmount(order.manual_discount_total - order.prorate_credit)
                                            }}</span>
                                    </div>

                                    <div v-if="order.prorate_credit > 0 && (order.manual_discount_total - order.prorate_credit) > 0"
                                         class="tr fct-customer-tax-row--muted fct-customer-tax-row--indented">
                                        {{ $t('Prorate Credit') }}
                                        <span>{{
                                                formatOrderAmount(order.prorate_credit)
                                            }}</span>
                                    </div>
                                </div>
                            </div>
                            <footer class="fct-order-summary-footer">
                                <div class="fct-order-summary-footer-inner">
                                    <div class="tr" :aria-level="$t('Total amount for the order')">
                                        {{ $t('Total') }} <span>{{ formatOrderAmount(order.total_amount - (order.tax_summary && order.tax_summary.rcTotalAdjustment ? order.tax_summary.rcTotalAdjustment : 0)) }}</span>
                                    </div>
                                </div>
                            </footer>
                        </div>
                    </section>
                </div>
                <div v-if="sectionParts.after_summary" v-html="sectionParts.after_summary"></div>
            </article>

            <article v-if="order.subscriptions && order.subscriptions.length && order.payment_status !=='pending'" class="fct-single-order-box" role="region" aria-labelledby="subscription-title">
                <header class="fct-single-order-header">
                    <h2 id="subscription-title" class="title">{{ $t('Subscription Plan') }}</h2>
                </header>
                <SubscriptionTable :hideHeader="true" :subscriptions="order.subscriptions"/>

                <div v-if="sectionParts.after_subscriptions" v-html="sectionParts.after_subscriptions"></div>
            </article>


            <article v-if="order.downloads && Object.entries(order.downloads).length && order.payment_status !=='pending'" class="fct-single-order-box" role="region" aria-labelledby="downloads-title">
                <header class="fct-single-order-header">
                    <h2 id="downloads-title" class="title">
                        {{ pluralizeTranslate('Download', 'Downloads', Object.entries(order.downloads).length) }}
                    </h2>
                </header>
                <DownloadsTable :show-table-header="false" :downloads="order.downloads"/>

                <div v-if="sectionParts.after_downloads" v-html="sectionParts.after_downloads"></div>
            </article>

            <article v-if="order.licenses && order.licenses.length" class="fct-single-order-box" role="region" aria-labelledby="licenses-title">
                <header class="fct-single-order-header">
                    <h2 id="licenses-title" class="title">
                        {{ pluralizeTranslate('License', 'Licenses', order.licenses.length) }}
                    </h2>
                </header>
                <LicenseTable :licenses="order.licenses" :is_simple="true" :showTableHeader="false"/>

                <div v-if="sectionParts.after_licenses" v-html="sectionParts.after_licenses"></div>

            </article>

            <article v-if="visibleTransactions.length" class="fct-single-order-box" role="region" aria-labelledby="transactions-title">
                <div v-if="sectionParts.before_transactions" v-html="sectionParts.before_transactions"></div>

                <header class="fct-single-order-header">
                    <h2 id="transactions-title" class="title">{{ $t('Related Transactions') }}</h2>
                </header>
                <div class="fct-customer-dashboard-table">
                    <TransactionsTable :transactions="visibleTransactions" :show-table-header="true" @billing-address-updated="orderBillingAddressUpdated"/>
                </div>

                <div v-if="sectionParts.after_transactions" v-html="sectionParts.after_transactions"></div>

            </article>

            <article class="fct-single-order-box pb-5 lg:pb-0" v-if="order.billing_address_text || order.shipping_address_text" role="region" aria-labelledby="addresses-title">
                <h2 id="addresses-title" class="sr-only">{{ $t('Order addresses') }}</h2>

                <el-row :gutter="30">
                    <el-col :md="12" :sm="24">
                        <section class="fct-customer-dashboard-address mb-5 lg:mb-0" aria-labelledby="billing-title">
                          <h3 id="billing-title" class="title">{{ $t('Billing Address') }}</h3>
                          <div class="text" v-html="order.billing_address_text"></div>
                        </section>
                    </el-col>
                    <el-col :md="12" :sm="24" v-if="order.fulfillment_type == 'physical'">
                        <section class="fct-customer-dashboard-address" aria-labelledby="shipping-title">
                          <h3 id="shipping-title" class="title">{{ $t('Shipping Address') }}</h3>
                          <div class="text" v-html="order.shipping_address_text"></div>
                        </section>
                    </el-col>
                </el-row>
            </article>

            <div v-if="sectionParts.end_of_order" v-html="sectionParts.end_of_order"></div>

        </template>
        <template v-else-if="loading">
            <div aria-live="polite">
                <OrderTableLoader class="mb-4" :rows-range="[1, 2, 3]"/>
                <OrderTableLoader class="mb-4" :rows-range="[1, 2]"/>
                <OrderTableLoader :rows-range="[1, 2, 3]"/>
            </div>
        </template>
    </div>
</template>

<script type="text/babel">
import {ArrowRight, InfoFilled} from '@element-plus/icons-vue';
import Badge from "./parts/Badge.vue";
import SubscriptionTable from "./parts/SubscriptionTable.vue";
import DownloadsTable from "./parts/DownloadsTable.vue";
import LicenseTable from "./parts/LicenseTable.vue";
import TransactionsTable from "./parts/TransactionTable.vue";
import OrderTableLoader from "./parts/OrderTableLoader.vue";
import translate, {pluralizeTranslate, translateNumber} from '../translator/Translator'
import statusLabel from "../utils/statusLabels";
import { formatOrderItems } from "@/Bits/common";
import BundleProducts from "@/Bits/Components/BundleProducts.vue";
import CustomerTaxBreakdownBox from "./parts/CustomerTaxBreakdownBox.vue";
import UpgradePlan from "./subcriptions/UpdatePaymentInfos/UpgradePlan.vue";

export default {
    name: 'SingleOrderDetails',
    components: {
        OrderTableLoader,
        TransactionsTable,
        LicenseTable,
        SubscriptionTable,
        Badge,
        DownloadsTable,
        BundleProducts,
        InfoFilled,
        CustomerTaxBreakdownBox,
        UpgradePlan
    },
    props: {
        order_id: {
            type: [String, Number],
            required: true
        }
    },
    data() {
        return {
            orderTitleId: 'order-title',
            order: null,
            loading: true,
            placeholderImage: window.fluentcart_customer_profile_vars.placeholder_image,
            sectionParts: {}
        };
    },
    computed: {
        ArrowRight() {
            return ArrowRight;
        },
        productItems() {
            if (!this.order || !this.order.order_items) return [];
            return this.order.order_items.filter(item => !['fee', 'signup_fee'].includes(item.payment_type));
        },
        feeItems() {
            if (!this.order || !this.order.order_items) return [];
            return this.order.order_items.filter(item => item.payment_type === 'fee');
        },
        visibleTransactions() {
            return (this.order?.transactions || []).filter(
                t => !(t.order_type === 'renewal' && Number(t.total) === 0)
            );
        },
        customerTaxRateLines() {
            if (!this.order || this.order.is_reverse_charge_tax_order) {
                return [];
            }
            const summary = this.order.tax_summary || {};
            return this.order.display_tax_lines || summary.taxRateLines || [];
        },
        customerTaxShouldShowBreakdown() {
            const s = this.order && this.order.tax_summary;
            if (!s) return false;
            if (this.customerTaxRateLines.length || (s.shippingTaxLines || []).length) {
                return true;
            }
            let count = 0;
            if (this.customerTaxRateLines.length) {
                count += this.customerTaxRateLines.length;
            } else {
                if (s.inclusiveTax > 0) count++;
                if (s.exclusiveTax > 0) count++;
            }
            count += (s.feeTaxLines || []).filter(function(f) { return f.tax_amount > 0; }).length;
            if (s.shippingTax > 0) count++;
            if (count >= 2) return true;
            if (count === 0) return false;
            return !(s.payableTax > 0 || s.inclusiveTax > 0 || s.inclusiveFeeTax > 0);
        },
    },
    watch: {
        order_id() {
            this.fetchOrder();
        }
    },
    mounted() {
        this.fetchOrder();
    },
    methods: {
        formatOrderItems,
        orderBillingAddressUpdated(address) {
            this.order.billing_address_text = address;
        },
        reviewUrl(item) {
            if (!item.url) {
                return '';
            }
            // Anchor to the reviews section rendered on the product page
            return item.url + '#fct-product-reviews';
        },
        getImage(item) {
            if(item.variant_image) {
                return item.variant_image;
            } else if(item.image) {
                return item.image;
            } else {
                return this.placeholderImage;
            }
        },
        fetchOrder() {
            this.$get("customer-profile/orders/" + this.order_id)
                .then((response) => {
                    this.order = response.order;
                    this.order.order_items = this.formatOrderItems(response.order.order_items);
                    if(response.section_parts) {
                        this.sectionParts = response.section_parts;
                    }
                })
                .catch((error) => {
                    if (error?.data?.parent_order && error?.data?.parent_order?.uuid) {
                        this.$router.push({
                            name: 'view_order',
                            params: {
                                order_id: error.data.parent_order.uuid
                            }
                        });
                        return;
                    }

                    this.handleError(error);
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        pluralizeTranslate,
        getStatusText: statusLabel,
        translateNumber,
        getItemTaxRates(item) {
            var rates = item.line_meta && item.line_meta.tax_config
                ? item.line_meta.tax_config.rates
                : null;
            if (!Array.isArray(rates)) return [];
            return rates.filter(r => r.tax_amount > 0);
        },
        getSubscriptionSetupFeeTaxRates(item) {
            if (!this.order || !this.order.order_items) return [];
            var sibling = this.order.order_items.find(
                i => i.payment_type === 'signup_fee' && i.object_id === item.variation_id
            );
            if (!sibling) return [];
            var rates = (sibling.line_meta && sibling.line_meta.tax_config && Array.isArray(sibling.line_meta.tax_config.rates))
                ? sibling.line_meta.tax_config.rates
                : (sibling.line_meta && Array.isArray(sibling.line_meta.rates) ? sibling.line_meta.rates : null);
            if (!Array.isArray(rates)) return [];
            return rates.filter(function(r) { return r.tax_amount > 0; });
        },
        isSubscriptionSetupFeeInclusive(item) {
            if (!this.order || !this.order.order_items) return false;
            var sibling = this.order.order_items.find(
                i => i.payment_type === 'signup_fee' && i.object_id === item.variation_id
            );
            if (!sibling || !sibling.line_meta) return false;
            var meta = (sibling.line_meta.tax_config && typeof sibling.line_meta.tax_config === 'object')
                ? sibling.line_meta.tax_config
                : sibling.line_meta;
            return !!meta.inclusive;
        },
        shouldShowUnitPriceRoundingTooltip(item) {
            var quantity = parseInt(item.quantity || 1);
            var unitPrice = parseInt(item.unit_price || 0);
            var lineTotal = parseInt(item.line_total || 0);
            if (quantity < 2 || unitPrice <= 0 || lineTotal <= 0) {
                return false;
            }
            var diff = Math.abs(unitPrice * quantity - lineTotal);
            return diff > 0 && diff <= quantity;
        },
        formatOrderAmount(amount, withCurrency = true, hideEmpty = false) {
            var formatted = this.formatNumber(amount, withCurrency, hideEmpty, this.order && this.order.currency);
            if (!formatted) return formatted;

            var numericAmount = Number(amount || 0);
            if (!Number.isFinite(numericAmount) || numericAmount % 100 !== 0) {
                return formatted;
            }

            var shopConfig = window.fluentcart_customer_profile_vars.shop || {};
            var decimalSeparator = shopConfig.decimal_separator === 'comma' ? ',' : '.';
            var decimalToken = decimalSeparator + translateNumber('00');

            return formatted.replace(decimalToken, '');
        },
        formatTaxRateBadge(rate) {
            var pct = parseFloat(rate.rate_percent || 0).toFixed(3).replace(/\.?0+$/, '');
            return (rate.label || translate('Tax')) + ' (' + pct + '%)';
        }
    }
};
</script>
