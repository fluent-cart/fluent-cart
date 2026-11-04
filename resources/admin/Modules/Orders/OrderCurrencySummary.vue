<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import Badge from "@/Bits/Components/Badge.vue";
import translate from "@/utils/translator/Translator";
import {computed} from "vue";

const props = defineProps({
  currency: {
    type: Object,
    required: true,
  },
  baseCode: {
    type: String,
    default: '',
  },
});

const rate = computed(() => String(props.currency.rate).replace(/\.?0+$/, ''));
</script>

<template>
  <Card.Container class="fct-order-currency-summary">
    <Card.Header
        :title="translate('Order currency')"
        title_size="small"
        border_bottom
    >
      <template #action>
        <Badge
            :type="currency.is_base ? 'info' : 'blue'"
            :text="currency.is_base ? translate('Store currency') : currency.sign + ' ' + currency.code"
            size="small"
        />
      </template>
    </Card.Header>
    <Card.Body>
      <dl class="fct-order-currency-summary-list">
        <dt>{{ translate('Store currency') }}</dt>
        <dd>{{ baseCode }}</dd>
        <dt>{{ translate('Exchange rate') }}</dt>
        <dd>{{ currency.is_base ? '—' : translate('1 %1$s = %2$s %3$s', baseCode, rate, currency.code) }}</dd>
        <dt>{{ translate('Product prices') }}</dt>
        <dd>{{ currency.is_base ? translate('As set on the product') : translate('Converted from %1$s', baseCode) }}</dd>
        <dt>{{ translate('Custom prices') }}</dt>
        <dd>{{ translate('Entered in %1$s', currency.code) }}</dd>
      </dl>
      <p class="fct-order-currency-summary-hint">
        {{ translate('Change the currency from the Products card.') }}
      </p>
    </Card.Body>
  </Card.Container>
</template>
