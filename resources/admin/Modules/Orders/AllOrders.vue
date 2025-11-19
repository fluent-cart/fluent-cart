<script setup>
import useOrderTable from "@/utils/table-new/OrderTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import Storage from "@/utils/Storage";
import {ref, onMounted, onUnmounted, getCurrentInstance} from "vue";
import OrderStatBar from '@/Bits/Components/Stats/OrderStat/OrderStatBar.vue';
import {ArrowDown, User} from '@element-plus/icons-vue';
import OrderTableComponent from "@/Modules/Orders/Components/OrdersTable.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import OrdersLoader from "@/Modules/Orders/Components/OrdersLoader.vue";
import OrdersLoaderMobile from "@/Modules/Orders/Components/OrdersLoaderMobile.vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import OrdersTableMobile from "@/Modules/Orders/Components/OrdersTableMobile.vue";

const orderTable = useOrderTable({
  instance: getCurrentInstance()
});
const showOrderStats = ref(false);
const isMobileView = ref(false);

const checkMobileView = () => {
  isMobileView.value = window.innerWidth < 768; // You can adjust this breakpoint
};


const handleShowOrderStats = (command) => {
  if (command === "show_order_stats") {
    Storage.set('show_order_stats', !showOrderStats.value);
    showOrderStats.value = !showOrderStats.value;
  }

  if (command === "show_delete_bulk_action") {
    orderTable.showBulkDeleteAction(true);
  }
  if (command === "hide_delete_bulk_action") {
    orderTable.showBulkDeleteAction(false);
  }
}

const getStoredOrderStats = () => {
  const storedValue = Storage.get('show_order_stats');
  if (storedValue) {
    showOrderStats.value = storedValue;
  }
}

onMounted(() => {
  getStoredOrderStats();
  checkMobileView(); // Initial check
  window.addEventListener('resize', checkMobileView);
});

onUnmounted(() => {
  window.removeEventListener('resize', checkMobileView);
});

</script>

<template>
  <div class="fct-all-orders-page fct-layout-width">
    <PageHeading :title="translate('Orders')">
      <template #action>
        <UserCan permission="reports/view">
          <el-dropdown trigger="click" popper-class="fct-dropdown" @command="handleShowOrderStats"
                       placement="bottom-end">
            <el-button>
              {{ translate('More actions') }}
              <el-icon>
                <ArrowDown/>
              </el-icon>
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="show_order_stats">
                  <template v-if="!showOrderStats">
                    <DynamicIcon name="Eye"/>
                    {{translate('Show Order Stats')}}
                  </template>
                  <template v-else>
                    <DynamicIcon name="EyeOff"/>
                    {{translate('Hide Order Stats')}}
                  </template>
                </el-dropdown-item>
                <el-dropdown-item v-if="!orderTable.data.showDeleteBulkAction" command="show_delete_bulk_action">
                  <DynamicIcon name="Eye"/>
                  {{ translate('Show Bulk Actions') }}
                </el-dropdown-item>
                <el-dropdown-item v-if="orderTable.data.showDeleteBulkAction" command="hide_delete_bulk_action">
                  <DynamicIcon name="EyeOff"/>
                  {{ translate('Hide Bulk Actions') }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </UserCan>
        <UserCan permission="orders/create">
          <el-button tag="router-link" type="primary" :to="{ name: 'add_order' }">
            {{ translate('Create Order') }}
          </el-button>
        </UserCan>
      </template>
    </PageHeading>

    <UserCan permission="reports/view">
      <OrderStatBar v-if="showOrderStats"/>
    </UserCan>

    <UserCan permission="orders/view">

      <div class="fct-all-orders-wrap">
        <TableWrapper :table="orderTable" :classicTabStyle="true" :hasMobileSlot="true">

          <OrdersLoader v-if="orderTable.isLoading()" :orderTable="orderTable"
                        :next-page-count="orderTable.nextPageCount"/>
          <OrderTableComponent v-else :table="orderTable" :orders="orderTable.getTableData()" :columns="orderTable.data.columns" :empty-text="orderTable.emptyMessage"/>

          <template #mobile>
            <OrdersLoaderMobile v-if="orderTable.isLoading()"/>

            <OrdersTableMobile v-if="!orderTable.isLoading()" :table="orderTable" :orders="orderTable.getTableData()" :columns="orderTable.data.columns" :empty-text="orderTable.emptyMessage"/>
          </template>

        </TableWrapper>
      </div>
    </UserCan>

  </div>
</template>
