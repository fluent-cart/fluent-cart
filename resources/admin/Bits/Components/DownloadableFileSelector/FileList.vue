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
  props.storageDriverModel.getFileList(props.driver, searchQuery.value, activeBucket.value)
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

  const selectedFiles = selected.map(file => {
    // Check file name length during the mapping
    if (file.name.length > 185) {
      hasSelectionIssue.value = true;
    }

    return {
      name: file.name,
      size: file.size,
      bucket: file.bucket,
      driver: file.driver
    };
  });

  selectedFiles.value = selected;
  isFileSelected.value = selectedFiles.value.length > 0;
  emit('onFileSelected', selectedFiles, hasSelectionIssue.value);
}

const hasError = computed(() => {
  return fileList.value && fileList.value.errors && Object.keys(fileList.value.errors).length > 0;
});

const activeBucket = ref('');
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

  //check if the uploaded file is in the same bucket
  if (file.bucket !== activeBucket.value) {
    activeBucket.value = file.bucket;
    fileList.value = [file];
  } else {
    fileList.value.unshift(file);
  }

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
  if(!props.storageDriverModel.hasBucket(props.driver)){
    getFileList();
  }
  props.storageDriverModel.onFileUploaded(onFileUploaded, props.driver);
})

const deleteFile = async (file, index) => {

  file.deleting = true;

  const response = await Rest.delete('files/delete', {
    file_path: file.name,
    driver: props.driver,
    bucket: activeBucket.value
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

  <div class="fct-form-group" v-if="storageDriverModel.hasBucket(driver)">
    <label>{{ translate('Select Bucket') }}</label>
    <el-select filterable clearable v-model="activeBucket" :placeholder="translate('Type to search')"
               popper-class="fct-bucket-list-popover" @change="getFileList" :disabled="loading">
      <el-option v-for="(bucket, index) in storageDriverModel.data.buckets[driver]" :key="index" :label="bucket.label"
                 :value="bucket.value"
                 :loading="storageDriverModel.isBucketLoading(driver)"
      />
    </el-select>
  </div>


  <template v-if="!storageDriverModel.hasBucket(driver) || (activeBucket && storageDriverModel.isBucketLoaded(driver))">

    <div class="fct-file-upload-search-wrap">
      <el-input v-model="searchQuery" class="fct-file-search-input" :placeholder="translate('Search and hit enter')" clearable
                 @keyup.enter="()=>{
                  getFileList()
              }"/>
    </div>

    <div v-if="loading">
      <el-table :data="[1,2,3,4,5]">
        <el-table-column type="selection" width="45"/>
        <el-table-column :label="translate('File Name')">
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item varient="p" class="max-w-[190px]"/>
            </template>
          </el-skeleton>
        </el-table-column>

        <el-table-column :label="translate('Size')" width="150">
          <el-skeleton animated>
            <template #template>
              <el-skeleton-item varient="p" class="max-w-[100px]"/>
            </template>
          </el-skeleton>
        </el-table-column>
      </el-table>
    </div>
    <div v-if="!loading" class="fct-file-upload-list">

      <el-table
          v-if="!hasError"
          ref="tableRef"
          :data="fileList"
          @selection-change="onSelectFile"
      >
        <!-- Multiple selection (checkboxes) -->
        <el-table-column
            v-if="multiple"
            type="selection"
            width="45"
            :selectable="(row) => !row.deleting"
            @selection-change="onSelectFile"
        />

        <!-- Single selection (custom radio) -->
        <el-table-column v-else label="" width="45">
          <template #default="scope">
            <el-radio
                v-model="singleSelected"
                :label="scope.row"
                @change="onSelectFile([scope.row])"
            />
          </template>
        </el-table-column>

        <el-table-column :label="translate('File Name')">
          <template #default="scope">
            <span>{{ getFileName(scope.row.name) }}</span>
          </template>
        </el-table-column>

        <el-table-column :label="translate('Size')" width="150">
          <template #default="scope">
            <span>{{ Storage.readableFileSizeFromBytes(scope.row.size) }}</span>
          </template>
        </el-table-column>

        <el-table-column :label="translate('Actions')" width="80">
          <template #default="scope">

            <el-popconfirm
                :title="translate('Are you sure you want to delete this file? This action is not recoverable.')"
                :confirm-button-text="translate('Yes, Confirm!')"
                :cancel-button-text="translate('Cancel')"
                @confirm="deleteFile(scope.row, scope.$index)"
                width="250"
                popper-class="fct-delete-confirm-popover"
            >
              <template #reference>
                <IconButton size="small" tag="button" :disabled="scope.row.deleting">
                  <DynamicIcon name="Delete"/>
                </IconButton>
              </template>
            </el-popconfirm>

          </template>
        </el-table-column>

        <template #empty>
          <span>{{ translate('No file found.') }}</span>
        </template>
      </el-table>
      <div v-else>
        <p>{{ translate('Error: Unable to load the file list.') }}</p>
      </div>
    </div>
  </template>

  <div v-if="!activeBucket && storageDriverModel.hasBucket(driver)" class="fct-file-upload-list-empty">
    {{ translate('Please select a bucket to view the files.') }}
  </div>

</template>
