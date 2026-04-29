<script setup>
import useOrderTable from "@/utils/table-new/OrderTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import Storage from "@/utils/Storage";
import {computed, ref, onMounted, onUnmounted, getCurrentInstance} from "vue";
import OrderStatBar from '@/Bits/Components/Stats/OrderStat/OrderStatBar.vue';
import {ArrowDown} from '@element-plus/icons-vue';
import {ElMessageBox} from 'element-plus';
import OrderTableComponent from "@/Modules/Orders/Components/OrdersTable.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import OrdersLoader from "@/Modules/Orders/Components/OrdersLoader.vue";
import OrdersLoaderMobile from "@/Modules/Orders/Components/OrdersLoaderMobile.vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import OrdersTableMobile from "@/Modules/Orders/Components/OrdersTableMobile.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import Animation from "@/Bits/Components/Animation.vue";

const orderTable = useOrderTable({
  instance: getCurrentInstance()
});
const showOrderStats = ref(false);
const isMobileView = ref(false);
const isDeletingTestOrders = ref(false);
const showDeleteTestOrdersDialog = ref(false);
const deleteTestOrdersStats = ref({
  total: 0,
  processed: 0,
  deleted: 0,
  failed: 0
});
const deleteTestOrdersMessage = ref('');

const deleteTestOrdersProgress = computed(() => {
  if (!deleteTestOrdersStats.value.total) {
    return 0;
  }

  return Math.min(
      100,
      Math.round((deleteTestOrdersStats.value.processed / deleteTestOrdersStats.value.total) * 100)
  );
});

const deleteTestOrdersProgressTitle = computed(() => {
  if (!deleteTestOrdersStats.value.total) {
    return '';
  }

  const processed = Math.min(
    deleteTestOrdersStats.value.processed,
    deleteTestOrdersStats.value.total
  );

  return isDeletingTestOrders.value
    ? translate(
      'Deleting %1$s of %2$s Test Orders',
      processed,
      deleteTestOrdersStats.value.total
    )
    : translate(
      'Deleted %1$s of %2$s Test Orders',
      processed,
      deleteTestOrdersStats.value.total
    );
});

const deleteTestOrdersProgressStatus = computed(() => {
  if (!deleteTestOrdersStats.value.total) {
    return '';
  }

  return isDeletingTestOrders.value
    ? translate('Deleting... %s%', deleteTestOrdersProgress.value)
    : translate('Completed');
});

const checkMobileView = () => {
  isMobileView.value = window.innerWidth < 768; // You can adjust this breakpoint
};

const resetDeleteTestOrdersState = () => {
  deleteTestOrdersStats.value = {
    total: 0,
    processed: 0,
    deleted: 0,
    failed: 0
  };
  deleteTestOrdersMessage.value = '';
}

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

  if (command === "delete_test_orders") {
    deleteTestOrders();
  }
}

