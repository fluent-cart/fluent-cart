<template>
  <el-dialog
      v-model="visible"
      :title="modalTitle"
      :append-to-body="true"
      width="500px"
      @close="handleClose"
      modal-class="fct-duplicate-product-modal"
  >
    <div class="product-action-modal">
      <!-- Warning Section (Delete Only) -->
      <Alert
          v-if="isDeleteAction"
          icon="WarningFill"
          type="error"
      >
        <template #content>
          <div class="fct-alert-content">
            <h3 class="fct-alert-title">{{ translate('Are you sure?') }}</h3>
            <p class="fct-alert-text">
              {{ translate('You are about to permanently delete') }}
              <strong>{{ productCount }} </strong>
              {{ translate(productCount === 1 ? 'product' : 'products') }}.
            </p>
            <p class="fct-alert-subtext">
              {{ translate('This action cannot be undone.') }}
            </p>
          </div>
        </template>
      </Alert>

      <!-- Info Section (Duplicate Only) -->
      <div v-if="isDuplicateAction" class="product-info">
        <div class="info-text">
          {{ translate(isBulk ? 'You are about to duplicate' : 'Select which settings to import from') }}
          <strong v-if="isBulk">{{ productCount }} </strong>
          <strong v-else>{{ productName }} </strong>
          <span v-if="isBulk">{{ translate(productCount === 1 ? 'product' : 'products') }}</span>
        </div>
      </div>

      <!-- Options Wrapper (Duplicate Only) -->
      <div v-if="isDuplicateAction" class="options-wrapper">
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

      <!-- Alert (Duplicate Only) -->
      <Alert
          v-if="isDuplicateAction"
          type="warning"
          icon="InformationFill"
      >
        <span>{{ translate(isBulk ? 'All duplicated products will be created as drafts' : 'The duplicated product will be created as a draft') }}</span>
      </Alert>

      <!-- Product List Preview (Bulk Delete Only) -->
      <div v-if="isDeleteAction && isBulk && !isProcessing && !showResults" class="products-preview">
        <p class="preview-title">{{ translate('Products to be deleted:') }}</p>
        <ul class="product-list">
          <li v-for="product in productsPreviewed" :key="product.ID" class="product-item">
            <span class="product-name">{{ product.post_title }}</span>
          </li>
          <li v-if="remainingCount > 0" class="remaining-count">
            {{ translate('... and %s more', remainingCount) }}
          </li>
        </ul>
      </div>

      <!-- Progress Section (Bulk Actions Only) -->
      <div v-if="isBulk && isProcessing" class="progress-section">
        <el-progress
            :percentage="progressPercentage"
            :status="progressStatus"
        />
        <p class="progress-text">
          {{ translate(isDeleteAction ? 'Deleting' : 'Duplicating') }} {{ processedCount }} / {{ productCount }}
        </p>
      </div>

      <!-- Results Section (Bulk Actions Only) -->
      <div v-if="isBulk && showResults" class="results-section">
        <el-alert
            v-if="successCount > 0"
            :title="translate(isDeleteAction ? 'Successfully deleted %s product(s)' : 'Successfully duplicated %s product(s)', successCount)"
            type="success"
            :closable="false"
            show-icon
        />
        <el-alert
            v-if="failedCount > 0"
            :title="translate(isDeleteAction ? 'Failed to delete %s product(s)' : 'Failed to duplicate %s product(s)', failedCount)"
            type="error"
            :closable="false"
            show-icon
            style="margin-top: 10px"
        />
      </div>
    </div>

    <template #footer>
      <div class="dialog-footer">
        <el-button @click="handleClose" :disabled="isProcessing">
          {{ isProcessing || showResults ? translate('Close') : translate('Cancel') }}
        </el-button>
        <el-button
            v-if="!showResults"
            :type="isDeleteAction ? 'danger' : 'primary'"
            :loading="loading || isProcessing"
            :disabled="loading || isProcessing"
            @click="handleAction"
        >
          <i v-if="isDeleteAction" class="el-icon-delete"></i>
          {{ actionButtonText }}
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import translate from '@/utils/translator/Translator';
import Rest from '@/utils/http/Rest';
import Notify from '@/utils/Notify';
import Alert from "@/Bits/Components/Alert.vue";
import AppConfig from "@/utils/Config/AppConfig";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import { WarnTriangleFilled } from '@element-plus/icons-vue';

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  },
  action: {
    type: String,
    required: true,
    validator: (value) => ['duplicate', 'delete'].includes(value)
  },
  // For single product
  productId: {
    type: [Number, String],
    default: null
  },
  productName: {
    type: String,
    default: ''
  },
  // For bulk actions
  products: {
    type: Array,
    default: () => []
  },
  productIds: {
    type: Array,
    default: () => []
  }
});

const emit = defineEmits(['update:modelValue', 'completed']);

const visible = ref(props.modelValue);
const loading = ref(false);
const isProcessing = ref(false);
const showResults = ref(false);
const processedCount = ref(0);
const successCount = ref(0);
const failedCount = ref(0);

const options = ref({
  importStockManagement: true,
  importLicenseSettings: true,
  importDownloadableFiles: true
});

const isProActive = AppConfig.get('app_config.isProActive');
const showStockManagement = AppConfig.get('modules_settings.stock_management.active');

// Computed properties
const isDuplicateAction = computed(() => props.action === 'duplicate');
const isDeleteAction = computed(() => props.action === 'delete');

