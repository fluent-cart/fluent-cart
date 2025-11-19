<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import useShippingZoneTable from "@/utils/table-new/ShippingZoneTable";
import ShippingZonesTable from "@/Modules/Shipping/Components/ShippingZonesTable.vue";
import ShippingZonesLoader from "@/Modules/Shipping/Components/ShippingZonesLoader.vue";
import translate from "@/utils/translator/Translator";
import {useRouter} from 'vue-router';

const router = useRouter();

const shippingZoneTable = useShippingZoneTable();
</script>

<template>
  <div class="setting-wrap">
    <div class="fct-all-shipping-zones-page">

      <PageHeading :title="translate('Shipping Zones')">
        <template #action>
          <el-button type="primary" @click="router.push({ name: 'add_shipping_zone' })">
            {{ translate('Add Shipping Zone') }}
          </el-button>
        </template>
      </PageHeading>

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
