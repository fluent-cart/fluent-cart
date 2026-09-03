<script setup>
import useLicenseTable from "@/utils/table-new/LicenseTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import Storage from "@/utils/Storage";
import {ref, getCurrentInstance, onMounted, onUnmounted} from "vue";
import {ArrowDown, User} from '@element-plus/icons-vue';
import LicenseTableComponent from "./_LicenseTable.vue";
import LicenseStats from "./_LicenseStats.vue";
import UserCan from "../../admin/Bits/Components/Permission/UserCan.vue";
import LicensesLoader from "./LicensesLoader.vue";
import LicensesLoaderMobile from "./LicensesLoaderMobile.vue";
import DynamicIcon from "../../admin/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import LicenseTableMobile from "./_LicenseTableMobile.vue";
import ExportDialog from "../../admin/Bits/Components/ExportDialog.vue";
import {useExport} from "@/utils/export/useExport";

const licenseTable = useLicenseTable();
const showLicenseStats = ref(false);

const isMobileView = ref(false);
const isExportDialogVisible = ref(false);

const buildExportParams = (includeFilters = true) => {
  const baseParams = licenseTable.buildQueryParams();
  if (!includeFilters) {
    delete baseParams.active_view;
    delete baseParams.filter_type;
    delete baseParams.search;
    delete baseParams.advanced_filters;
  }

  return baseParams;
};

const exportInstance = useExport({
  entity: 'licenses',
  buildParams: () => buildExportParams(),
  buildAllParams: () => buildExportParams(false),
  filenamePrefix: 'licenses-export',
});

const handleExport = ({ scope, columns, modules, format }) => {
  const ids = scope === 'current_page'
      ? licenseTable.getTableData().map(license => license.id).filter(Boolean)
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

const handleShowLicenseStats = (command) => {
  if (command === "show_license_stats") {
    Storage.set('show_license_stats', !showLicenseStats.value);
    showLicenseStats.value = !showLicenseStats.value;
  }
}

const getStoredLicenseStats = () => {
  const storedValue = Storage.get('show_license_stats');
  if (storedValue) {
    showLicenseStats.value = storedValue;
  }
}

onMounted(() => {
  getStoredLicenseStats();
  checkMobileView(); // Initial check
  window.addEventListener('resize', checkMobileView);
});

onUnmounted(() => {
  window.removeEventListener('resize', checkMobileView);
});
</script>

<template>
  <div class="fct-all-license-page fct-layout-width">
    <PageHeading :title="translate('Licenses')">
      <template #action>
        <router-link :to="{ name: 'activated_sites' }">
          <el-button>
            <DynamicIcon name="Sites"/>
            {{ translate('Sites') }}
          </el-button>
        </router-link>
        <UserCan :permission="['reports/view', 'licenses/export']">
          <el-dropdown trigger="click" popper-class="fct-dropdown" @command="handleShowLicenseStats"
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
                  <el-dropdown-item command="show_license_stats">
                    <template v-if="!showLicenseStats">
                      <DynamicIcon name="Eye"/>
                      {{translate('Show License Stats')}}
                    </template>
                    <template v-else>
                      <DynamicIcon name="EyeOff"/>
                      {{translate('Hide License Stats')}}
                    </template>
                  </el-dropdown-item>
                </UserCan>
                <UserCan permission="licenses/export">
                  <el-dropdown-item @click="isExportDialogVisible = true">
                    <DynamicIcon name="Download"/>
                    {{ translate('Export Licenses') }}
                  </el-dropdown-item>
                </UserCan>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </UserCan>
      </template>
    </PageHeading>

    <UserCan permission='reports/view'>
      <LicenseStats v-if="showLicenseStats" :licenses="licenseTable.getTableData()"/>
    </UserCan>


    <UserCan permission="licenses/view">
      <div class="fct-all-license-wrap">
        <TableWrapper :table="licenseTable" :classicTabStyle="true" :has-mobile-slot="true">
          <LicensesLoader v-if="licenseTable.isLoading()" :licenseTable="licenseTable" :next-page-count="licenseTable.nextPageCount" />
          <div v-else>
            <LicenseTableComponent :licenses="licenseTable.getTableData()" :columns="licenseTable.data.columns"/>
          </div>
          <template #mobile>
            <LicensesLoaderMobile v-if="licenseTable.isLoading()"/>
            <LicenseTableMobile v-if="!licenseTable.isLoading()" :licenses="licenseTable.getTableData()" :columns="licenseTable.data.columns" />
          </template>
        </TableWrapper>
      </div>
    </UserCan>

    <ExportDialog
        v-model="isExportDialogVisible"
        :title="translate('Export Licenses')"
        :current-page-count="licenseTable.getTableData().length"
        :total-count="licenseTable.data.paginate.total"
        :item-label-singular="translate('license')"
        :item-label-plural="translate('licenses')"
        :has-active-filter="Boolean(licenseTable.isFiltering())"
        :export-state="exportInstance"
        @export="handleExport"
    />
  </div>
</template>
