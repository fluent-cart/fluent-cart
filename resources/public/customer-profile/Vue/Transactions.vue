<template>
  <div class="fct-order-transaction-lists">
    <div class="fct-plain-order-box pt-0">
      <header class="fct-single-order-header">
        <h3 class="title">
          {{ translate('Transactions') }}
        </h3>
      </header>


      <div class="fct-single-order-body">
        <ul class="transaction-lists !pl-3" role="list">
          <template v-for="(transaction, index) in transactions" :key="index">
            <li v-if="transaction.transaction_type !== 'refund' && transaction.meta.reason !== 'renewal'" class="flex items-center gap-1 text-sm" role="listitem">

              <span :aria-label="$t('Transaction amount') + ': ' + formatNumber(transaction.total)" class="font-semibold text-system-dark">
                {{ formatNumber(transaction.total) }}
              </span>

              <span v-if="transaction.status === 'succeeded'">{{$t('Payment')}}</span>

              <Badge :type="transaction.status" size="small">
                {{ transaction.status }}
              </Badge>

              <time 
                datetime="{{ transaction.created_at }}" 
                :aria-label="$t('Transaction date') + ': ' + dateTimeI18(transaction.created_at)"
              >
                on {{ dateTimeI18(transaction.created_at) }}
              </time>

              <span v-if="transaction.status === 'succeeded'" class="mr-1">{{$t('using')}}</span>

              <span v-if="transaction.status === 'succeeded'" class="inline-flex gap-1 items-center">
                <template v-if="transaction.card_last_4">
                   <img 
                      :src="`${assetsPath}images/credit-card/card.svg`" alt="Credit Card" class="w-4.5 h-4.5"
                      :alt="$t('Credit card ending in') + ' ' + transaction.card_last_4"
                    >
                    <span aria-hidden="true">**** {{ transaction.card_last_4 }}</span>
                </template>
              </span>

              <el-tooltip
                  v-if=" transaction.meta && transaction.meta.refunded_total"
                  :content="`Partially refunded: ${formatNumber(transaction.meta.refunded_total)}`"
                  placement="top"
              >
                <DynamicIcon name="InformationFill" class="w-4.5" aria-hidden="true"/>
              </el-tooltip>

              <a 
                :href="receiptUrl(order?.uuid)" 
                target="_blank" 
                class="print-order-receipt text-primary-500 text-sm p-1 inline-flex items-center gap-1 ml-auto font-semibold text-system-dark"
                rel="noopener noreferrer"
                :aria-label="$t('Download receipt for transaction on') + ' ' + dateTimeI18(transaction.created_at)"
              >
                {{ translate('Receipt') }}
                <DynamicIcon name="Download" class="w-4 h-4" aria-hidden="true"/>
              </a>
            </li>
          </template>

          <li v-for="(transaction, index) in renewals" :key="index" class="renewal-item flex items-center gap-2 text-sm" role="listitem">

            <template v-if="transaction?.transactions && Array.isArray(transaction.transactions) && transaction.transactions.length">
              <template v-for="firstTxn in [transaction.transactions[0]]">
                <span 
                  :aria-label="$t('Transaction amount') + ': ' + formatNumber(transaction.total_amount, true, false, transaction.currency)"
                  v-html="formatNumber(transaction.total_amount, true, false, transaction.currency)"
                ></span>

                <span v-if="firstTxn.status === 'succeeded'">{{$t('Payment')}}</span>

                <Badge :type="firstTxn.status" size="small">
                  {{ firstTxn.status }}
                </Badge>

                <time 
                  datetime="{{ firstTxn.created_at }}" 
                  :aria-label="$t('Transaction date') + ': ' + dateTimeI18(firstTxn.created_at)"
                >
                  on {{ dateTimeI18(firstTxn.created_at) }}
                </time>

                <span v-if="firstTxn.status === 'succeeded'">
                  {{$t('using')}}
                </span>

                <span v-if="firstTxn.status === 'succeeded'" class="inline-flex gap-1 items-center">
                <template v-if="firstTxn.card_last_4">
                  <img 
                    :src="`${assetsPath}images/credit-card/card.svg`"
                    class="w-4.5 h-4.5"
                    :alt="$t('Credit card ending in') + ' ' + firstTxn.card_last_4"
                    loading="lazy"
                  >
                    <span aria-hidden="true">**** {{ firstTxn.card_last_4 }}</span>
                  </template>
                </span>

                <el-tooltip
                    v-if="transaction.total_refund > 0"
                    :content="`Partially refunded: ${formatNumber(transaction.total_refund)}`"
                    placement="top"
                >
                  <DynamicIcon name="InformationFill" class="w-4.5" aria-hidden="true"/>
                </el-tooltip>

                <a 
                    :href="receiptUrl(transaction?.uuid)" target="_blank"
                    class="print-order-receipt text-primary-500 text-sm p-1 inline-flex items-center gap-1 ml-auto font-semibold text-system-dark"
                    rel="noopener noreferrer"
                    :aria-label="$t('Download receipt for transaction on') + ' ' + dateTimeI18(firstTxn.created_at)"
                >
                   {{ translate('Receipt') }} <DynamicIcon name="Download" class="w-4 h-4" aria-hidden="true"/>
                </a>
              </template>
            </template>

          </li>
        </ul>


      </div>
    </div>
  </div>
</template>

<script setup>
import translate from "../translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Badge from "@/Bits/Components/Badge.vue";
import {computed} from "vue";
import {dateTimeI18} from "../../translator/Translator";

const props = defineProps({
  transactions: Object,
  renewals: Object,
  order: Object,
});

const receiptUrl = (uuid) => {
  if (!uuid) {
    return '';
  }
  return window.fluentcart_customer_profile_vars.site_url+'?fluent-cart=receipt&order_hash='+uuid+'&download=1';
}
const assetsPath = computed(() => {
  return window.fluentcart_customer_profile_vars.assets_path;
});
</script>
