<template>
    <div class="fct-table-mobile-wrap fct-transaction-details-mobile-wrap">
        <div class="fct-table-mobile-row" v-for="(row, rowIndex) in transactions" :key="rowIndex">
          <div class="fct-table-mobile-header">
            <div class="fct-table-mobile-header-left">
              <div class="fct-table-status-col">
                <el-tooltip
                    v-if="row?.meta?.reason"
                    effect="dark"
                    :placement="'top'"
                    :content="translate(row?.meta?.reason)"
                    popper-class="fct-label-hint-popover fct-tooltip"
                >
                  <Badge
                      v-if="row?.meta?.disputed === 'yes' && row?.meta?.dispute_resolved === 'no'"
                      :status="(row.status)">
                    {{ row.status === 'paid' ? 'Completed' : row.status }}
                  </Badge>
                  <Badge v-else :status="(row.status)">
                    {{ row.status === 'paid' ? 'Completed' : row.status }}
                  </Badge>
                </el-tooltip>

                <Badge v-else :status="(row.status)">
                  {{ row.status === 'paid' ? 'Completed' : row.status }}
                </Badge>
              </div><!-- fct-table-status-col -->

              <div class="fct-table-date-col">
                <span class="id">#{{ row.id }}</span>
                <span class="bullet">•</span>
                <ConvertedTime :date-time="row?.meta?.settled_at || row.created_at"/>
              </div><!-- fct-table-date-col -->
            </div><!-- fct-table-mobile-header-left -->

            <div class="fct-table-price-col">
              <template v-if="row?.meta?.mor_vat_removed > 0">
                {{ formatNumberForOrder(row.total - row.meta.mor_vat_removed, orderCurrency) }}
                <p class="p-0 m-0 text-xs text-gray-500">
                  {{ translate('Tax reversed') }}: {{ formatNumberForOrder(row.meta.mor_vat_removed, orderCurrency) }}
                </p>
              </template>
              <template v-else>
                {{ formatNumberForOrder(row.total, orderCurrency) }}
              </template>
            </div>

            <el-tooltip
                v-if="row.transaction_type == 'charge' && row.status == 'pending' && row.vendor_charge_id"
                effect="dark"
                :placement="'top'"
                :content="translate('Sync payment status from %1$s', row.payment_method)"
                popper-class="fct-tooltip"
            >
              <el-button size="small" :aria-label="translate('Sync payment status from %1$s', row.payment_method)" :loading="syncing_transaction === row.id" @click="syncTransaction(row)">
                <DynamicIcon class="w-4 h-4" name="Refresh" aria-hidden="true"/>
              </el-button>
            </el-tooltip>


          </div>

          <div class="fct-table-mobile-body">
            <ul class="fct-table-mobile-body-list">
              <li class="fct-table-mobile-method-col">
                <div class="label">{{translate('Payment Method:')}}</div>
                <div class="value">
                  <div class="flex items-center gap-2.5">
                    <div
                        class="w-7"
                        v-if="getCardBrand(row.card_brand, row.payment_method_type)"
                    >
                      <img
                          class="w-full"
                          :title="row.payment_method"
                          :src="getCardBrand(row.card_brand, row.payment_method_type)"
                          :alt="row.card_brand"
                      >
                    </div>

                    <span v-else class="capitalize">{{row.payment_method_type}}</span>

                    <span v-if="row.card_last_4 && row.card_last_4 != '0'">
                      {{ '...' + row.card_last_4 }}
                    </span>
                  </div>
                </div>
              </li>

              <li class="fct-table-mobile-gateway-col">
                <div class="label">{{translate('Gateway ID:')}}</div>
                <div class="value">
                  <span v-if="row.vendor_charge_id === ''">--</span>
                  <a v-else class="link" target="_blank" :href="row.url">
                    {{ row.vendor_charge_id }}
                  </a>
                </div>
              </li>

            </ul>

          </div><!-- fct-table-mobile-body -->




        </div>
    </div>
</template>

<script type="text/babel">
import Badge from "@/Bits/Components/Badge.vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import translate from "@/utils/translator/Translator";
import Str from "@/utils/support/Str";
import {getCardBrand} from "@/Bits/common.js";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";


export default {
  name: 'Transaction',
  components: {
    ConvertedTime,
    Badge,
    DynamicIcon
  },
  props: {
    transactions: {
      type: Array,
      required: true,
    },
    order_id: {
      type: Number,
      required: true,
    },
    orderCurrency: {
      type: String,
      default: null,
    },
  },
  emits: ['reload'],
  data() {
    return {
      syncing_transaction: null
    }
  },
  computed: {
    Str() {
      return Str
    }
  },
  methods: {
    translate,
    getCardBrand,
    syncTransaction(transaction) {
      if (this.syncing_transaction) {
        return;
      }
      this.syncing_transaction = transaction.id;
      this.$post('orders/' + this.order_id + '/transactions/' + transaction.id + '/sync')
          .then(response => {
            this.$notify.success(response.message);
            this.$emit('reload');
          })
          .catch(errors => {
            this.$notify.error(errors?.data?.message || translate('Something went wrong!'));
          })
          .finally(() => {
            this.syncing_transaction = null;
          });
    }
  }



}
</script>
