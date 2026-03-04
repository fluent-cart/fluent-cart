<script setup>
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import useShippingZoneTable from "@/utils/table-new/ShippingZoneTable";
import ShippingZonesTable from "@/Modules/Shipping/Components/ShippingZonesTable.vue";
import ShippingZonesLoader from "@/Modules/Shipping/Components/ShippingZonesLoader.vue";
import translate from "@/utils/translator/Translator";
import {useRouter} from 'vue-router';
import SettingsHeader from "../Settings/Parts/SettingsHeader.vue";

const router = useRouter();

const shippingZoneTable = useShippingZoneTable();
</script>

<template>
  <div class="setting-wrap">
    <SettingsHeader
        :heading="translate('Shipping Zones')"
        :show-save-button="false"
    >
      <template #action>
        <el-button type="primary" @click="router.push({ name: 'add_shipping_zone' })" size="small">
          {{ translate('Add Shipping Zone') }}
        </el-button>
      </template>
    </SettingsHeader>

    <div class="setting-wrap-inner">
      <div class="fct-all-shipping-zones-wrap">
        <TableWrapper :table="shippingZoneTable">
          <ShippingZonesLoader v-if="shippingZoneTable.isLoading()" :shippingZoneTable="shippingZoneTable"
                               :next-page-count="shippingZoneTable.nextPageCount"/>
          <div v-else>
            <ShippingZonesTable :shipping_zones="shippingZoneTable.getTableData()"
                                :columns="shippingZoneTable.data.columns" @refresh="shippingZoneTable.fetch()"/>
          </div>
        </TableWrapper>
      </div>
    </div>
  </div>
</template>
