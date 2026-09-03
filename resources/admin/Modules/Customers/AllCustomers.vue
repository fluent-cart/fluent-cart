<script setup>
import CustomerTable from "@/utils/table-new/CustomerTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import {onMounted, onUnmounted, ref} from "vue";
import {ArrowDown} from '@element-plus/icons-vue';
import NewCustomerModal from "@/Modules/Orders/Modals/NewCustomerModal.vue";
import translate from "@/utils/translator/Translator";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import CustomersLoader from "@/Modules/Customers/parts/CustomersLoader.vue";
import CustomersTable from "@/Modules/Customers/Components/CustomersTable.vue";
import CustomersTableMobile from "@/Modules/Customers/Components/CustomersTableMobile.vue";
import CustomersLoaderMobile from "@/Modules/Customers/parts/CustomersLoaderMobile.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import ExportDialog from "@/Bits/Components/ExportDialog.vue";
import {useExport} from "@/utils/export/useExport";

const customerTable = CustomerTable();
const customerModalInfos = ref({
  showModal: false,
  action: "create",
  title: translate("Create New Customer"),
  customer: {},
});

const isMobileView = ref(false);
const isExportDialogVisible = ref(false);
const selectedCustomers = ref([]);

const buildExportParams = (includeFilters = true) => {
  const baseParams = customerTable.buildQueryParams();
  if (!includeFilters) {
    delete baseParams.active_view;
    delete baseParams.filter_type;
    delete baseParams.search;
    delete baseParams.advanced_filters;
  }

  return baseParams;
};

const exportInstance = useExport({
  entity: 'customers',
  buildParams: () => buildExportParams(),
  buildAllParams: () => buildExportParams(false),
  filenamePrefix: 'customers-export',
});

const handleExport = ({ scope, columns, modules, format }) => {
  let ids = [];

  if (scope === 'current_page') {
    ids = customerTable.getTableData().map(customer => customer.id).filter(Boolean);
  } else if (scope === 'selected') {
    ids = selectedCustomers.value.map(customer => customer.id).filter(Boolean);
  }

  exportInstance.startExport({
    scope,
    ids,
    columns,
    modules,
    format
  });
};

const handleSelectionChange = (customers) => {
  selectedCustomers.value = customers;
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
  <div class="fct-all-customers-page fct-layout-width">

    <PageHeading :title="translate('Customers')">
      <template #action>
        <UserCan permission="customers/export">
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
                  {{ translate('Export Customers') }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </UserCan>

        <UserCan permission="customers/manage">
          <el-button type="primary" @click="customerModalInfos.showModal = true">
            {{ translate("Add Customer") }}
          </el-button>
        </UserCan>
      </template>
    </PageHeading>


    <UserCan permission="customers/view">
      <div class="fct-all-customers-wrap">
        <TableWrapper :table="customerTable" :has-mobile-slot="true">
          <CustomersLoader v-if="customerTable.isLoading()" :customerTable="customerTable" :next-page-count="customerTable.nextPageCount" />
          <div v-else>
            <CustomersTable :table="customerTable" @selection-change="handleSelectionChange" />
          </div>
          <template #mobile>
            <CustomersLoaderMobile v-if="customerTable.isLoading()"/>
            <CustomersTableMobile :table="customerTable" />
          </template>
        </TableWrapper>
      </div>
    </UserCan>

    <UserCan permission="customers/manage">
      <NewCustomerModal
          @update:customer-modal="
        () => {
          customerModalInfos.showModal = false;
          customerModalInfos.customer = {};
          customerTable.fetch();
        }
      "
          :customerModalInfos="customerModalInfos"
          :customer_id="customerModalInfos.customer_id"
          :customer="customerModalInfos.customer"
          @close-modal="customerModalInfos.showModal = false"
      />
    </UserCan>

    <ExportDialog
        v-model="isExportDialogVisible"
        :title="translate('Export Customers')"
        :selected-count="selectedCustomers.length"
        :current-page-count="customerTable.getTableData().length"
        :total-count="customerTable.data.paginate.total"
        :item-label-singular="translate('customer')"
        :item-label-plural="translate('customers')"
        :has-active-filter="Boolean(customerTable.isFiltering())"
        :export-state="exportInstance"
        @export="handleExport"
    />
  </div>
</template>
