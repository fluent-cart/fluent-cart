<script setup>
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  table: {
    type: Object,
    required: true,
  },
  emptyText: {
    type: String,
    default: translate('No customers found.')
  }
});
</script>

<template>
  <div class="fct-customer-table-mobile-wrap">
    <div
        v-for="(row, rowIndex) in table.getTableData()"
        :key="row.id"
        class="fct-customer-table-mobile-row"
        @click="$router.push({ name: 'view_customer', params: { customer_id: row.id } })"
    >
      <div class="fct-customer-table-mobile-header">
        <div v-if="row?.id" class="fct-customer-table-customer-col fct-customer-card">
          <div class="fct-customer-avatar">
            <img :src="row?.photo" alt="avatar"/>
          </div>

          <div class="fct-customer-details">
            <div class="fct-customer-name">
              {{ row?.full_name || translate("No Name") }}
              <span class="fct-customer-id">#{{ row?.id }}</span>
            </div>
            <div class="fct-customer-email">
              {{ row?.email }}
            </div>
          </div>
        </div>

        <div class="fct-customer-table-purchases-col">
          {{ row.purchase_count }} {{ row.purchase_count > 1 ? translate('Purchases') : translate('Purchase') }}
        </div>
      </div><!-- fct-customer-table-mobile-header -->

      <div class="fct-customer-table-mobile-body">
        <ul class="fct-customer-table-mobile-body-list">
          <li v-if="table.isColumnVisible('ltv')" class="fct-customer-table-ltv-col">
            <div class="label">{{translate('LTV:')}}</div>
            <div class="value" v-html="CurrencyFormatter.formatNumber(row.ltv, true, false)"></div>
          </li>

          <li v-if="table.isColumnVisible('last_purchase_date')" class="fct-customer-table-last-purchase-date-col">
            <div class="label">{{translate('Last Purchase Date:')}}</div>
            <div class="value">
              <ConvertedTime :date-time="row.last_purchase_date"/>
            </div>
          </li>

          <li v-if="table.isColumnVisible('customer_since')" class="fct-customer-table-customer-since-col">
            <div class="label">{{translate('Customer Since:')}}</div>
            <div class="value">
              <ConvertedTime :date-time="row.created_at"/>
            </div>
          </li>
        </ul>

      </div><!-- fct-customer-table-mobile-body -->

      <div class="fct-customer-table-mobile-footer">
        <div class="fct-customer-table-address-col">
          <DynamicIcon name="LocationPin"/>
          {{ getOrderAddress(row) }}
        </div>
      </div><!-- fct-customer-table-mobile-footer -->

    </div>

    <!-- Empty State -->
    <div v-if="table.getTableData().length === 0" class="py-6 text-center">
      <Empty icon="Empty/Order" has-dark :text="emptyText"/>
    </div>
  </div>
</template>

