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
                <ConvertedTime :date-time="row.created_at"/>
              </div><!-- fct-table-date-col -->
            </div><!-- fct-table-mobile-header-left -->

            <div class="fct-table-price-col">
              {{ formatNumber(row.total) }}
            </div>





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


export default {
  name: 'Transaction',
  components: {
    ConvertedTime,
    Badge
  },
  computed: {
    Str() {
      return Str
    }
  },
  props: {
    transactions: {
      type: Array,
      required: true,
    },
    order_id: {
      type: Number,
      required: true,
    }
  },
  emits: ['reload'],
  methods: {
    translate,
    getCardBrand
  }



}
</script>
