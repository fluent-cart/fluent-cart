<script setup>
import {computed, nextTick, onMounted, ref, watch} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Storage from "@/utils/Storage";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";

const props = defineProps({
  driver: String,
  buckets: Array,
  storageDriverModel: Object,
  multiple: {
    type: Boolean,
    default: true
  }
})

const fileList = ref([])
const loading = ref(false);

const getFileList = () => {
  loading.value = true;
  fileList.value = [];
  selectedFiles.value = [];
  onSelectFile([]);
  props.storageDriverModel.getFileList(props.driver, searchQuery.value)
      .then((data) => {
        fileList.value = data;

      })
      .catch((errors) => {
        Notify.error(errors);
      })
      .finally(() => {
        loading.value = false;
      });
}
const searchQuery = ref('');


const selectedFiles = ref([]);
const isFileSelected = ref(false);
const hasSelectionIssue = ref(false);
const onSelectFile = (selected) => {

  hasSelectionIssue.value = false; // Reset the flag

  const normalizedSelectedFiles = selected.map(file => {
    // Check file name length during the mapping
    if (file.name.length > 185) {
      hasSelectionIssue.value = true;
    }

    return {
      name: file.name,
      path: file.path || file.name,
      size: file.size,
      bucket: file.bucket,
      driver: file.driver
    };
  });

  selectedFiles.value = selected;
  isFileSelected.value = selectedFiles.value.length > 0;
  emit('onFileSelected', normalizedSelectedFiles, hasSelectionIssue.value);
}

const hasError = computed(() => {
  return fileList.value && fileList.value.errors && Object.keys(fileList.value.errors).length > 0;
});

const singleSelected = ref(null);

const getFileName = (name) => {
  if (name.includes('_____fluent-cart_____')) {
    return name.split('_____fluent-cart_____')[0];
  }
  return name.split('__fluent-cart__')[0];
}

watch(() => props.driver, () => {
  onSelectFile([])
})

const emit = defineEmits(['onFileSelected'])

const tableRef = ref(null);

const onFileUploaded = (response) => {
  loading.value = true;
  const file = response.file;

  fileList.value.unshift(file);

  onSelectFile(
      [
        ...selectedFiles.value,
        file
      ]
  )


  loading.value = false;
  nextTick(() => {
    // Manually select the newly uploaded file
    if (tableRef.value) {
      tableRef.value.toggleRowSelection(response.file, true);
    }
  })
}

onMounted(() => {
  if (!props.storageDriverModel.needsReconfigure(props.driver)) {
    getFileList();
  }
  props.storageDriverModel.onFileUploaded(onFileUploaded, props.driver);
})

const deleteFile = async (file, index) => {
  file.deleting = true;

  const response = await Rest.delete('files/delete', {
    file_path: file.path || file.name,
    driver: props.driver,
    bucket: file.bucket || ''
  }).then(response => {
    Notify.success(response.message);
    fileList.value.splice(index, 1);
    onSelectFile([])
  }).catch(error => {
    Notify.error(error.message);
  }).finally(() => {
    file.deleting = false;
  });
};
</script>

<template>

  <el-alert
    v-if="storageDriverModel.needsReconfigure(driver)"
    type="warning"
    :closable="false"
    :title="translate('S3_Needs_Reconfigured')"
    show-icon
  />

  <template v-if="!storageDriverModel.needsReconfigure(driver)">

    <div class="fct-file-upload-search-wrap">
      <fct-input v-model="searchQuery" class="fct-file-search-input" :placeholder="translate('Search and hit enter')" clearable
                 @keyup.enter="()=>{
                  getFileList()
              }"/>
    </div>

    <div v-if="loading">
      <fct-table :data="[1,2,3,4,5]">
        <fct-table-column type="selection" width="45"/>
        <fct-table-column :label="translate('File Name')">
          <fct-skeleton animated>
            <fct-skeleton variant="p" class="max-w-[190px]"/>
          </fct-skeleton>
        </fct-table-column>

        <fct-table-column :label="translate('Size')" width="150">
          <fct-skeleton animated>
            <fct-skeleton variant="p" class="max-w-[100px]"/>
          </fct-skeleton>
        </fct-table-column>
      </fct-table>
    </div>
    <div v-if="!loading" class="fct-file-upload-list">

      <fct-table
          v-if="!hasError"
          ref="tableRef"
          :data="fileList.filter(file => file.size > 0)"
          @selection-change="onSelectFile"
      >
        <!-- Multiple selection (checkboxes) -->
        <fct-table-column
            v-if="multiple"
            type="selection"
            width="45"
            :selectable="(row) => !row.deleting && row.size > 0"
        />

        <!-- Single selection (custom radio) -->
        <fct-table-column v-else label="" width="45">
          <template #default="scope">
            <fct-choice
                type="radio"
                v-model="singleSelected"
                :options="[{value: scope.row, disabled: scope.row.size == 0}]"
                @change="onSelectFile([scope.row])"
            />
          </template>
        </fct-table-column>

        <fct-table-column :label="translate('File Name')">
          <template #default="scope">
            <span class="file-name">{{ getFileName(scope.row.path || scope.row.name) }}</span>
          </template>
        </fct-table-column>

        <fct-table-column :label="translate('Size')" width="150">
          <template #default="scope">
            <span>{{ Storage.readableFileSizeFromBytes(scope.row.size) }}</span>
          </template>
        </fct-table-column>

        <fct-table-column :label="translate('Actions')" width="80">
          <template #default="scope">

            <fct-popover
                confirm
                :title="translate('Are you sure you want to delete this file? This action is not recoverable.')"
                :confirm-button-text="translate('Yes, Confirm!')"
                :cancel-button-text="translate('Cancel')"
                @confirm="deleteFile(scope.row, scope.index)"
                width="250"
                :z-index="99999"
            >
              <template #reference>
                <IconButton size="small" tag="button" :disabled="scope.row.deleting">
                  <DynamicIcon name="Delete"/>
                </IconButton>
              </template>
            </fct-popover>

          </template>
        </fct-table-column>

        <template #empty>
          <span>{{ translate('No file found.') }}</span>
        </template>
      </fct-table>
      <div v-else>
        <p>{{ translate('Error: Unable to load the file list.') }}</p>
      </div>
    </div>
  </template>


</template>
