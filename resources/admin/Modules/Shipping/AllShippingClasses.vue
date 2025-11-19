<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import useShippingClassTable from "@/utils/table-new/ShippingClassTable";
import ShippingClassesTable from "@/Modules/Shipping/Components/ShippingClassesTable.vue";
import ShippingClassesLoader from "@/Modules/Shipping/Components/ShippingClassesLoader.vue";
import translate from "@/utils/translator/Translator";
import { useRouter } from 'vue-router';
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import ShippingClassDrawer from "@/Modules/Shipping/Components/ShippingClassDrawer.vue";
import {ref} from "vue";

const router = useRouter();
const shippingClassTable = useShippingClassTable();
const showClassDrawer = ref(false);
const selectedClass = ref(null);


const openAddClassDrawer = () => {
  selectedClass.value = null;
  showClassDrawer.value = true;
};

const onClassSaved = () => {
  shippingClassTable.fetch();
  showClassDrawer.value = false;
};
</script>

<template>
  <div class="setting-wrap">
    <div class="fct-all-shipping-classes-page">
      <PageHeading :title="translate('Shipping Classes')">
        <template #action>
          <el-button type="primary" @click="openAddClassDrawer">
            {{ translate('Add Shipping Class') }}
          </el-button>
        </template>
      </PageHeading>

      <div class="fct-all-shipping-classes-wrap">
        <TableWrapper :table="shippingClassTable">
          <ShippingClassesLoader v-if="shippingClassTable.isLoading()" :shippingClassTable="shippingClassTable" :next-page-count="shippingClassTable.nextPageCount" />
          <div v-else>
            <ShippingClassesTable :shipping_classes="shippingClassTable.getTableData()" :columns="shippingClassTable.data.columns" @refresh="shippingClassTable.fetch()" />
          </div>
        </TableWrapper>
      </div>

      <ShippingClassDrawer
          v-model="showClassDrawer"
          :class-data="selectedClass"
          @saved="onClassSaved"
      />
    </div>
  </div>
</template>
