<script setup>
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import useSubscriptionTable from "@/utils/table-new/SubscriptionTable";
import SubscriptionsTableComponent from "@/Modules/Subscriptions/Components/SubscriptionsTable.vue";
import SubscriptionsLoader from "@/Modules/Subscriptions/Components/SubscriptionsLoader.vue";
import SubscriptionsLoaderMobile from "@/Modules/Subscriptions/Components/SubscriptionsLoaderMobile.vue";
import SubscriptionsTableMobile from "@/Modules/Subscriptions/Components/SubscriptionsTableMobile.vue";
import {getCurrentInstance, onMounted, onUnmounted, ref} from "vue";
import {ArrowDown} from '@element-plus/icons-vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ExportDialog from "@/Bits/Components/ExportDialog.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import {useExport} from "@/utils/export/useExport";
import translate from "@/utils/translator/Translator";

const subscriptionTable = useSubscriptionTable({
  instance: getCurrentInstance()
});

const isMobileView = ref(false);
const isExportDialogVisible = ref(false);

const buildExportParams = (includeFilters = true) => {
  const baseParams = subscriptionTable.buildQueryParams();
  if (!includeFilters) {
    delete baseParams.active_view;
    delete baseParams.filter_type;
    delete baseParams.search;
    delete baseParams.advanced_filters;
  }

  return baseParams;
};

const exportInstance = useExport({
  entity: 'subscriptions',
  buildParams: () => buildExportParams(),
  buildAllParams: () => buildExportParams(false),
  filenamePrefix: 'subscriptions-export',
});

const handleExport = ({ scope, columns, modules, format }) => {
  const ids = scope === 'current_page'
      ? subscriptionTable.getTableData().map(subscription => subscription.id).filter(Boolean)
      : [];

  exportInstance.startExport({
    scope,
    ids,
    columns,
    modules,
    format
  });
};

const checkMobileView = () => {
  isMobileView.value = window.innerWidth < 768; // You can adjust this breakpoint
};

onMounted(() => {
  checkMobileView(); // Initial check
  window.addEventListener('resize', checkMobileView);
});

onUnmounted(() => {
  window.removeEventListener('resize', checkMobileView);
});
</script>

<template>
  <div class="fct-all-subscriptions-page fct-layout-width">
    <PageHeading :title="$t('Subscriptions')">
      <template #action>
        <UserCan permission="subscriptions/export">
          <el-dropdown trigger="click" popper-class="fct-dropdown" placement="bottom-end">
            <el-button>
              {{ translate('More actions') }}
              <el-icon>
                <ArrowDown/>
              </el-icon>
            </el-button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item @click="isExportDialogVisible = true">
                  <DynamicIcon name="Download"/>
                  {{ translate('Export Subscriptions') }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </UserCan>
      </template>
    </PageHeading>

    <div class="fct-all-subscriptions-wrap">
      <TableWrapper :table="subscriptionTable" :classicTabStyle="true" :has-mobile-slot="true">
        <SubscriptionsLoader v-if="subscriptionTable.isLoading()" :subscriptionTable="subscriptionTable" :next-page-count="subscriptionTable.nextPageCount" />
        <div v-else>
          <SubscriptionsTableComponent :subscriptions="subscriptionTable.getTableData()" :columns="subscriptionTable.data.columns"/>
        </div>
        <template #mobile>
          <SubscriptionsLoaderMobile v-if="subscriptionTable.isLoading()" />
          <SubscriptionsTableMobile v-if="!subscriptionTable.isLoading()" :subscriptions="subscriptionTable.getTableData()" :columns="subscriptionTable.data.columns"/>
        </template>
      </TableWrapper>
    </div>

    <ExportDialog
        v-model="isExportDialogVisible"
        :title="translate('Export Subscriptions')"
        :current-page-count="subscriptionTable.getTableData().length"
        :total-count="subscriptionTable.data.paginate.total"
        :item-label-singular="translate('subscription')"
        :item-label-plural="translate('subscriptions')"
        :has-active-filter="Boolean(subscriptionTable.isFiltering())"
        :export-state="exportInstance"
        @export="handleExport"
    />
  </div>
</template>
