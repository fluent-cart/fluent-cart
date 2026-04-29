<script setup>

import {translateNumber} from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  table: {
    type: Object,
    required: true,
  }
});
</script>

<template>
  <el-table
      :data="table.getTableData()"
      class="w-full compact-table"
  >
    <el-table-column v-if="false" type="selection" width="50"/>

    <el-table-column :label="translate('Customer')" width="300">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{
                    name: 'view_customer',
                    params: { customer_id: scope.row?.id },
                  }"
        >
          <div class="fct-customer-card" v-if="scope.row?.id">
            <div class="fct-customer-avatar">
              <span>
                <img :src="scope.row?.photo" alt="avatar"/>
              </span>
            </div>
            <div class="fct-customer-details">
              <div class="fct-customer-name">
                {{ scope.row?.full_name || translate("No Name") }}
                <span class="fct-customer-id">#{{ translateNumber(scope.row?.id) }}</span>
              </div>
              <div class="fct-customer-email">
                {{ scope.row?.email }}
              </div>
            </div>
          </div>
        </RouteCell>
      </template>
    </el-table-column>

    <el-table-column :label="translate('Address')" width="300">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{
                    name: 'view_customer',
                    params: { customer_id: scope.row?.id },
                  }"
        >
          {{getOrderAddress(scope.row)}}

        </RouteCell>
      </template>
    </el-table-column>

    <el-table-column :label="translate('Purchases')" :width="90">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{
                    name: 'view_customer',
                    params: { customer_id: scope.row?.id },
                  }"
        >
          {{ translateNumber(scope.row.purchase_count) }}
        </RouteCell>
      </template>
    </el-table-column>

    <el-table-column v-if="table.isColumnVisible('ltv')" :label="translate('LTV')" :width="100">
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{
                    name: 'view_customer',
                    params: { customer_id: scope.row?.id },
                  }"
        >
          <span v-html="CurrencyFormatter.formatNumber(scope.row.ltv, true, false)"></span>

        </RouteCell>
      </template>
    </el-table-column>

    <el-table-column
        v-if="table.isColumnVisible('last_purchase_date')"
        :label="translate('Last Purchase Date')"
        width="200"
    >
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{
                    name: 'view_customer',
                    params: { customer_id: scope.row?.id },
                  }"
        >
          <template v-if="scope.row.last_purchase_date">
            <ConvertedTime :date-time="scope.row.last_purchase_date" />
          </template>
          <span v-else>---</span>
        </RouteCell>
      </template>
    </el-table-column>

    <el-table-column
        v-if="table.isColumnVisible('customer_since')"
        :label="translate('Customer Since')"
        width="150"
    >
      <template #default="scope">
        <RouteCell
            class="hover:no-underline"
            :to="{
                    name: 'view_customer',
                    params: { customer_id: scope.row?.id },
                  }"
        >
          <ConvertedTime :date-time="scope.row.created_at"/>

        </RouteCell>
      </template>
    </el-table-column>

    <template #empty>
      <Empty icon="Empty/ListView" :has-dark="true" :text="translate('No customer found!')"/>
    </template>
  </el-table>
</template>

<style scoped>

</style>
