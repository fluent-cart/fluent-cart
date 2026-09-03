<script setup>
import useLicenseSiteTable from "@/utils/table-new/LicenseSiteTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import SiteTableComponent from "./_SiteTable.vue";
import SitesLoader from "./SitesLoader.vue";
import SitesLoaderMobile from "./SitesLoaderMobile.vue";
import UserCan from "../../admin/Bits/Components/Permission/UserCan.vue";
import translate from "@/utils/translator/Translator";
import {ArrowRight} from "@element-plus/icons-vue";

const siteTable = useLicenseSiteTable();
</script>

<template>
  <div class="fct-all-sites-page fct-layout-width">
    <div class="single-page-header">
      <el-breadcrumb :separator-icon="ArrowRight">
        <el-breadcrumb-item :to="{ name: 'licenses' }">{{ translate('Licenses') }}</el-breadcrumb-item>
        <el-breadcrumb-item>{{ translate('Sites') }}</el-breadcrumb-item>
      </el-breadcrumb>
    </div>

    <UserCan permission="licenses/view">
      <div class="fct-all-sites-wrap">
        <TableWrapper :table="siteTable" :classicTabStyle="true" :has-mobile-slot="true">
          <SitesLoader v-if="siteTable.isLoading()" :siteTable="siteTable" :next-page-count="siteTable.nextPageCount"/>
          <div v-else>
            <SiteTableComponent :sites="siteTable.getTableData()" :columns="siteTable.data.columns"/>
          </div>
          <template #mobile>
            <SitesLoaderMobile v-if="siteTable.isLoading()"/>
            <SiteTableComponent v-if="!siteTable.isLoading()" :sites="siteTable.getTableData()" :columns="siteTable.data.columns"/>
          </template>
        </TableWrapper>
      </div>
    </UserCan>
  </div>
</template>
