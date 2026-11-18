<template>
  <div class="fct-all-logs-page fct-layout-width">
    <PageHeading :title="translate('Logs')"/>

    <TableWrapper :table="logTable" :classicTabStyle="true" :has-mobile-slot="true">
      <LogsLoader
          v-if="logTable.isLoading()"
          :logTable="logTable"
          :next-page-count="logTable.nextPageCount"
      />

      <div v-else>
        <fct-table class="w-full" :data="logTable.getTableData()">

          <fct-table-column :label="translate('ID')" :width="100">
            <template #default="scope">
              <div class="inline-flex items-center gap-1">
                <span
                    v-if="scope.row.read_status !== 'unread'"
                    @click="changeStatus(scope.row, 'unread')"
                    :title="translate('Mark as unread')"
                    class="cursor-pointer"
                >
                  <el-icon><CircleCheckFilled/></el-icon>
                </span>

                <span
                    v-else
                    @click="changeStatus(scope.row, 'read')"
                    :title="translate('Mark as read')"
                    class="cursor-pointer"
                >
                  <el-icon><CircleCheck/></el-icon>
                </span>

                #{{ translateNumber(scope.row.id) }}
              </div>
            </template>
          </fct-table-column>

          <fct-table-column v-if="logTable.isColumnVisible('date')" :label="translate('Date')" :width="120">
            <template #default="scope">
              <ConvertedTime :date-time="scope.row.created_at"/>
            </template>
          </fct-table-column>

          <fct-table-column v-if="logTable.isColumnVisible('title')" :label="translate('Title')" :width="200">
            <template #default="scope">
              {{ scope.row.title }}
            </template>
          </fct-table-column>

          <fct-table-column v-if="logTable.isColumnVisible('content')" :label="translate('Content')" :width="200">
            <template #default="scope">
              {{ scope.row.content }}
            </template>
          </fct-table-column>

          <fct-table-column :label="translate('Status')" :width="100">
            <template #default="scope">
              <Badge :status="scope.row.status" kind="log" size="small" :key="scope.row.id"/>
            </template>
          </fct-table-column>

          <fct-table-column :label="translate('Module')" :width="100">
            <template #default="scope">
              {{ scope.row.module_name }}
            </template>
          </fct-table-column>

          <fct-table-column v-if="logTable.isColumnVisible('actions')" :label="translate('Actions')" :width="80">
            <template #default="scope">
              <fct-button
                  v-if="scope.row.module_id && scope.row.module_name.toLowerCase() === 'order'"
                  size="x-small"
                  :to="{
                    name: 'view_order',
                    params: { order_id: scope.row.module_id }
                  }"
              >
                {{ translate('View') }}
              </fct-button>
              <fct-button
                  v-if="scope.row.module_id && scope.row.module_name.toLowerCase() === 'subscription'"
                  size="x-small"
                  :to="{
                    name: 'view_subscription',
                    params: { subscription_id: scope.row.module_id }
                  }"
              >
                {{ translate('View') }}
              </fct-button>
            </template>
          </fct-table-column>

          <template #empty>
            <Empty icon="Empty/ListView" :has-dark="true" :text="translate('We could\'t find any logs matching your filter.')"/>
          </template>

        </fct-table>
      </div>

      <template #mobile>
        <LogsTableLoaderMobile v-if="logTable.isLoading()"/>
        <LogsTableMobile v-if="!logTable.isLoading()" :table="logTable" @change-status="changeStatus"/>
      </template>
    </TableWrapper>
  </div>
</template>

<script setup>
import useLogTable from "@/utils/table-new/LogTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import {getCurrentInstance, ref} from "vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Badge from "@/Bits/Components/Badge.vue";
import {CircleCheck, CircleCheckFilled} from "@element-plus/icons-vue";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import LogsTableMobile from "@/Modules/Logs/LogsTableMobile.vue";
import LogsTableLoaderMobile from "@/Modules/Logs/LogsTableLoaderMobile.vue";
import Notify from "@/utils/Notify";
import {translateNumber} from "@/utils/translator/Translator";
import translate from "@/utils/translator/Translator";
import LogsLoader from "@/Modules/Logs/LogsLoader.vue";

const selfRef = getCurrentInstance().ctx;
const logTable = useLogTable();
const logs = ref([]);


const changeStatus = (row, status) => {
  selfRef.$put("activity/" + row.id + '/mark-read', {
    id: row.id,
    status: status
  })
      .then(res => {
        logs.value.find(log => log.id === row.id).read_status = status;
        selfRef.handleSuccess(res);
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      });
};

logTable.onDataLoaded(function () {
  logs.value = logTable.getTableData();
});

</script>
