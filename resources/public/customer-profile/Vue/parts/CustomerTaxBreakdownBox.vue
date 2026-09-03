<template>
  <div class="fct-customer-tax-box">
    <template v-if="order.tax_summary && order.tax_summary.foldedRateLines && order.tax_summary.foldedRateLines.length">
      <div class="tr fct-customer-tax-breakdown-heading">
        <span>{{ $t('Tax breakdown by rate') }}</span>
      </div>
      <div class="tr fct-customer-tax-breakdown-col-headers">
        <span class="fct-customer-tax-breakdown-col-rate">{{ $t('Rate') }}</span>
        <span class="fct-customer-tax-breakdown-col-base">{{ $t('Taxable base') }}</span>
        <span class="fct-customer-tax-breakdown-col-tax">{{ $t('Tax') }}</span>
      </div>
      <div v-for="(line, idx) in order.tax_summary.foldedRateLines"
           :key="'folded-rate-' + idx"
           :class="['tr', 'fct-customer-tax-breakdown-row', line.inclusive ? 'fct-customer-tax-row--muted' : '']">
        <span class="fct-customer-tax-breakdown-col-rate">{{ line.label }}</span>
        <span class="fct-customer-tax-breakdown-col-base">{{ formatOrderAmount(line.base) }}</span>
        <span class="fct-customer-tax-breakdown-col-tax">{{ formatOrderAmount(line.tax) }}</span>
      </div>
      <div v-if="order.is_reverse_charge_tax_order" class="tr fct-customer-tax-row--divider">
        {{ $t('VAT reversed') }}
        <span v-if="order.tax_summary.reversedTaxTotal > 0">{{ formatOrderAmount(order.tax_summary.reversedTaxTotal) }}</span>
        <span v-else>{{ $t('Charge reversed') }}</span>
      </div>
      <template v-else>
        <div class="tr fct-customer-tax-row--divider">
          {{ $t('Total tax') }}
          <span>{{ formatOrderAmount(order.tax_summary.totalOrderTax) }}</span>
        </div>
        <div v-if="order.tax_summary.includedInPrices > 0" class="tr fct-customer-tax-row--muted">
          {{ $t('of which included in prices') }}
          <span>{{ formatOrderAmount(order.tax_summary.includedInPrices) }}</span>
        </div>
        <div v-if="order.tax_summary.payableTax > 0 && order.tax_summary.includedInPrices > 0" class="tr fct-customer-tax-row--divider">
          {{ $t('Payable now (added)') }}
          <span>{{ formatOrderAmount(order.tax_summary.payableTax) }}</span>
        </div>
      </template>
    </template>
    <template v-else-if="!order.is_reverse_charge_tax_order">
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
    <div v-if="order.is_reverse_charge_tax_order && order.tax_summary.showRcShippingRow && order.tax_summary.reversedShippingTax > 0 && !(order.tax_summary.foldedRateLines && order.tax_summary.foldedRateLines.length)"
         class="tr">
      {{ $t('Added on shipping') }}
      <span class="fct-tax-amount fct-tax-amount--reversed">{{ formatOrderAmount(order.tax_summary.reversedShippingTax) }}</span>
    </div>
    <div v-if="order.is_reverse_charge_tax_order && order.tax_summary.reversedTaxTotal > 0 && !(order.tax_summary.foldedRateLines && order.tax_summary.foldedRateLines.length)"
         class="tr">
      {{ $t('Tax reversed') }}
      <span>{{ formatOrderAmount(order.tax_summary.reversedTaxTotal) }}</span>
    </div>
    <div v-if="order.is_reverse_charge_tax_order && !(order.tax_summary.foldedRateLines && order.tax_summary.foldedRateLines.length) && !(order.tax_summary.reversedTaxTotal > 0) && !(order.tax_summary.showRcShippingRow && order.tax_summary.reversedShippingTax > 0)"
         class="tr fct-customer-tax-row--divider">
      {{ $t('VAT reversed') }}
      <span>{{ $t('Charge reversed') }}</span>
    </div>
  </div><!-- /fct-customer-tax-box -->
</template>

<script>
import {translateNumber} from "../../translator/Translator";

export default {
  name: "CustomerTaxBreakdownBox",
  props: {
    order: {
      type: Object,
      required: true
    }
  },
  computed: {
    // Mirrors SingleOrder.vue's customerTaxRateLines/customerTaxShouldShowBreakdown
    // (lines ~518-543) so the popover renders the same non-folded fallback the
    // detailed order-summary view already renders inline.
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
    }
  },
  methods: {
    // $t and formatNumber are provided globally via the app-registered
    // mixin in Start.js, the same way SingleOrder.vue resolves them —
    // no local import needed. formatOrderAmount is local to SingleOrder.vue
    // (not part of the global mixin), so it's replicated here identically,
    // built on top of the inherited global formatNumber.
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
    }
  }
};
</script>
