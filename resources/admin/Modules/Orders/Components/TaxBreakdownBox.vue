<template>
  <div class="fct-tax-sum-box">
    <template v-if="taxSummary.foldedRateLines && taxSummary.foldedRateLines.length">
      <div class="fct-tax-sum-row fct-tax-breakdown-heading">
        <span>{{ translate('Tax breakdown by rate') }}</span>
        <el-icon class="fct-tax-breakdown-info"><InfoFilled /></el-icon>
      </div>
      <div class="fct-tax-sum-row fct-tax-breakdown-col-headers">
        <span class="fct-tax-breakdown-col-rate">{{ translate('Rate') }}</span>
        <span class="fct-tax-breakdown-col-base">{{ translate('Taxable base') }}</span>
        <span class="fct-tax-breakdown-col-tax">{{ translate('Tax') }}</span>
      </div>
      <div v-for="(line, idx) in taxSummary.foldedRateLines"
           :key="'folded-rate-' + idx"
           class="fct-tax-sum-row fct-tax-breakdown-row"
           :class="{'fct-tax-sum-row--muted': line.inclusive}">
        <span class="fct-tax-breakdown-col-rate">{{ line.label }}</span>
        <span class="fct-tax-breakdown-col-base">{{ formatNumber(line.base) }}</span>
        <span class="fct-tax-breakdown-col-tax">{{ formatNumber(line.tax) }}</span>
      </div>
      <div v-if="order.is_reverse_charge_tax_order"
           class="fct-tax-sum-row total-pay total-payable-tax-row fct-tax-sum-row--divider fct-tax-sum-row--reversed">
        <span>{{ translate('VAT reversed') }}</span>
        <span v-if="taxSummary.reversedTaxTotal > 0">{{ formatNumber(taxSummary.reversedTaxTotal) }}</span>
        <span v-else>{{ translate('Charge reversed') }}</span>
      </div>
      <template v-else>
        <div class="fct-tax-sum-row total-pay total-payable-tax-row fct-tax-sum-row--divider">
          <span>{{ translate('Total tax') }}</span>
          <span>{{ formatNumber(taxSummary.totalOrderTax) }}</span>
        </div>
        <div v-if="taxSummary.includedInPrices > 0"
             class="fct-tax-sum-row fct-tax-sum-row--muted">
          <span>{{ translate('of which included in prices') }}</span>
          <span>{{ formatNumber(taxSummary.includedInPrices) }}</span>
        </div>
        <div v-if="taxSummary.payableTax > 0 && taxSummary.includedInPrices > 0"
             class="fct-tax-sum-row fct-tax-sum-row--divider">
          <span>{{ translate('Payable now (added)') }}</span>
          <span>{{ formatNumber(taxSummary.payableTax) }}</span>
        </div>
      </template>
    </template>
    <template v-else-if="!order.is_reverse_charge_tax_order">
      <template v-if="taxSummary.taxRateLines.length && taxBreakdownShouldShow">
        <div v-for="line in taxSummary.taxRateLines"
             :key="'tax-rate-line-' + line.rate_id + '-' + line.label"
             class="fct-tax-sum-row"
             :class="{'fct-tax-sum-row--muted': line.inclusive}">
          <span>{{ line.label }}</span>
          <span>{{ formatNumber(line.order_tax) }}</span>
        </div>
      </template>
      <div v-if="!taxSummary.taxRateLines.length && taxSummary.inclusiveTax > 0 && taxBreakdownShouldShow"
           class="fct-tax-sum-row fct-tax-sum-row--muted">
        <span>{{ translate('Included in item prices') }}</span>
        <span>{{ formatNumber(taxSummary.inclusiveTax) }}</span>
      </div>
      <div v-if="!taxSummary.taxRateLines.length && taxSummary.exclusiveTax > 0 && taxBreakdownShouldShow"
           class="fct-tax-sum-row">
        <span>{{ translate('Added on products') }}</span>
        <span>{{ formatNumber(taxSummary.exclusiveTax) }}</span>
      </div>
      <template v-if="taxSummary.feeTaxLineRows && taxSummary.feeTaxLineRows.length && taxBreakdownShouldShow">
        <div v-for="(row, idx) in taxSummary.feeTaxLineRows"
             :key="idx"
             class="fct-tax-sum-row"
             :class="{'fct-tax-sum-row--muted': row.inclusive}">
          <span>{{ row.display_label }}</span>
          <span>{{ formatNumber(row.tax_amount) }}</span>
        </div>
      </template>
      <template v-if="taxSummary.shippingTax > 0 && taxBreakdownShouldShow">
        <template v-if="taxSummary.shippingTaxLines && taxSummary.shippingTaxLines.length">
          <div v-for="shLine in taxSummary.shippingTaxLines"
               :key="shLine.label"
               class="fct-tax-sum-row"
               :class="{'fct-tax-sum-row--muted': taxSummary.isShippingInclusive}">
            <span>{{ shLine.label }}</span>
            <span>{{ formatNumber(shLine.shipping_tax) }}</span>
          </div>
        </template>
        <div v-else
             class="fct-tax-sum-row"
             :class="{'fct-tax-sum-row--muted': taxSummary.isShippingInclusive}">
          <span>
            {{
              taxSummary.isShippingInclusive
                  ? translate('Included in shipping prices')
                  : translate('Added on shipping')
            }}
          </span>
          <span>{{ formatNumber(taxSummary.shippingTax) }}</span>
        </div>
      </template>
      <div v-if="taxSummary.payableTax > 0"
           class="fct-tax-sum-row total-pay total-payable-tax-row">
        <span>{{ translate('Total payable tax') }}</span>
        <span>{{ formatNumber(taxSummary.payableTax) }}</span>
      </div>
      <div v-if="taxSummary.inclusiveTax > 0 || taxSummary.inclusiveFeeTax > 0"
           class="fct-tax-sum-row fct-tax-sum-row--muted">
        <span>{{ translate('Total tax in this order') }}</span>
        <span>{{ formatNumber(taxSummary.totalOrderTax) }}</span>
      </div>
    </template>
    <div v-if="order.is_reverse_charge_tax_order && taxSummary.showRcShippingRow && taxSummary.reversedShippingTax > 0 && !(taxSummary.foldedRateLines && taxSummary.foldedRateLines.length)"
         class="fct-tax-sum-row">
      <span>{{ translate('Added on shipping') }}</span>
      <span class="fct-tax-amount fct-tax-amount--reversed">{{ formatNumber(taxSummary.reversedShippingTax) }}</span>
    </div>
    <div v-if="order.is_reverse_charge_tax_order && taxSummary.reversedTaxTotal > 0 && !(taxSummary.foldedRateLines && taxSummary.foldedRateLines.length)"
         class="fct-tax-sum-row fct-tax-sum-row--reversed">
      <span>{{ translate('Tax reversed') }}</span>
      <span>{{ formatNumber(taxSummary.reversedTaxTotal) }}</span>
    </div>
    <div v-if="order.is_reverse_charge_tax_order && !(taxSummary.foldedRateLines && taxSummary.foldedRateLines.length) && !(taxSummary.reversedTaxTotal > 0) && !(taxSummary.showRcShippingRow && taxSummary.reversedShippingTax > 0)"
         class="fct-tax-sum-row fct-tax-sum-row--reversed">
      <span>{{ translate('VAT reversed') }}</span>
      <span>{{ translate('Charge reversed') }}</span>
    </div>
  </div>
</template>

<script type="text/babel">
import translate from "@/utils/translator/Translator";
import {InfoFilled} from "@element-plus/icons-vue";

export default {
  name: "TaxBreakdownBox",
  components: {
    InfoFilled,
  },
  props: {
    taxSummary: {
      type: Object,
      required: true,
    },
    order: {
      type: Object,
      required: true,
    },
    taxBreakdownShouldShow: {
      type: Boolean,
      default: false,
    },
  },
  methods: {
    translate,
    formatNumber(amount, withCurrency = true, hideEmpty = false) {
      return this.formatNumberForOrder(amount, this.order, withCurrency, hideEmpty);
    },
  },
};
</script>
