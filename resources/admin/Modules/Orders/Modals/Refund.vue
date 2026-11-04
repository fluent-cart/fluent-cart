<template>
    <div>
      <div v-if="refundNote" class="fct-refund-note-list">
        <div v-for="(item, key) in refundNote" :key="key" class="fct-refund-note-item">
          <span class="message">{{ item.message }}</span>
          <Badge :status="item.status"/>
        </div>
      </div>

      <div v-else-if="forceLocalPrompt" class="fct-refund-force-local">
        <el-alert type="error" :closable="false" :title="forceLocalPrompt.message">
          <p v-if="forceLocalPrompt.gateway_message">{{ forceLocalPrompt.gateway_message }}</p>
        </el-alert>

        <div>
          <!-- translators: %1$s: payment gateway name -->
          <p>{{ translate('Order_Timeout_Check', forceLocalPrompt.gateway) }}</p>
          <p><strong>{{ translate('Record locally instead?') }}</strong></p>
          <!-- translators: %1$s: payment gateway name -->
          <p>{{ translate('This does not refund through %1$s. Return the money yourself if needed.', forceLocalPrompt.gateway) }}</p>
          <ul class="fct-refund-force-local-impact fct-bullet-lists">
            <li><strong>{{ _x('Refund', 'Refund amount label') }}</strong> {{ formatNumber(forceLocalPrompt.impact.amount) }}</li>
            <li v-for="item in forceLocalPrompt.impact.items" :key="item.id">
              <!-- translators: %1$s: item name, %2$s: refunded quantity, %3$s: restocked quantity -->
              {{ forceLocalPrompt.impact.manage_stock && item.restore_quantity > 0 ? translate('%1$s × %2$s (%3$s restocked)', itemName(item.id), item.refund_qty, item.restore_quantity) : translate('%1$s × %2$s', itemName(item.id), item.refund_qty) }}
            </li>
            <li v-if="forceLocalPrompt.impact.shipping_amount > 0">
              <strong>{{ translate('Shipping') }}</strong> {{ formatNumber(forceLocalPrompt.impact.shipping_amount) }}
            </li>
            <li>
              <strong>{{ translate('Payment status') }}</strong>
              {{ forceLocalPrompt.impact.payment_status_after === 'refunded' ? translate('Refunded') : translate('Partially Refunded') }}
            </li>
            <li v-if="forceLocalPrompt.impact.cancel_subscription">
              <strong>{{ translate('Subscription') }}</strong> {{ translate('Not cancelled') }}
            </li>
            <li><strong>{{ translate('Customer email') }}</strong> {{ translate('Sent, if enabled') }}</li>
          </ul>
        </div>

        <div class="dialog-footer is-border">
          <el-button @click.prevent="forceLocalPrompt = null; failedRefundPayload = null" :disabled="refundProcessing">
            {{ translate('Cancel') }}
          </el-button>
          <el-button type="warning" @click.prevent="recordLocally" :loading="refundProcessing">
            {{ translate('Record locally only') }}
          </el-button>
        </div>
      </div>

      <div v-else>
        <el-form label-position="top" require-asterisk-position="right">
            <el-row :gutter="10">
                <el-col :lg="24">
                    <el-form-item :label="$t('Refund with transaction')" required="">
                        <el-select v-model="refund.transaction_id" :placeholder="$t('Select')"
                                   @change="updateMaxRefund">
                            <el-option v-for="item in refundableTransactions" :key="item.id"
                                       :label="
                                       /* translators: %1$s - transaction id, %2$s - refundable amount */
                                       translate(
                                   '#%1$s - Paid %2$s',
                                           item.id,
                                           formatNumber(item.refundable, true)
                                       )"
                                       :value="item.id">
                            </el-option>
                        </el-select>
                    </el-form-item>

                    <el-form-item :label="$t('Items')">
                        <div class="fct-refund-items-scroll">
                            <div
                                v-for="item in refundableItems"
                                :key="item.id"
                                class="fct-refund-item-row"
                                :class="{ 'is-disabled': item.remainingBase <= 0 }"
                            >
                                <el-checkbox
                                    v-model="item.isSelected"
                                    :disabled="item.remainingBase <= 0"
                                />
                                <div class="fct-refund-item-meta">
                                    <span class="fct-refund-item-name">{{ item.post_title }}<template v-if="item.title && item.title !== item.post_title"> — {{ item.title }}</template></span>
                                    <span v-if="item.moneyOnly" class="fct-refund-item-price"><!-- translators: %1$s: amount left to refund on the item -->{{ translate('%1$s left', formatNumber(item.remainingBase)) }}</span>
                                    <span v-else class="fct-refund-item-price">{{ formatNumber(Number(item.refund_total) > 0 ? item.unitAmount : item.baseUnit) }}<template v-if="!itemLineInclusive(item) && item.unitTax > 0 && !Number(item.refund_total)"> <span class="fct-refund-tax-label"><!-- translators: %1$s: per-unit tax amount -->{{ translate('+%1$s tax/qty', formatNumber(item.unitTax)) }}</span></template></span>
                                    <!-- translators: %1$s: shipped units by shipment status, e.g. 1 (In Transit), 1 (Delivered) -->
                                    <span v-if="showShippedSummary(item) && shippedSummary(item)" class="fct-refund-item-price">{{ translate('Shipped: %1$s', shippedSummary(item)) }}</span>
                                </div>
                                <!-- translators: %1$s: item name -->
                                <QuantityStepper
                                    :label="translate('Quantity to refund for %1$s', itemName(item.id))"
                                    v-if="item.payment_type !== 'signup_fee'"
                                    :model-value="item.refund_qty"
                                    :min="item.maxQty > 0 ? 1 : 0"
                                    :max="item.maxQty"
                                    :disabled="!item.isSelected || item.maxQty === 0"
                                    show-max
                                    class="shrink-0"
                                    @update:model-value="(qty) => { item.refund_qty = qty; item.shipped_qty = shippedQtyBounds(item).min; }"
                                />
                                <template v-if="showShippedQty(item)">
                                    <span class="fct-refund-item-price">{{ translate('Already shipped') }}</span>
                                    <!-- translators: %1$s: item name -->
                                    <QuantityStepper
                                        :label="translate('Already shipped for %1$s', itemName(item.id))"
                                        v-model="item.shipped_qty"
                                        :min="shippedQtyBounds(item).min"
                                        :max="shippedQtyBounds(item).max"
                                        :disabled="!item.isSelected"
                                        class="shrink-0"
                                    />
                                </template>
                            </div>
                        </div>

                        <div v-if="shippingRemaining > 0" class="fct-refund-shipping-row">
                            <el-checkbox v-model="includeShipping">
                                <template v-if="shippingTaxRemaining > 0">
                                    <!-- translators: %1$s: refundable shipping amount, %2$s: refundable shipping tax -->
                                    {{ translate('Include shipping (%1$s + %2$s tax)', formatNumber(shippingRemaining - shippingTaxRemaining), formatNumber(shippingTaxRemaining)) }}
                                </template>
                                <template v-else>
                                    <!-- translators: %1$s: refundable shipping amount -->
                                    {{ translate('Include shipping (%1$s)', formatNumber(shippingRemaining)) }}
                                </template>
                            </el-checkbox>
                            <el-input-number
                                v-if="includeShipping"
                                v-model="shippingRefundAmount"
                                :min="Math.min(refundStep, shippingRemaining / 100)"
                                :max="shippingRemaining / 100"
                                :precision="refundStep === 1 ? 0 : 2"
                                :step="refundStep"
                                controls-position="right"
                                class="fct-refund-shipping-amount"
                            />
                        </div>

                        <div v-if="anyManageStockSelected" class="fct-refund-restock-row">
                            <el-checkbox v-model="restockItems">{{ $t('Restock items to inventory') }}</el-checkbox>
                        </div>
                        <el-alert
                            :title="translate('Shipped units will not be restocked.')"
                            v-if="anyManageStockSelected && restockItems && shippedNotRestocked > 0"
                            type="info"
                            :closable="false"
                        />
                    </el-form-item>

                    <el-col :lg="24">
                        <el-form-item :label="$t('Refund amount')" required="">
                            <el-input
                                type="number"
                                :min="hasSelectedItems ? shippingCents / 100 + refundStep : refundStep"
                                :step="refundStep"
                                :max="hasRefundSelection ? computedRefundAmount : maxRefundAmount"
                                :readonly="hasRefundSelection && !hasSelectedItems"
                                :model-value="hasSelectedItems && itemRefundAmount !== null ? itemRefundAmount : (hasRefundSelection ? computedRefundAmount : refund.amount)"
                                @input="val => { if (hasSelectedItems) { itemRefundAmount = val; } else if (!hasRefundSelection) { refund.amount = Number(val); checkMaxRefundAmount(); } }"
                            >
                                <template #prefix="">
                                    {{ orderCurrencySign }}
                                </template>
                            </el-input>
                            <div class="form-note">
                                <p>{{ $t('Max refund amount for this transaction:') }}
                                    <span style="color: #F04438; font-weight: 500;">
                                      {{ formatNumber(maxRefundAmount * 100) }}
                                    </span>
                                </p>
                            </div>
                        </el-form-item>

                        <el-col :lg="24" v-if="isSubscriptionCancelable">
                            <el-form-item :label="$t('Subscription')">
                                <el-checkbox :label="$t('Cancel Subscription (if any)')"
                                             v-model="refund.cancelSubscription">
                                </el-checkbox>
                            </el-form-item>
                        </el-col>

                        <el-col :lg="24">
                          <el-form-item :label="$t('Reason of refund')">
                            <el-input v-model="refund.reason"/>
                            <div class="form-note form-note-tooltip">
                              <p>
                                {{ translate('Your customers can see this reason') }}
                                <span>
                                    <el-tooltip
                                        popper-class="fct-tooltip"
                                        placement="top"
                                        :content="translate('Order_Set_Possible')"
                                    >
                                      <DynamicIcon name="InformationFill"/>
                                    </el-tooltip>
                                </span>
                              </p>
                            </div>
                          </el-form-item>
                        </el-col>
                    </el-col>
                </el-col>
            </el-row>
        </el-form>
        <div class="dialog-footer is-border">
            <el-button
                type="primary"
                @click.prevent="processRefund"
                :loading="refundProcessing"
                :disabled="hasRefundSelection ? !selectionRefundAmount || selectionRefundAmount <= 0 : refund.amount <= 0"
            >
                {{ refundProcessing ? translate('Processing') : translate('Refund') }}
                <template v-if="hasRefundSelection && selectionRefundAmount > 0"> {{ formatNumber(selectionRefundAmount * 100) }}</template>
                <template v-else-if="!hasRefundSelection && refund.amount > 0"> {{ formatNumber(refund.amount * 100) }}</template>
            </el-button>
        </div>
      </div>
    </div>
