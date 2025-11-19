<script setup>

import {getCurrentInstance, onMounted, ref, computed} from 'vue';
import FileUploader from "../Inputs/FileUploader.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import FileList from "@/Bits/Components/DownloadableFileSelector/FileList.vue";
import {useStorageDriverModel} from "@/Bits/Components/DownloadableFileSelector/StorageDriverModel";

const props = defineProps({
  isDisabled: String,
  uploadBtnText: {
    default: translate('Choose File')
  },
  uploadBtnIcon: {
    default: 'Upload'
  },
  uploadBtnSize: {
    type: String,
    default: 'large'
  },
  isMultiple: {
    type: Boolean,
    default: true
  }
})
const emit = defineEmits(['onUploaded', 'onFileSelected'])
const dialogVisible = ref(false)
const uploader = ref();
const selfRef = getCurrentInstance().ctx;
const bucketList = ref([])
const selectedDriver = ref('local')
const file = ref([])
const selectedFiles = []
const loading = ref(false);
const isFileSelected = ref(false);

const storageDriverModel = useStorageDriverModel();


onMounted(() => {
  storageDriverModel.getActiveDrivers();
})


const onTabChange = (selection) => {
  selectedDriver.value = selection;
  storageDriverModel.getBuckets(selection);
}


const onConfirm = () => {
  if (!selectedFiles.value || selectedFiles.value.length === 0) {
    selfRef.handleMessage(translate('Please select a file'), 'error', 'fct-file-upload-message');
    return;
  }

  // Reset the file array
  file.value = [];

  if (props.isMultiple) {
    // Multiple files
    selectedFiles.value.forEach((selectedFile) => {
      const filename = selectedFile.name;
      let title = filename.split('_____fluent-cart_____')[0];
      title = title.split('__fluent-cart__')[0];

      file.value.push({
        id: '',
        title: title,
        type: filename.slice(filename.lastIndexOf('.') + 1),
        driver: selectedFile.driver,
        bucket: selectedFile.bucket,
        file_name: filename,
        file_size: selectedFile.size,
        settings: {
          download_limit: '',
          download_expiry: '',
          bucket: selectedFile.bucket,
        },
        serial: ''
      });
    });
  } else {
    // Single file
    const selectedFile = selectedFiles.value[0];
    const filename = selectedFile.name;
    let title = filename.split('_____fluent-cart_____')[0];
    title = title.split('__fluent-cart__')[0];

    file.value.push({
      id: '',
      title: title,
      type: filename.slice(filename.lastIndexOf('.') + 1),
      driver: selectedFile.driver,
      bucket: selectedFile.bucket,
      file_name: filename,
      file_size: selectedFile.size,
      settings: {
        download_limit: '',
        download_expiry: '',
        bucket: selectedFile.bucket,
      },
      serial: ''
    });
  }

  dialogVisible.value = false;
  isFileSelected.value = false;
  emit('onFileSelected', file);
}

const selectedView = ref('list');

const hasSelectionIssue = ref(false);

const onSelectFile = (selected, hasIssue) => {
  selectedFiles.value = selected;
  isFileSelected.value = selectedFiles.value.length > 0;
  hasSelectionIssue.value = hasIssue;
}
</script>

<template>

  <div class="fct-driver-wrap">
    <el-button class="fct-upload-btn" type="info" plain @click="dialogVisible = true" :disabled="isDisabled"
               :size="uploadBtnSize">
      <DynamicIcon :name="uploadBtnIcon" v-if="uploadBtnIcon"/>
      {{ uploadBtnText }}

    </el-button>

    <el-dialog
        v-model="dialogVisible"
        :append-to-body="true"
        :title="translate('Storage Providers')"
        modal-class="fct-file-upload-dialog"
        @close="() => {
          // uploader.reset()
        }"
    >

      <div class="fct-driver-content">
          <span v-if="storageDriverModel.drivers.length < 1" class="empty-driver-text">
              {{ translate('No active storage provider found!') }} <router-link
              to="/settings/storage">{{ translate('Go to setting to active') }}</router-link>
          </span>

        <el-tabs v-if="storageDriverModel.drivers.length > 0" class="fct-driver-tabs" v-model="selectedDriver"
                 tab-position="left"
                 @tab-change="onTabChange">
          <el-tab-pane v-for="tab in storageDriverModel.drivers" :name="tab.route" :key="tab.route">
            <template #label>
              <div class="fct-driver-tabs-label">
                <DynamicIcon :name="tab.title === 'S3' ? 'FolderCloud' : 'Folder'"/>
                <span>{{ tab.title }}</span>
                <div class="fct-driver-arrow-icon">
                  <DynamicIcon name="ChevronRight"/>
                </div>
              </div>
            </template>

            <el-tabs v-model="selectedView" class="fct-file-tabs" tab-position="top">
              <el-tab-pane name="list">
                <template #label>
                  <div class="fct-driver-tabs-label">
                    {{ translate('Select Files') }}
                  </div>
                </template>
                <FileList
                    :storageDriverModel="storageDriverModel"
                    :buckets="bucketList"
                    :driver="tab.route"
                    @onFileSelected="(selected, hasIssue)=>{
                      onSelectFile(selected, hasIssue)
                    }"
                    :multiple="isMultiple"
                />

                <span v-if="hasSelectionIssue">
                  {{
                    /* translators: %s - maximum filename length */
                    translate('File name must not exceed %s characters', 160)
                  }}
                </span>

              </el-tab-pane>
              <el-tab-pane name="upload">

                <template #label>
                  <div class="fct-driver-tabs-label">
                    {{ translate('Upload') }}
                  </div>
                </template>

                <file-uploader
                    :storageDriverModel="storageDriverModel"
                    @on-uploaded="(response, uploadFile, uploadFiles)=>{
                      selectedView = 'list';
                    }"
                    :driver="tab.route"
                    :ref="tab.route === 'local' ? uploader : ''"
                />

              </el-tab-pane>
            </el-tabs>

          </el-tab-pane>
        </el-tabs>
      </div>

      <div class="dialog-footer" v-if="storageDriverModel.drivers.length > 0">
        <el-button @click="dialogVisible = false" type="info" soft>
          {{ translate('Cancel') }}
        </el-button>
        <el-button type="primary" @click="onConfirm" :disabled="!isFileSelected || hasSelectionIssue">
          {{ translate('Confirm') }}
        </el-button>
      </div>

    </el-dialog>
  </div>
</template>