const deleteTestOrders = async () => {
  if (isDeletingTestOrders.value) {
    return;
  }

  try {
    await ElMessageBox.confirm(
      translate('Are you sure you want to delete all test orders? This action cannot be undone.'),
      translate('Confirm Delete!'),
      {
        confirmButtonText: translate('Delete'),
        cancelButtonText: translate('Cancel'),
        cancelButtonClass: 'el-button--small',
        confirmButtonClass: 'el-button--small',
        type: 'warning'
      }
    );
  } catch (error) {
    return;
  }

  isDeletingTestOrders.value = true;
  showDeleteTestOrdersDialog.value = true;
  resetDeleteTestOrdersState();

  try {
    let lastOrderId = 0;
    let totalCount = 0;
    let processedCount = 0;

    while (true) {
      const response = await Rest.post('orders/do-bulk-action', {
        action: 'delete_test_orders',
        last_order_id: lastOrderId
      });

      const responseData = response || {};
      totalCount = totalCount || Number(responseData.total_count || 0);
      processedCount += Number(responseData.batch_count || 0);

      deleteTestOrdersStats.value = {
        total: totalCount,
        processed: processedCount,
        deleted: deleteTestOrdersStats.value.deleted + Number(responseData.deleted_count || 0),
        failed: deleteTestOrdersStats.value.failed + Number(responseData.failed_count || 0)
      };
      deleteTestOrdersMessage.value = responseData.has_more
        ? translate(
          'Processed %1$s of %2$s test orders...',
          Math.min(processedCount, totalCount),
          totalCount
        )
        : (response?.message || '');

      if (!responseData.has_more) {
        break;
      }

      lastOrderId = Number(responseData.last_attempted_order_id || 0);
      await new Promise((resolve) => setTimeout(resolve, 100));
    }

    if (!deleteTestOrdersStats.value.total) {
      showDeleteTestOrdersDialog.value = false;
      Notify.info(translate('No test orders found to delete'));
      return;
    }

    const finalMessage = deleteTestOrdersStats.value.failed
      ? translate(
        'Deleted %1$s test orders. %2$s test orders could not be deleted.',
        deleteTestOrdersStats.value.deleted,
        deleteTestOrdersStats.value.failed
      )
      : translate(
        'Deleted %s test orders successfully',
        deleteTestOrdersStats.value.deleted
      );

    deleteTestOrdersMessage.value = finalMessage;
    Notify.success(finalMessage);
    orderTable.fetch();
  } catch (error) {
    deleteTestOrdersMessage.value = error?.data?.message || translate('Failed to delete test orders');
    Notify.error(error?.data?.message || translate('Failed to delete test orders'));
  } finally {
    isDeletingTestOrders.value = false;
  }
}

