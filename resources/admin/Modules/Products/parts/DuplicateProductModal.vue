<template>
  <el-dialog
      v-model="visible"
      :title="translate('Duplicate Product')"
      :append-to-body="true"
      width="500px"
      @close="handleClose"
      modal-class="fct-duplicate-product-modal"
  >
    <div class="duplicate-product-modal">
      <div class="product-info">
        <div class="info-text">
          {{ translate('Select which settings to import from') }}
          <strong>{{ productName }}</strong>
        </div>
      </div>

      <div class="options-wrapper">
        <el-checkbox
            v-model="options.importDownloadableFiles"
            :label="translate('Import Downloadable Files')"
            size="large"
        />

        <el-checkbox
            v-model="options.importLicenseSettings"
            :label="translate('Import License Settings')"
            size="large"
        />

        <template v-if="showStockManagement === 'yes'">
          <template v-if="isProActive">
            <el-checkbox
                v-model="options.importStockManagement"
                :label="translate('Import Stock Management')"
                size="large"
            />
          </template>
          <template v-else>
            <el-checkbox
                :disabled="true"
                size="large"
            >
              {{ translate('Import Stock Management') }}
              <DynamicIcon name="Crown" class="w-4 h-4 text-warning-500" />
            </el-checkbox>
          </template>
        </template>
      </div>

      <Alert
          type="warning"
          icon="InformationFill"
      >
        <span>{{ translate('The duplicated product will be created as a draft') }}</span>
      </Alert>
    </div>

    <template #footer>
      <div class="dialog-footer">
        <el-button @click="handleClose">
          {{ translate('Cancel') }}
        </el-button>
        <el-button
            type="primary"
            :loading="loading"
            @click="handleDuplicate"
        >
          {{ translate('Duplicate Product') }}
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, watch } from 'vue';
import translate from '@/utils/translator/Translator';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import Alert from "@/Bits/Components/Alert.vue";
import AppConfig from "@/utils/Config/AppConfig";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  },
  productId: {
    type: [Number, String],
    required: true
  },
  productName: {
    type: String,
    default: ''
  }
});

const emit = defineEmits(['update:modelValue', 'duplicated']);

const visible = ref(props.modelValue);
const loading = ref(false);

const options = ref({
  importStockManagement: true,
  importLicenseSettings: true,
  importDownloadableFiles: true
});

const isProActive = AppConfig.get('app_config.isProActive');
const showStockManagement = AppConfig.get('modules_settings.stock_management.active');

watch(() => props.modelValue, (newVal) => {
  visible.value = newVal;
  if (newVal) {
    // Reset options when modal opens
    options.value = {
      importStockManagement: true,
      importLicenseSettings: true,
      importDownloadableFiles: true
    };
  }
});

watch(visible, (newVal) => {
  emit('update:modelValue', newVal);
});

const handleClose = () => {
  visible.value = false;
};

const handleDuplicate = () => {
  loading.value = true;

  Rest.post(`products/${props.productId}/duplicate`, {
    import_stock_management: options.value.importStockManagement,
    import_license_settings: options.value.importLicenseSettings,
    import_downloadable_files: options.value.importDownloadableFiles
  })
      .then(response => {
        Notify.success(response.message || translate('Product duplicated successfully'));
        emit('duplicated');
        handleClose();
      })
      .catch(errors => {
        if (errors.status_code?.toString() === '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message || translate('Failed to duplicate product'));
        }
      })
      .finally(() => {
        loading.value = false;
      });
};
</script>

