<script setup>
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import useShippingClassTable from "@/utils/table-new/ShippingClassTable";
import ShippingClassesTable from "@/Modules/Shipping/Components/ShippingClassesTable.vue";
import ShippingClassesLoader from "@/Modules/Shipping/Components/ShippingClassesLoader.vue";
import translate from "@/utils/translator/Translator";
import ShippingClassDrawer from "@/Modules/Shipping/Components/ShippingClassDrawer.vue";
import {ref} from "vue";
import SettingsHeader from "../Settings/Parts/SettingsHeader.vue";

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
    <SettingsHeader
        :heading="translate('Shipping Classes')"
        :save-button-text="translate('Add Shipping Class')"
        @onSave="openAddClassDrawer"
    />


    <div class="setting-wrap-inner">
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
