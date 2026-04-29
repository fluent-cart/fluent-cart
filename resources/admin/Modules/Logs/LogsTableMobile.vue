<template>
  <div class="fct-table-mobile-wrap fct-logs-table-mobile-wrap">
    <div v-for="(row, rowIndex) in table.getTableData()" :key="row.id" class="fct-table-mobile-row">
      <div class="fct-table-mobile-header">
        <div class="fct-table-date-col">
          <div class="mark-read-unread-wrap">
            <span
                v-if="row.read_status !== 'unread'"
                @click="changeStatus(row, 'unread')"
                :title="translate('Mark as unread')"
            >
              <el-icon><CircleCheckFilled/></el-icon>
            </span>

            <span
                v-else
                @click="changeStatus(row, 'read')"
                :title="translate('Mark as read')"
              >
              <el-icon><CircleCheck/></el-icon>
            </span>
          </div>

          <div :title="
           /* translators: %s - log id */
          translate('Logs ID: %s', row.id)
          " class="invoice-no">
            #{{ row.id }}
          </div>

          <template v-if="table.isColumnVisible('date')">
            <span class="bullet">•</span>

            <ConvertedTime class="date" :date-time="row.created_at" :with-time="true"/>
          </template>
        </div>

      </div><!-- fct-table-mobile-header -->

      <div class="fct-table-mobile-body">
        <div class="fct-table-title-col" v-if="table.isColumnVisible('title')">
          {{ row.title }}
        </div>

        <div class="fct-table-content-col" v-if="table.isColumnVisible('content')">
          {{ row.content }}
        </div>
      </div><!-- fct-table-mobile-body -->

      <div class="fct-table-mobile-footer">
        <div class="fct-table-mobile-footer-row">
          <div class="fct-table-status-col">
            <div class="title">{{translate('Status')}}</div>
            <Badge :status="row.status" size="small" :key="row.id"/>
          </div>

          <div class="fct-table-module-col">
            <div class="title">{{translate('Module')}}</div>
            <div class="value">{{ row.module_name }}</div>
          </div>

          <div class="fct-table-actions-col" v-if="table.isColumnVisible('actions')">
            <div class="title">{{translate('Actions')}}</div>
            <router-link
                class="value"
                :to="{
                  name: 'view_order',
                  params: { order_id: row.module_id }
                }"
            >
              {{ translate('View Order') }}
            </router-link>
          </div>
        </div>
      </div><!-- fct-table-mobile-footer -->



    </div>
  </div>
</template>

<script setup>
import translate from "@/utils/translator/Translator";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import {CircleCheck, CircleCheckFilled} from "@element-plus/icons-vue";
import Badge from "@/Bits/Components/Badge.vue";


const props = defineProps({
  table: {
    type: Object
  }
});

const emit = defineEmits(['changeStatus']);

const changeStatus = (row, status) => {
  emit('changeStatus', row, status);
};
</script>