const closeDeleteTestOrdersDialog = () => {
  showDeleteTestOrdersDialog.value = false;
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
        <UserCan :permission="['reports/view', 'orders/manage']">
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
                <UserCan permission="reports/view">
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
                </UserCan>
                <UserCan permission="orders/manage">
                  <el-dropdown-item
                      command="delete_test_orders"
                      :disabled="isDeletingTestOrders"
                      class="item-destructive"
                  >
                    <DynamicIcon name="Delete"/>
                    {{ isDeletingTestOrders ? translate('Deleting Test Orders...') : translate('Delete Test Orders') }}
                  </el-dropdown-item>
                </UserCan>
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

    <!-- Delete Test Orders Dialog -->
    <el-dialog
        v-model="showDeleteTestOrdersDialog"
        :title="translate('Delete Test Orders')"
        :append-to-body="true"
        :close-on-click-modal="false"
        :show-close="true"
        :close-on-press-escape="true"
        @close="closeDeleteTestOrdersDialog"
        class="fct-delete-test-orders-modal"
    >
      <div class="fct-delete-test-orders-content">
        <div if="deleteTestOrdersProgressTitle" class="fct-delete-test-orders-title">
          {{ deleteTestOrdersProgressTitle }}
        </div>

        <!-- Status and Message -->
        <div class="fct-delete-test-orders-meta">
          <div class="fct-delete-test-orders-text">
            {{
              isDeletingTestOrders
                ? translate('You can close this — deletion will continue in the background')
                : translate('Test order deletion completed.')
            }}
          </div>

          <el-tag
              v-if="deleteTestOrdersProgressStatus"
              type="success"
              class="fct-delete-test-orders-status"
              :class="{ 'is-complete': !isDeletingTestOrders }"
          >
            {{ deleteTestOrdersProgressStatus }}
          </el-tag>
        </div>

        <!-- Loading Bars -->
        <div v-if="isDeletingTestOrders" class="fct-delete-test-orders-loading">
            <div class="fct-test-orders-loading-content">
                <div class="fct-loading-bars">
                    <div v-for="i in 8" :key="i" class="bar-block" :id="`bar-block-${i + 1}`"></div>
                </div>
                {{
                    translate('Deleting...')
                }}
            </div>
            
            <span class="fct-delete-test-orders-percentage">
                {{ deleteTestOrdersProgress }}%
            </span>
        </div>

        <Animation :visible="isDeletingTestOrders" fade :duration="300">
          <el-progress
              :percentage="deleteTestOrdersProgress"
              :stroke-width="6"
              striped
              striped-flow
              :show-text="false"
              :status="isDeletingTestOrders ? undefined : 'success'"
              class="fct-delete-test-orders-progress"
          />
        </Animation>

        <div class="fct-delete-test-orders-stats">
          <div class="fct-delete-test-orders-stat-row">
            <span class="fct-delete-test-orders-stat-icon">
                <svg width="24" height="24" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path opacity="0.4" d="M13.66 1.1333C13.3741 1.09487 13.0575 1.0716 12.7083 1.05762V1.66146V1.70461C12.7083 2.25782 12.7084 2.73471 12.6567 3.11653C12.6017 3.52616 12.4775 3.91865 12.1592 4.23714C11.8409 4.55563 11.4483 4.68009 11.0383 4.73518C10.6566 4.78653 10.18 4.78649 9.62663 4.78646H9.58329H8.74996H8.70662C8.15333 4.78649 7.67666 4.78653 7.29499 4.73518C6.88499 4.68009 6.49249 4.55563 6.17416 4.23714C5.85583 3.91865 5.73166 3.52616 5.67666 3.11653C5.62333 2.72482 5.625 2.23304 5.625 1.66146V1.05762C5.27583 1.0716 4.95917 1.09487 4.67333 1.1333C3.92333 1.23414 3.2925 1.45038 2.79083 1.95198C2.28917 2.45358 2.07249 3.08503 1.97166 3.83513C1.87499 4.55588 1.875 5.47186 1.875 6.60774V13.3818C1.875 14.5178 1.87499 15.4337 1.97166 16.1544C2.07249 16.9046 2.28917 17.536 2.79083 18.0376C3.2925 18.5392 3.92333 18.7554 4.67333 18.8563C5.39417 18.9532 6.31 18.9532 7.44667 18.9531H10.8866C11.0791 18.9532 11.2641 18.9519 11.4433 18.9515C11.2358 18.5378 11.0666 18.3449 10.9875 18.2671C10.18 18.0578 9.58329 17.3269 9.58329 16.4539C9.58329 15.4184 10.4225 14.5789 11.4583 14.5789C11.6941 14.5789 11.9733 14.6359 12.1158 14.6766C12.31 14.7322 12.52 14.8165 12.7383 14.9346C13.1533 14.298 13.6958 13.5629 14.3133 12.9367C14.8741 12.3678 15.5933 11.7846 16.4583 11.432V6.60774C16.4583 5.47186 16.4583 4.55588 16.3616 3.83513C16.2608 3.08503 16.0441 2.45358 15.5425 1.95198C15.0408 1.45038 14.41 1.23414 13.66 1.1333Z" fill="#017EF3"/>
                    <path d="M17.012 12.3361C17.4462 12.1825 17.922 12.4094 18.0753 12.8431C18.2295 13.2768 18.002 13.753 17.5687 13.9068C17.1453 14.0568 16.6945 14.3786 16.2395 14.8393C15.7912 15.2944 15.3778 15.845 15.022 16.3897C14.6687 16.9317 14.3828 17.4519 14.1853 17.8374C14.087 18.0296 13.9387 18.3456 13.8879 18.4534C13.7587 18.7501 13.4695 18.9462 13.1453 18.9548C12.822 18.9633 12.5228 18.7836 12.3778 18.4942C12.0112 17.7597 11.6821 17.4672 11.5154 17.3564C11.4421 17.3074 11.3937 17.2895 11.3762 17.284C10.9537 17.2435 10.6237 16.8878 10.6237 16.4548C10.6237 15.9945 10.997 15.6214 11.457 15.6214C11.5645 15.6214 11.7428 15.6547 11.8287 15.6792C12.002 15.7287 12.2112 15.8173 12.4403 15.9698C12.6295 16.0958 12.8245 16.2633 13.0228 16.48C13.1945 16.1726 13.3971 15.8302 13.6271 15.4782C14.0187 14.8772 14.5003 14.2296 15.0528 13.6691C15.6003 13.1143 16.2595 12.6028 17.012 12.3361ZM9.16532 11.8714C9.51032 11.8714 9.79032 12.1513 9.79032 12.4964C9.79032 12.8416 9.51032 13.1214 9.16532 13.1214H5.83203C5.48703 13.1214 5.20703 12.8416 5.20703 12.4964C5.20703 12.1513 5.48703 11.8714 5.83203 11.8714H9.16532ZM12.4987 8.53809C12.8437 8.53809 13.1237 8.81793 13.1237 9.16309C13.1237 9.50826 12.8437 9.78809 12.4987 9.78809H5.83203C5.48703 9.78809 5.20703 9.50826 5.20703 9.16309C5.20703 8.81793 5.48703 8.53809 5.83203 8.53809H12.4987ZM11.457 1.0389V1.66309C11.457 2.26994 11.4562 2.66228 11.417 2.95134C11.3803 3.22242 11.3212 3.3074 11.2737 3.35498C11.2262 3.40256 11.1412 3.46176 10.8704 3.49821C10.5812 3.53706 10.1887 3.53809 9.58199 3.53809H8.74866C8.14203 3.53809 7.74952 3.53706 7.46036 3.49821C7.18952 3.46176 7.10453 3.40256 7.05703 3.35498C7.00953 3.3074 6.95036 3.22242 6.9137 2.95134C6.87453 2.66228 6.8737 2.26994 6.8737 1.66309V1.0389C7.05786 1.03839 7.24786 1.03808 7.44536 1.03809H10.8853C11.0828 1.03808 11.2728 1.03839 11.457 1.0389Z" fill="#017EF3"/>
                </svg>
            </span>
            <span class="fct-delete-test-orders-stat-label">{{ translate('Processed') }}</span>
            <span class="fct-delete-test-orders-stat-value">
              {{ deleteTestOrdersStats.processed }} / {{ deleteTestOrdersStats.total }}
            </span>
          </div>

          <div class="fct-delete-test-orders-stat-row">
            <span class="fct-delete-test-orders-stat-icon">
                <svg width="24" height="24" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path opacity="0.4" d="M16.9433 13.0463C16.88 14.0913 16.8292 14.9197 16.725 15.5813C16.6183 16.2605 16.4475 16.8263 16.1067 17.3205C15.7942 17.773 15.3917 18.1547 14.9258 18.4413C14.4158 18.7555 13.8467 18.8922 13.1692 18.958H8.06417C7.38667 18.8922 6.81667 18.7547 6.30667 18.4405C5.84 18.153 5.4375 17.7705 5.125 17.3172C4.78417 16.8222 4.61417 16.2563 4.50833 15.5763C4.405 14.913 4.355 14.083 4.2925 13.0372L3.75 3.95801H17.5L16.9433 13.0463Z" fill="#189877"/>
                    <path d="M11.7488 1.06879C12.2196 1.11046 12.6621 1.25462 13.043 1.53796C13.3238 1.74712 13.5188 2.00379 13.6855 2.28212C13.8405 2.53962 13.9963 2.86046 14.173 3.22462L14.528 3.95879H18.1263C18.5863 3.95879 18.9596 4.33129 18.9596 4.79212C18.9596 5.25212 18.5863 5.62546 18.1263 5.62546H3.1263C2.6663 5.62546 2.29297 5.25212 2.29297 4.79212C2.29297 4.33129 2.6663 3.95879 3.1263 3.95879H6.8013L7.09797 3.30796C7.26964 2.93046 7.4213 2.59796 7.5738 2.33129C7.73797 2.04296 7.93214 1.77629 8.21714 1.55796C8.6013 1.26296 9.05297 1.11379 9.53464 1.06962C9.86213 1.03962 10.193 1.04046 10.5221 1.04129C10.5571 1.04129 10.5913 1.04212 10.6263 1.04212C10.6605 1.04212 10.6955 1.04212 10.7288 1.04212C11.1138 1.04212 11.4605 1.04296 11.7488 1.06879ZM8.63297 3.95879H12.6763C12.4871 3.56879 12.3663 3.32212 12.2571 3.13962C12.0963 2.87296 11.9046 2.75546 11.6005 2.72879C11.3846 2.70962 11.103 2.70879 10.6555 2.70879C10.1963 2.70879 9.90714 2.70962 9.68547 2.72962C9.3738 2.75796 9.17964 2.87962 9.0213 3.15712C8.91714 3.33879 8.80464 3.58212 8.63297 3.95879Z" fill="#189877"/>
                </svg>
            </span>
            <span class="fct-delete-test-orders-stat-label">{{ translate('Deleted') }}</span>
            <span class="fct-delete-test-orders-stat-value is-success">{{ deleteTestOrdersStats.deleted }}</span>
          </div>

          <div class="fct-delete-test-orders-stat-row">
            <span class="fct-delete-test-orders-stat-icon">
                <svg width="24" height="24" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path opacity="0.4" d="M10.0013 1.04004C14.9488 1.04004 18.9596 5.05082 18.9596 9.99833C18.9596 14.9459 14.9488 18.9567 10.0013 18.9567C5.0538 18.9567 1.04297 14.9459 1.04297 9.99833C1.04297 9.54867 1.40778 9.18392 1.85677 9.18375C2.30677 9.18375 2.6722 9.54858 2.6722 9.99833C2.6722 14.0463 5.95297 17.3282 10.0013 17.3282C14.0496 17.3282 17.3304 14.0463 17.3304 9.99833C17.3304 5.95037 14.0496 2.66846 10.0013 2.66846C9.55138 2.66846 9.18764 2.30434 9.18747 1.85466C9.18747 1.40488 9.5513 1.04004 10.0013 1.04004ZM2.80566 4.66634C3.08066 4.35891 3.55043 4.30436 3.89209 4.55241C4.25514 4.81714 4.33519 5.32656 4.07031 5.69011L3.90674 5.92448C3.53507 6.47876 3.23784 7.08712 3.02784 7.73356C2.88857 8.16094 2.42904 8.39483 2.00163 8.25602C1.57424 8.11701 1.33924 7.65747 1.47835 7.22982C1.77169 6.32508 2.20608 5.48362 2.75358 4.73145L2.80566 4.66634ZM7.3125 1.45345C7.71574 1.36659 8.12817 1.59791 8.25814 1.9987C8.39722 2.42644 8.16233 2.88598 7.73486 3.02491L7.46061 3.12011C6.82564 3.35476 6.23137 3.67519 5.69222 4.06738L5.62224 4.11214C5.26557 4.32003 4.80286 4.22905 4.55453 3.88835C4.29044 3.52472 4.37034 3.01452 4.73356 2.74984L5.02084 2.55046C5.69734 2.09692 6.44048 1.7332 7.23194 1.47624L7.3125 1.45345Z" fill="#F04438"/>
                    <path d="M11.9754 6.85201C12.3029 6.58506 12.7854 6.6039 13.0904 6.90897C13.3954 7.21407 13.4145 7.69657 13.147 8.02388L13.0904 8.08736L11.1787 9.99808L13.0895 11.9089L13.147 11.9724C13.4137 12.2997 13.3945 12.7823 13.0895 13.0873C12.7845 13.3923 12.302 13.4113 11.9745 13.1442L11.9112 13.0873L10.0012 11.1765L8.09122 13.0873C7.76538 13.4127 7.23789 13.4127 6.91289 13.0873C6.58706 12.7619 6.58706 12.2344 6.91289 11.9089L8.82288 9.99808L6.91206 8.08736L6.85456 8.02388C6.58789 7.69657 6.60706 7.21407 6.91206 6.90897C7.21706 6.6039 7.69955 6.58506 8.02705 6.85201L8.09039 6.90897L10.0012 8.81975L11.912 6.90897L11.9754 6.85201Z" fill="#F04438"/>
                </svg>
            </span>
            <span class="fct-delete-test-orders-stat-label">{{ translate('Failed') }}</span>
            <span class="fct-delete-test-orders-stat-value is-danger">{{ deleteTestOrdersStats.failed }}</span>
          </div>
        </div>

        <p v-if="deleteTestOrdersMessage && !isDeletingTestOrders" class="fct-delete-test-orders-message">
          {{ deleteTestOrdersMessage }}
        </p>
      </div>

      <template #footer>
        <div v-if="!isDeletingTestOrders" class="dialog-footer">
          <el-button type="primary" @click="closeDeleteTestOrdersDialog">
            {{ translate('Done') }}
          </el-button>
        </div>
      </template>
    </el-dialog>

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

