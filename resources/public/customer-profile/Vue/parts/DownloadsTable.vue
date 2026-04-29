<template>
  <div class="fct-customer-dashboard-table">
    <el-table :data="Object.values(downloads)" :show-header="showTableHeader" role="grid"
      :aria-label="$t('Downloads table')">
      <el-table-column :label="$t('File Name')">
        <template #default="scope">
          <div class="fct-customer-downloads-file-info">
            <!-- translators: %s is the file name -->
            <div class="filename" role="status" :aria-label="`${translate('File name: %s', scope.row?.title || 'N/A')}`">{{ scope.row?.title || 'N/A' }}</div>
            <div v-if="scope.row.file_size" class="text" role="status" :aria-label="`${translate('File size: %s', readableFileSize(scope.row?.file_size))}`">{{ readableFileSize(scope.row?.file_size) }}</div>
          </div>
        </template>
      </el-table-column>

      <el-table-column align="right">
        <template #default="scope">
          <a
              target="_blank"
              class="el-button el-button--info is-plain el-button--x-small"
              :href="scope.row.download_url"
              :aria-label="`Download ${scope.row?.title || 'file'}`"
              rel="noopener noreferrer"
          >
            <DynamicIcon name="Download" aria-hidden="true"
              role="presentation"/>
            {{ $t('Download') }}
          </a>
        </template>
      </el-table-column>
    </el-table>
  </div>
</template>

<script type="text/babel">
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import Storage from "@/utils/Storage";
import translate from "../../translator/Translator";

export default {
    props: {
        downloads: {
            type: Array,
            default: () => []
        },
        showTableHeader: {
            type: Boolean,
            default: true
        }
    },
    components: {
        DynamicIcon
    },
    methods: {
      translate,
        readableFileSize(size) {
          return Storage.readableFileSizeFromBytes(size);
        }
    }
};
</script>