</template>
<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import VariationSelector from "@/Bits/Components/VariationSelector.vue";
import translate, {_x} from "@/utils/translator/Translator";
import {restStatus} from "@/utils/http/restError";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import AppConfig from "@/utils/Config/AppConfig";
import Arr from "@/utils/support/Arr";
import Badge from "@/Bits/Components/Badge.vue";
import QuantityStepper from "@/Bits/Components/Inputs/QuantityStepper.vue";

export default {
    name: "Refund",
    components: {
      Badge,
        QuantityStepper,
        VariationSelector,
        DynamicIcon
    },
    props: ['order_id', 'order', 'is_subscription', 'subscription_status', 'showRefundModal'],
    data() {
        return {
            transactions: {},
            maxRefundAmount: '',
            refundProcessing: false,
            refundableTransactions: [],
            refundableItems: [],
            includeShipping: false,
            shippingRefundAmount: 0,
            itemRefundAmount: null,
            restockItems: true,
            refund: {
                transaction_id: '',
                amount: 0,
                cancelSubscription: true,
                reason: '',
                availableAmount: '',
                paymentMethod: '',
            },
            totalPaid: 0,
            refundNote: '',
            forceLocalPrompt: null,
            failedRefundPayload: null
        }
    },
    computed: {
        refundStep() {
            const transaction = this.refundableTransactions.find(item => item.id === this.refund.transaction_id);
            // Stripe stores 'jpy'; the decimals map is keyed 'JPY'
            const currency = String(transaction?.currency || this.order.currency || '').toUpperCase();
            return AppConfig.get('currency_decimals', {})[currency] === 0 ? 1 : 0.01;
        },
        CurrencyFormatter() {
            return CurrencyFormatter
        },
        orderCurrencySign() {
            const signs = AppConfig.get('currency_signs', {});
            const defaultSign = AppConfig.get('shop.currency_sign', '$');
            return (this.order && this.order.currency && signs[this.order.currency]) ? signs[this.order.currency] : defaultSign;
        },
        isSubscriptionCancelable() {
            return this.is_subscription && this.subscription_status !== 'canceled' && this.subscription_status !== 'expired';
        },
        anyManageStockSelected() {
            // Cancelling the order already restocked it.
            return this.order.status !== 'canceled' && this.refundableItems.some(i => i.isSelected && i.refund_qty > 0 && i.variants && i.variants.manage_stock === '1');
        },
        shippedNotRestocked() {
            return this.refundableItems
                .filter(i => i.isSelected && i.refund_qty > 0 && i.shippable && i.variants?.manage_stock === '1')
                .reduce((sum, i) => sum + (Number(i.shipped_qty) || 0), 0);
        },
        hasSelectedItems() {
            return this.refundableItems.some(i => i.isSelected);
        },
        shippingRemaining() {
            return Math.max(0, Number(this.order.shipping_refundable) || 0);
        },
        shippingTaxRemaining() {
            const shippingLeft = Math.max(0, (Number(this.order.shipping_total) || 0) - (Number(this.order.shipping_refunded_amount) || 0));
            return Math.max(0, this.shippingRemaining - shippingLeft);
        },
        hasRefundSelection() {
            return this.hasSelectedItems || (this.includeShipping && this.shippingRefundAmount > 0);
        },
        shippingCents() {
            return this.includeShipping ? Math.round(this.shippingRefundAmount * 100) : 0;
        },
        computedRefundAmount() {
            const selected = this.refundableItems.filter(i => i.isSelected);
            const shippingCents = this.shippingCents;
            if (!selected.length && !shippingCents) return null;
            // Mirror the server: a full-line refund charges the exact remaining base (not rounded
            // per-unit × qty, which can drift a few cents and wrongly trip the transaction-limit guard).
            const itemsTotal = selected.reduce((sum, i) => {
                return sum + (i.refund_qty >= i.maxQty ? i.remainingBase : Math.min(i.remainingBase, i.unitAmount * i.refund_qty));
            }, 0);
            const rawCents = itemsTotal + shippingCents;
            // Upgrade/plan-change orders store prorate credit at order level (not in item discount_total),
            // so item line_total can exceed what the order charged. Cap to what is left on the order, as the
            // server does; the submit check flags an amount above the selected transaction's refundable.
            const orderLeft = Math.max(0, (parseInt(this.order.total_paid) || 0) - (parseInt(this.order.total_refund) || 0));
            return Math.min(rawCents, orderLeft) / 100;
        },
        selectionRefundAmount() {
            return this.hasSelectedItems && this.itemRefundAmount !== null ? Number(this.itemRefundAmount) : this.computedRefundAmount;
        },
        // computedRefundAmount stays pinned at the order cap, so it cannot tell the selection changed.
        selectionKey() {
            const items = this.refundableItems.filter(i => i.isSelected).map(i => i.id + 'x' + i.refund_qty);
            return items.join(',') + '|' + this.shippingCents;
        },
    },
    watch: {
        showRefundModal: {
            // el-dialog mounts this component on its first open, already true
            immediate: true,
            handler(val) {
                this.refundNote = '';
                this.forceLocalPrompt = null;
                this.failedRefundPayload = null;
                if (val) {
                    // Shipping refunded before its tax was refundable: the tax may already be back by custom amount.
                    this.includeShipping = this.shippingRemaining > this.shippingTaxRemaining;
                    this.shippingRefundAmount = this.shippingRemaining / 100;
                    this.restockItems = true;
                    this.itemRefundAmount = null;
                    this.refundableItems = this.buildRefundableItems(this.order.order_items);
                }
            }
        },
        selectionKey() {
            this.itemRefundAmount = null;
        },
        includeShipping(val) {
            if (val && this.shippingRefundAmount <= 0) {
                this.shippingRefundAmount = this.shippingRemaining / 100;
            }
        },
        'order.order_items': {
            immediate: true,
            handler(items) {
                this.refundableItems = this.buildRefundableItems(items);
            }
        }
    },
    mounted() {
        this.refund.availableAmount = parseInt(this?.order?.total_paid) - parseInt(this?.order?.total_refund);

        this.transactions = this.order?.transactions;
        if (this.transactions.length) {
            this.updateRefundable();
        }
    },
    methods: {
        translate,
        _x,
        formatNumber(amount, withCurrency = true, hideEmpty = false) {
            return this.formatNumberForOrder(amount, this.order, withCurrency, hideEmpty);
        },
        itemLineInclusive(item) {
            const raw = item.line_meta && item.line_meta.tax_config ? item.line_meta.tax_config.inclusive : undefined;
            if (raw !== undefined) {
                return raw === true || raw === 'true' || raw === 1 || raw === '1';
            }
            // tax_behavior 3 mixes inclusive/exclusive per line, so the order-level flag can't answer it.
            // The item's own tax_inclusion is authoritative; fall back to the order-level guess only when absent.
            const inclusion = item.other_info ? item.other_info.tax_inclusion : undefined;
            if (inclusion === 'included' || inclusion === 'excluded') {
                return inclusion === 'included';
            }
            return this.order.tax_behavior === 2;
        },
        // Mirrors OrderItem::chargedTotal(): a trial line's first order charged only its signup fee line.
        itemIsTrialOnFirstOrder(item) {
            return item.payment_type === 'subscription'
                && Number(item.other_info?.trial_days) > 0
                && this.order.type !== 'renewal';
        },
        itemTaxInLineTotal(item) {
            const raw = item.line_meta && item.line_meta.tax_config ? item.line_meta.tax_config.inclusive : undefined;
            const hasTaxConfig = raw !== undefined && raw !== null;
            const inclusion = item.other_info ? item.other_info.tax_inclusion : undefined;
            if (this.order.type !== 'renewal' || hasTaxConfig || inclusion === 'included' || inclusion === 'excluded') {
                return false;
            }
            // Renewal lines store line_total with the tax already in it and no tax_config
            const tax = Number(item.tax_amount) || 0;
            return tax > 0 && Number(item.line_total) === Number(item.subtotal) - (Number(item.discount_total) || 0) + tax;
        },
        buildRefundableItems(items) {
            if (!items) return [];

            // On upgrade/plan-change orders, adjustment items represent the actual net charge;
            // subscription items show the full plan price and would inflate the refund preview.
            // Mirror filteredOrderItems priority: when adjustment items exist, drop subscription items.
            const hasAdjustment = items.some(i => i.payment_type === 'adjustment');
            const wholeUnits = AppConfig.get('currency_decimals', {})[String(this.order.currency || '').toUpperCase()] === 0;
            const roundUnit = cents => wholeUnits ? Math.round(cents / 100) * 100 : Math.round(cents);

            return items
                .filter(i => !(hasAdjustment && i.payment_type === 'subscription'))
                .map(i => {
                    const currQty = Number(i.quantity) || 1;
                    const unitsLeft = Math.max(0, currQty - (Number(i.line_meta?.refunded_quantity) || 0));
                    const lineInclusive = this.itemLineInclusive(i);
                    const trialLine = this.itemIsTrialOnFirstOrder(i);
                    const lineBase = trialLine ? 0 : (lineInclusive || this.itemTaxInLineTotal(i) ? Number(i.line_total) : Number(i.line_total) + Number(i.tax_amount));
                    const alreadyRefunded = Number(i.refund_total) || 0;
                    const remainingBase = Math.max(0, lineBase - alreadyRefunded);
                    const unitAmount = unitsLeft > 0 ? roundUnit(remainingBase / unitsLeft) : 0;
                    const maxQty = remainingBase <= 0 ? 0 : unitsLeft;
                    const unitTax = trialLine ? 0 : roundUnit(Number(i.tax_amount) / currQty);
                    const baseUnit = roundUnit(lineBase / currQty) - (lineInclusive ? 0 : unitTax);
                    // Mirrors OrderItem::shippedUnitsKept().
                    const shippedKept = Math.min(Math.max(0, (Number(i.fulfilled_quantity) || 0) - (Number(i.line_meta?.refunded_shipped_quantity) || 0)), unitsLeft);

                    const row = {
                        ...i,
                        isSelected: maxQty > 0,
                        // A lowered refund counted the units, but not all of their money.
                        moneyOnly: unitsLeft === 0 && remainingBase > 0,
                        refund_qty: maxQty,
                        maxQty,
                        unitsLeft,
                        shippedKept,
                        shippable: i.fulfillment_type === 'physical' && i.payment_type !== 'signup_fee' && !i.line_meta?.parent_item_id,
                        unitAmount,
                        baseUnit,
                        unitTax,
                        remainingBase,
                    };
                    row.shipped_qty = this.shippedQtyBounds(row).min;

                    return row;
                });
        },
        // Mirrors RefundPlanner::shippedQtyBounds().
        shippedQtyBounds(item) {
            const qty = Number(item.refund_qty) || 0;
            const max = Math.min(qty, item.shippedKept);

            return {
                min: Math.min(max, Math.max(0, qty - (item.unitsLeft - item.shippedKept))),
                max,
            };
        },
        showShippedQty(item) {
            if (!item.shippable) {
                return false;
            }
            const bounds = this.shippedQtyBounds(item);

            return bounds.min < bounds.max;
        },
        checkMaxRefundAmount() {
            if (this.refund.amount > this.maxRefundAmount) {
                this.refund.amount = this.maxRefundAmount;
            }
        },
        updateMaxRefund() {
            const id = this.refund.transaction_id;
            const transaction = this.refundableTransactions.find(item => item.id === id);
            this.refund.amount = transaction.refundable
                ? parseFloat(transaction.refundable / 100) : 0;
            this.refund.paymentMethod = transaction.payment_method;
            this.maxRefundAmount = this.refund.amount;
        },
        updateRefundable() {
            // Only include transactions with status 'paid'. `refundable` comes from the
            // API (OrderTransaction::getMaxRefundableAmount()) — do not recompute it
            // client-side, it can be filtered lower than item.total - refunded_total
            // (e.g. merchant-of-record gateways capping to the actual captured amount).
            this.refundableTransactions = this.transactions
                .filter(item => item.status === 'succeeded');

            if (this.refundableTransactions.length !== 0) {
                this.refund.transaction_id = this.refundableTransactions[0].id;
                this.refund.payment_method = this.refundableTransactions[0].payment_method;
                this.updateMaxRefund(this.refund.transaction_id);
            }
        },
        shippedSummary(item) {
            const statuses = AppConfig.get('shipment_statuses', {});
            const qtyByStatus = {};
            (this.order.shipments || []).forEach((shipment) => {
                (shipment.items || []).forEach((line) => {
                    if (Number(line.order_item_id) === Number(item.id)) {
                        qtyByStatus[shipment.status] = (qtyByStatus[shipment.status] || 0) + (Number(line.qty) || 0);
                    }
                });
            });

            return Object.keys(qtyByStatus).map(status => qtyByStatus[status] + ' (' + (statuses[status] || status) + ')').join(', ');
        },
        showShippedSummary(item) {
            return this.restockItems && item.isSelected && item.shippable && item.shippedKept > 0 && item.variants?.manage_stock === '1';
        },
        itemName(itemId) {
            const item = (this.order.order_items || []).find(i => i.id === itemId);
            if (!item) {
                return '#' + itemId;
            }
            return item.title && item.title !== item.post_title ? item.post_title + ' — ' + item.title : item.post_title;
        },
        processRefund() {
            this.refundProcessing = true;

            const refundedItems = this.refundableItems
                .filter(i => i.isSelected)
                .map(i => (this.showShippedQty(i)
                    ? { id: i.id, refund_qty: i.refund_qty, shipped_qty: i.shipped_qty }
                    : { id: i.id, refund_qty: i.refund_qty }));

            if (!this.hasRefundSelection && !this.refund.amount) {
                this.refundProcessing = false;
                return this.handleMessage('Please add refund amount.', 'error');
            }

            const refundUnits = Number(this.refund.amount) / this.refundStep;
            // Tolerance absorbs float error (1.01 / 0.01 = 101.00000000000001).
            if (!this.hasRefundSelection && Math.abs(refundUnits - Math.round(refundUnits)) > 1e-6) {
                this.refundProcessing = false;
                return this.handleMessage(this.refundStep === 1
                    ? translate('Refund amount must be a whole number for this currency.')
                    : translate('Refund amount must use the smallest unit of the transaction currency.'), 'error');
            }

            if (!this.hasRefundSelection && this.refund.amount > this.refund.availableAmount) {
                this.refundProcessing = false;
                return this.handleMessage(`Refund amount should not exceed: ${this.orderCurrencySign}${this.refund.availableAmount}`, 'error');
            }

            const computedCents = Math.round(this.computedRefundAmount * 100);
            const selectionCents = Math.round(this.selectionRefundAmount * 100);
            if (this.hasSelectedItems && selectionCents !== computedCents) {
                const selectionUnits = Number(this.selectionRefundAmount) / this.refundStep;
                let error = '';
                if (Math.abs(selectionUnits - Math.round(selectionUnits)) > 1e-6) {
                    error = this.refundStep === 1
                        ? translate('Refund amount must be a whole number for this currency.')
                        : translate('Refund amount must use the smallest unit of the transaction currency.');
                } else if (selectionCents > computedCents) {
                    error = translate('Refund amount cannot be more than the selected items and shipping.');
                } else if (selectionCents <= this.shippingCents) {
                    error = translate('Refund amount must be more than the shipping refund.');
                }
                if (error) {
                    this.refundProcessing = false;
                    return this.handleMessage(error, 'error');
                }
            }

            if (this.hasRefundSelection) {
                const selectedTxn = this.refundableTransactions.find(t => t.id === this.refund.transaction_id);
                const txnRefundable = selectedTxn ? Number(selectedTxn.refundable) || 0 : 0;
                if (selectionCents > txnRefundable) {
                    this.refundProcessing = false;
                    return this.handleMessage(`Refund amount should not exceed the selected transaction limit: ${this.orderCurrencySign}${txnRefundable / 100}`, 'error');
                }
            }

            // This modal works in DOLLARS throughout — refundable is divided by
            // 100 on load, and every max/available comparison and display below
            // assumes that. The endpoint takes CENTS, so convert only here, at
            // the submit boundary, leaving the rest of the component untouched.
            const refundInfo = {
                ...this.refund,
                amount: Math.round(parseFloat(this.refund.amount) * 100)
            };

            this.submitRefund({
                refund_info: {
                    ...refundInfo,
                    // 0 asks the server for its own price, whose cap can sit below this modal's.
                    amount: this.hasRefundSelection ? (selectionCents === computedCents ? 0 : selectionCents) : refundInfo.amount,
                    shipping_amount: this.shippingCents,
                    manageStock: this.anyManageStockSelected ? this.restockItems : false,
                },
                refunded_items: refundedItems
            });
        },
        recordLocally() {
            const payload = this.failedRefundPayload;
            this.refundProcessing = true;
            this.submitRefund({
                ...payload,
                refund_info: {...payload.refund_info, force_local: true}
            });
        },
        submitRefund(payload) {
            const forceLocal = payload.refund_info.force_local === true;

            this.$post("orders/" + this.order_id + '/refund', payload).then(res => {
                this.forceLocalPrompt = null;
                this.failedRefundPayload = null;
                this.refundNote = res;
                this.updateRefundable();
            }).catch(errors => {
                if (!forceLocal && errors?.data?.can_force_local) {
                    this.forceLocalPrompt = errors.data;
                    this.failedRefundPayload = payload;
                    return;
                }
                this.forceLocalPrompt = null;
                this.failedRefundPayload = null;
                const status = restStatus(errors);
                if (!errors?.data && (status === 0 || status >= 500)) {
                    return this.handleError(translate('Order_Response_Received'));
                }
                // Keep the typed amount: reloading the max here would put the full amount back under the button
                this.handleError(errors?.data || errors);
            }).finally(() => {
                this.refundProcessing = false;
            });
        }
    }
}
</script>
<style scoped>
.fct-refund-force-local {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.fct-refund-force-local-impact {
    margin: 0;
    padding-left: 18px;
    font-size: 13px;
    line-height: 1.7;
}
.fct-refund-items-scroll {
    width: 100%;
    max-height: 280px;
    overflow-y: auto;
    border: 1px solid var(--el-border-color);
    border-radius: var(--el-border-radius-base);
}

.fct-refund-item-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--el-border-color-lighter);
    min-width: 0;
}

.fct-refund-item-row:last-child {
    border-bottom: none;
}

.fct-refund-item-row.is-disabled {
    opacity: 0.45;
}

.fct-refund-item-meta {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
    overflow: hidden;
}

.fct-refund-item-name {
    font-size: 13px;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
}

.fct-refund-item-price {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    white-space: nowrap;
}

.fct-refund-tax-label {
    color: var(--el-color-warning);
    margin-left: 4px;
}

.fct-refund-shipping-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
    padding: 8px 12px;
    border: 1px solid var(--el-border-color);
    border-radius: var(--el-border-radius-base);
}

.fct-refund-shipping-amount {
    width: 120px;
}

.fct-refund-restock-row {
    margin-top: 8px;
    padding: 0 2px;
}
</style>
