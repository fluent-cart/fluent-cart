<template>
  <div class="fct-file-uploader-wrap">
    <template v-if="files.length > 0">
      <div class="fct-file-upload-rename" v-if="!uploading">
        <div class="fct-form-group">
          <label for="filename_input">{{ $t('File Name') }}</label>
          <el-input id="filename_input" v-model="fileName"/>

          <div style="display: flex; justify-content: space-between">

            <div>
              <span v-if="fileName.length > 160">
                {{
                  /* translators: %s - maximum filename length */
                  translate('File name Must not exceed %s characters', 160)
                }}
              </span>
            </div>

            <div>
              {{ fileName.length }}/160
            </div>

          </div>

        </div>
        <div class="fct-btn-group sm fct-file-upload-rename-actions">
          <el-button type="primary" @click="upload" :disabled="fileName.length > 160">{{ $t("Upload") }}</el-button>
          <IconButton @click.prevent="reset" tag="button">
            <DynamicIcon name="Delete"/>
          </IconButton>
        </div>
      </div>
    </template>

    <div :class="{'hidden': files.length > 0}">

      <div class="fct-form-group" v-if="storageDriverModel.hasBucket(driver)">
        <label>{{ translate('Select Bucket') }}</label>
        <el-select filterable v-model="activeBucket" :placeholder="translate('Select')"
                   popper-class="fct-bucket-list-popover">
          <el-option v-for="(bucket, index) in storageDriverModel.data.buckets[driver]" :key="index"
                     :label="bucket.label"
                     :value="bucket.value"/>
        </el-select>

      </div>
      <div v-if="!storageDriverModel.hasBucket(driver) ?
            false : !activeBucket
          " class="fct-file-upload-list-empty">{{ translate('Please select a bucket to upload files.') }}</div>

      <el-upload
          v-if="storageDriverModel.hasBucket(driver) ?
            activeBucket : true
          "
          class="fct-file-uploader"
          ref="uploaderRef"
          :data="getAdditionalRequestData()"
          v-model:file-list="files"
          drag
          :action="getFileUploadUrl()"
          :on-progress="onUploadProgress"
          :on-success="onUploaded"
          :on-error="(err, file, fileList)=>{

            const isTooLargeError = err.toString().includes('Request Entity Too Large');

            if(isTooLargeError){
              Notify.error(translate('File too large'));
            }else{
              Notify.error(translate('Cant upload the file'));
            }

            files = [];
            file = null;
            fileName = '';
            uploading = false;
            uploadProgress = 0;
            //
          }"
          :auto-upload="false"
          :on-change="onChange"
          :multiple="false"
          :limit="1"
          :show-file-list="false"
          :headers="getUploadHeaders()"
          :before-upload="beforeUpload"
      >
        <IconButton circle bg="primary" soft>
          <DynamicIcon name="Upload"/>
        </IconButton>
        <div class="el-upload__text">

          <span v-html="
          /* translators: %1$s - opening tag, %2$s - closing tag */
            translate('Drag & Drop or %1$s Browse/Upload %2$s Files', '<em>', '</em>')
            ">

          </span>

          <span>{{
              /* translators: %s - maximum upload size */
              translate('Any Format upto %s', Storage.serverMaxUploadSize())
            }}</span>
        </div>
      </el-upload>
    </div>

    <div v-if="uploading" class="fct-file-upload-progress">
      <div class="upload-progress-head">
        <IconButton bg="info" soft size="small">
          <DynamicIcon name="Files"/>
        </IconButton>
      </div>

      <div class="upload-progress-body">
        <div class="fct-file-upload-name" v-if="files.length > 0">
          {{ concatenateFileExtension(files[0].name) }}
        </div>
        <el-progress
            :stroke-width="5"
            :percentage="uploadProgress"
            striped
            striped-flow
            :duration="40"
        >
          <div class="fct-file-upload-text">
          <span v-if="files.length > 0">
            {{ formatFileSize(files[0].size * (uploadProgress / 100), files[0].size) }}
          </span>
            <span>{{
                /* translators: %s - upload progress */
                translate('%s% Uploaded', uploadProgress)
              }}</span>
          </div>
        </el-progress>
      </div>

      <div class="fct-file-upload-progress-cancel" @click="cancelUploadingFile">
        <DynamicIcon name="Close"/>
      </div>
    </div>
  </div>
</template>

<script setup>
import {ref} from "vue";
import {formatFileSize} from "@/Bits/common";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Storage from "@/utils/Storage";

const props = defineProps({
  driver: String,
  storageDriverModel: Object,
});
const emit = defineEmits(["onUploaded"]);
const activeBucket = ref();

const uploaderRef = ref(null);
const fileName = ref("");
const file = ref();
const files = ref([]);
const uploading = ref(false);
const uploadProgress = ref(0);
const getFileUploadUrl = () =>
    window.fluentCartRestVars.rest.url + "/files/upload";

const getAdditionalRequestData = () => {
  return {
    name: fileName.value,
    driver: props.driver,
    bucket: activeBucket.value,
  };
};

// Add this method to your component's methods section
const beforeUpload = (file) => {
  const maxFilenameLength = 160;

  // Check if filename exceeds maximum length
  if (file.name.length > maxFilenameLength) {
    Notify.error(translate(`Filename too long. Maximum ${maxFilenameLength} characters allowed`));
    files.value = [];
    file.value = null;
    fileName.value = "";
    uploading.value = false;
    uploadProgress.value = 0;

    // Return false to prevent upload
    return false;
  }

  // If validation passes, return true to allow upload
  return true;
}
const reset = (response) => {

  if (response?.message) {
    Notify.success(response.message);
  }
  // if (response === true) {
  // } else if (response?.message) {
  //   const errorParse = JSON.parse(response.message)
  //   if (errorParse?.message) {
  //     Notify.error(errorParse.message);
  //   }
  // }


  files.value = [];
  file.value = null;
  fileName.value = "";
  uploading.value = false;
  uploadProgress.value = 0;
};
//defineExpose have to be below the all methods, which will be exposed
defineExpose({reset});

const onChange = (selectedFile) => {
  file.value = selectedFile;
  //Remove the file extension from the name and use it
  fileName.value = selectedFile.name.replace(/\.[^/.]+$/, "");
};

const cancelUploadingFile = () => {
  uploaderRef.value.handleRemove(file.value);
  reset();
};
const onUploadProgress = (event, selectedFile, fileList) => {
  uploadProgress.value = parseInt(event.percent + "");
};

const onUploaded = (response, uploadFile, uploadFiles) => {
  reset(response);
  props.storageDriverModel?.fileUploaded?.(response);
  emit("onUploaded", response, uploadFile, uploadFiles);

};

const upload = () => {
  uploading.value = true;
  uploaderRef.value.submit();
};

// Method to concatenate file extension with base file name
const concatenateFileExtension = (file) => {
  return fileName.value + '.' + file.split('.').pop();
}

const getUploadHeaders = () => ({
  'X-WP-Nonce': Rest.getNonce(),
  // Add any other headers you need
  //'Accept': 'application/json',
  //'X-Requested-With': 'XMLHttpRequest'
});
</script>