const isBulk = computed(() => {
  return (props.products && props.products.length > 0) || (props.productIds && props.productIds.length > 0);
});

const productCount = computed(() => {
  if (props.products && props.products.length > 0) {
    return props.products.length;
  }
  if (props.productIds && props.productIds.length > 0) {
    return props.productIds.length;
  }
  return 1;
});

const modalTitle = computed(() => {
  if (isBulk.value) {
    return translate(isDuplicateAction.value ? 'Bulk Duplicate Products' : 'Bulk Delete Products');
  }
  return translate(isDuplicateAction.value ? 'Duplicate Product' : 'Delete Product');
});

const actionButtonText = computed(() => {
  if (isDeleteAction.value) {
    return translate(isBulk.value ? 'Delete Products' : 'Delete Product');
  }
  return translate(isBulk.value ? 'Duplicate Products' : 'Duplicate Product');
});

const productsPreviewed = computed(() => {
  return props.products.slice(0, 5);
});

const remainingCount = computed(() => {
  const remaining = productCount.value - 5;
  return remaining > 0 ? remaining : 0;
});

const progressPercentage = computed(() => {
  if (productCount.value === 0) return 0;
  return Math.round((processedCount.value / productCount.value) * 100);
});

const progressStatus = computed(() => {
  if (failedCount.value > 0 && processedCount.value === productCount.value) {
    return 'exception';
  }
  if (processedCount.value === productCount.value) {
    return 'success';
  }
  return undefined;
});

// Watchers
watch(() => props.modelValue, (newVal) => {
  visible.value = newVal;
  if (newVal) {
    // Reset state when modal opens
    options.value = {
      importStockManagement: true,
      importLicenseSettings: true,
      importDownloadableFiles: true
    };
    isProcessing.value = false;
    showResults.value = false;
    processedCount.value = 0;
    successCount.value = 0;
    failedCount.value = 0;
    loading.value = false;
  }
});

watch(visible, (newVal) => {
  emit('update:modelValue', newVal);
});

// Methods
const handleClose = () => {
  if (!isProcessing.value && !loading.value) {
    visible.value = false;
    if (showResults.value && successCount.value > 0) {
      emit('completed');
    } else if (!isBulk.value && !showResults.value) {
      // Single action completed
      emit('completed');
    }
  }
};

const handleAction = () => {
  if (isBulk.value) {
    handleBulkAction();
  } else {
    handleSingleAction();
  }
};

const handleSingleAction = () => {
  loading.value = true;

  if (isDuplicateAction.value) {
    handleSingleDuplicate();
  } else {
    handleSingleDelete();
  }
};

const handleSingleDuplicate = () => {
  Rest.post(`products/${props.productId}/duplicate`, {
    import_stock_management: options.value.importStockManagement,
    import_license_settings: options.value.importLicenseSettings,
    import_downloadable_files: options.value.importDownloadableFiles
  })
      .then(response => {
        Notify.success(response.message || translate('Product duplicated successfully'));
        emit('completed');
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

const handleSingleDelete = () => {
  Rest.delete(`products/${props.productId}`)
      .then(response => {
        Notify.success(response.message || translate('Product deleted successfully'));
        emit('completed');
        handleClose();
      })
      .catch(errors => {
        if (errors.status_code?.toString() === '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message || translate('Failed to delete product'));
        }
      })
      .finally(() => {
        loading.value = false;
      });
};

const handleBulkAction = async () => {
  isProcessing.value = true;
  processedCount.value = 0;
  successCount.value = 0;
  failedCount.value = 0;

  if (isDuplicateAction.value) {
    await handleBulkDuplicate();
  } else {
    await handleBulkDelete();
  }

  isProcessing.value = false;
  showResults.value = true;
  showNotification();
};

const handleBulkDuplicate = async () => {
  const requestData = {
    import_stock_management: options.value.importStockManagement,
    import_license_settings: options.value.importLicenseSettings,
    import_downloadable_files: options.value.importDownloadableFiles
  };

  const ids = props.productIds && props.productIds.length > 0 ? props.productIds : props.products.map(p => p.ID);

  for (const productId of ids) {
    try {
      await Rest.post(`products/${productId}/duplicate`, requestData);
      successCount.value++;
    } catch (error) {
      console.error(`Failed to duplicate product ${productId}:`, error);
      failedCount.value++;
    }
    processedCount.value++;
  }
};

const handleBulkDelete = async () => {
  const productsToDelete = props.products && props.products.length > 0 ? props.products : [];

  for (const product of productsToDelete) {
    try {
      await Rest.delete(`products/${product.ID}`);
      successCount.value++;
    } catch (error) {
      console.error(`Failed to delete product ${product.ID}:`, error);
      failedCount.value++;
    }
    processedCount.value++;
  }
};

const showNotification = () => {
  const actionText = isDuplicateAction.value ? 'duplicated' : 'deleted';

  if (successCount.value > 0 && failedCount.value === 0) {
    Notify.success({
      message: translate(`All products ${actionText} successfully`)
    });
  } else if (successCount.value > 0 && failedCount.value > 0) {
    Notify.warning({
      message: translate(`Some products were ${actionText} successfully`)
    });
  } else {
    Notify.error({
      message: translate(`Failed to ${actionText.replace('ed', '')} products`)
    });
  }
};
</script>

