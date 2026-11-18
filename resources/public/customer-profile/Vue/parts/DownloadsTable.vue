<template>
  <div class="fct-customer-dashboard-table">
    <fct-table :data="Object.values(downloads)" :show-header="showTableHeader"
      :aria-label="$t('Downloads table')">
      <fct-table-column :label="$t('File Name')">
        <template #default="scope">
          <div class="fct-customer-downloads-file-info">
            <!-- translators: %s is the file name -->
            <div class="filename" role="status" :aria-label="`${translate('File name: %s', scope.row?.title || 'N/A')}`">{{ scope.row?.title || 'N/A' }}</div>
            <div v-if="scope.row.file_size" class="text" role="status" :aria-label="`${translate('File size: %s', readableFileSize(scope.row?.file_size))}`">{{ readableFileSize(scope.row?.file_size) }}</div>
          </div>
        </template>
      </fct-table-column>

      <fct-table-column align="right">
        <template #default="scope">
          <a
              target="_blank"
              class="el-button el-button--x-small"
              :href="scope.row.download_url"
              :aria-label="`Download ${scope.row?.title || 'file'}`"
              rel="noopener noreferrer"
          >
            <DynamicIcon name="Download" aria-hidden="true"
              role="presentation"/>
            {{ _x('Download', 'Button action') }}
          </a>
        </template>
      </fct-table-column>
    </fct-table>
  </div>
</template>

<script type="text/babel">
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import Storage from "@/utils/Storage";
import translate, {_x} from "../../translator/Translator";

export default {
    components: {
        DynamicIcon
    },
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
    methods: {
      translate,
      _x,
        readableFileSize(size) {
          return Storage.readableFileSizeFromBytes(size);
        }
    }
};
</script>


