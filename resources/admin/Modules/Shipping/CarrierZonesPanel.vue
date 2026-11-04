<template>
  <div class="mt-6">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-semibold m-0 text-system-dark dark:text-gray-50">{{ translate('Shipping Zones') }}</h3>
      <!-- Default, not primary: Save in the page header is this page's one primary action. -->
      <el-button size="small" @click="router.push({ name: 'add_shipping_zone' })">
        {{ translate('Add Shipping Zone') }}
      </el-button>
    </div>
    <Card.Container>
      <Card.Body>
        <p class="text-sm text-system-mid dark:text-gray-300 mb-3">
          {{ translate('These are the general shipping zones that apply to all products.') }}
        </p>
        <TableWrapper :table="shippingZoneTable">
          <ShippingZonesLoader
              v-if="shippingZoneTable.isLoading()"
              :shippingZoneTable="shippingZoneTable"
              :next-page-count="shippingZoneTable.nextPageCount"
          />
          <div v-else>
            <ShippingZonesTable
                :shipping_zones="shippingZoneTable.getTableData()"
                :columns="shippingZoneTable.data.columns"
                @refresh="shippingZoneTable.fetch()"
            />
          </div>
        </TableWrapper>
      </Card.Body>
    </Card.Container>
  </div>
</template>

<script setup>
/**
 * The zones table, extracted so it only mounts for the Manual carrier.
 *
 * useShippingZoneTable() registers an onBeforeMount fetch in its constructor, so
 * merely instantiating it on the parent fired GET shipping/zones on EVERY
 * carrier's settings page — including future API carriers, which have no zones.
 * Living in its own component means the request happens when the panel is
 * actually rendered, and not before.
 */
import { useRouter } from 'vue-router';
import translate from '@/utils/translator/Translator';
import * as Card from '@/Bits/Components/Card/Card.js';
import useShippingZoneTable from '@/utils/table-new/ShippingZoneTable';
import TableWrapper from '@/Bits/Components/TableNew/TableWrapper.vue';
import ShippingZonesTable from '@/Modules/Shipping/Components/ShippingZonesTable.vue';
import ShippingZonesLoader from '@/Modules/Shipping/Components/ShippingZonesLoader.vue';

const router = useRouter();

const shippingZoneTable = useShippingZoneTable({ shipping_class_id: 0 });
</script>
