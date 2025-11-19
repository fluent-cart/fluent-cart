<script setup>
import {ref} from 'vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import { Loading } from '@element-plus/icons-vue';

const props = defineProps({
  selectedOrders: {
    type: Array,
    required: true
  },
  onComplete: {
    type: Function,
    default: () => {
    }
  }
});

const isDeleting = ref(false);
const progress = ref(0);
const showProgress = ref(false);
const showDialog = ref(false);
const deletedOrders = ref([]);
const currentDeletingOrder = ref('');

const resetState = () => {
  deletedOrders.value = [];
  progress.value = 0;
  currentDeletingOrder.value = '';
};

const deleteSingleOrder = (index = 0) => {

  if (!props.selectedOrders.length) {
    resetState();
    return;
  }

  // Check if we've processed all orders
  if (index > props.selectedOrders.length - 1) {
    setTimeout(() => {
      const successCount = deletedOrders.value.filter(order => order.status === 'success').length;
      const errorCount = deletedOrders.value.filter(order => order.status === 'error').length;

      if (successCount > 0) {
        Notify.success(`${successCount} orders deleted successfully`);
      }

      if (errorCount > 0) {
        Notify.error(`${errorCount} orders failed to delete`);
      }

      isDeleting.value = false;
      currentDeletingOrder.value = '';

      // showDialog.value = false;

      // Call completion callback after all operations
      // props.onComplete();
    }, 500);
    return;
  }

  const order = props.selectedOrders[index];
  const orderId = order.id;
  const invoiceNo = order.invoice_no;

  currentDeletingOrder.value = invoiceNo;

  // Update the current order status to 'processing'
  const orderIndex = deletedOrders.value.findIndex(item => item.id === orderId);

  // Skip API call for completed orders
  if (order.status === 'completed') {
    if (orderIndex !== -1) {
      deletedOrders.value[orderIndex].status = 'warning';
      deletedOrders.value[orderIndex].error_message = `Cannot delete order ${invoiceNo} due to status: completed`;
    }
    progress.value = Math.round(((index + 1) / props.selectedOrders.length) * 100);
    deleteSingleOrder(index + 1);
    return;
  }


  if (orderIndex !== -1) {
    deletedOrders.value[orderIndex].status = 'processing';
  }

  Rest.delete('/orders/'+orderId, {
    ids: [orderId] // Send single order ID as array
  }).then(response => {
    // Mark as successful
    if (orderIndex !== -1) {
      deletedOrders.value[orderIndex].status = 'success';
      deletedOrders.value[orderIndex].invoice_no = response.data.invoice_no;
    }
    // Update progress
    progress.value = Math.round(((index + 1) / props.selectedOrders.length) * 100);
    // Process next order
    deleteSingleOrder(index + 1);

  }).catch(errors => {
    console.log(`Error deleting order ${invoiceNo} (ID: ${orderId}):`, errors);

    // Mark as failed
    if (orderIndex !== -1) {
      deletedOrders.value[orderIndex].status = 'error';
      deletedOrders.value[orderIndex].error_message = errors && errors.message || 'Unknown error';
    }

    // Update progress even on error
    progress.value = Math.round(((index + 1) / props.selectedOrders.length) * 100);

    // Continue with next order even if current one fails
    deleteSingleOrder(index + 1);
  });
};

const handleBulkDelete = () => {
  if (!props.selectedOrders.length) return;

  isDeleting.value = true;
  showDialog.value = true;
  resetState();

  // Initialize the deletedOrders array with pending status
  deletedOrders.value = props.selectedOrders.map(order => ({
    id: order.id,
    invoice_no: order.invoice_no,
    parent_id: order.parent_id,
    status: order.status === 'completed' ? 'warning' : 'pending',
    error_message: order.status === 'completed' ? `${
      /* translators: %s is the order number */
      translate('Cannot delete order %s due to status: completed', order.invoice_no)
    }` : ''
  }));

  // Start the recursive deletion process
  deleteSingleOrder(0);
};

const getStatusIcon = (status) => {
  switch (status) {
    case 'success':
      return 'Check';
    case 'error':
      return 'Cross';
    case 'processing':
      return 'Loading';
    case 'warning':
      return 'Warning';
    default:
      return 'Clock';
  }
};

const getStatusClass = (status) => {
  switch (status) {
    case 'success':
      return 'text-green-500';
    case 'error':
      return 'text-red-500';
    case 'processing':
      return 'text-blue-500';
    case 'warning':
      return 'text-warning-500';
    default:
      return 'text-gray-400';
  }
};

const getStatusText = (status) => {
  switch (status) {
    case 'success':
      return translate('Success');
    case 'error':
      return translate('Failed');
    case 'processing':
      return translate('Processing...');
    case 'warning':
      return translate('Undeletable');
    default:
      return translate('Pending');
  }
};

const handleCloseModal = () => {
  showDialog.value = false;
  props.onComplete();
}
</script>

<template>
  <div class="fct-bulk-delete-action-wrap p-4 pl-5 pt-0">
    <el-popconfirm
        :title="translate('Are you sure you want to delete these orders?')"
        @confirm="handleBulkDelete"
        width="250"
    >
      <template #reference>
        <el-button
            type="danger"
            size="small"
            :loading="isDeleting"
            :disabled="isDeleting || !selectedOrders.length"
            class="flex items-center gap-2"
        >
          <template v-if="!isDeleting">
            <DynamicIcon name="Delete" class="w-4 h-4"/>
            {{
              /* translators: %s - number of selected orders */
              translate('Delete Selected (%s)', selectedOrders.length)
            }}
          </template>
          <template v-else>
            {{ translate('Deleting...') }}
          </template>
        </el-button>
      </template>
    </el-popconfirm>

    <!-- Progress Dialog -->
    <el-dialog
        v-model="showDialog"
        :title="translate('Deleting Orders')"
        width="800px"
        :close-on-click-modal="false"
        :show-close="false"
        :close-on-press-escape="false"
        modal-class="fct-bulk-delete-action-dialog"
    >
      <div class="fct-bulk-delete-action-dialog-content space-y-4">
        <div v-if="isDeleting">
          <p class="m-0 mb-2">
            {{ translate('Please do not close or refresh this window. Please wait while we delete the selected orders...') }}
          </p>
          <p class="text-sm text-gray-600 mb-3" v-if="currentDeletingOrder">
            {{
              /* translators: %s is the order number */
              translate('Currently processing: %s', currentDeletingOrder)
            }}
          </p>
        </div>
        <div v-else>
          <p class="m-0 mb-3">{{ translate('Deletion completed') }}</p>
        </div>

        <el-progress
            :percentage="progress"
            :stroke-width="6"
            striped
            striped-flow
            class="mb-4 !mt-0"
            :status="isDeleting ? undefined : 'success'"
        />

        <!-- Orders Status Table -->
        <div class="max-h-60 overflow-x-hidden">
          <el-table :data="deletedOrders" class="w-full" size="small">
            <el-table-column :label="translate('Invoice No.')" min-width="120">
              <template #default="scope">
                <span class="font-mono text-sm">{{ scope.row.invoice_no || scope.row.id }}</span>
              </template>
            </el-table-column>

            <el-table-column :label="translate('Status')" width="150" align="right">
              <template #default="scope">
                <div class="flex items-center justify-end gap-2">
                  <DynamicIcon
                      :name="getStatusIcon(scope.row.status)"
                      :class="['w-4 h-4 flex-none ', getStatusClass(scope.row.status)]"
                      v-if="scope.row.status !== 'processing'"
                  />
                  <el-icon class="is-loading text-blue-500" v-else>
                      <Loading />
                  </el-icon>
                  <span class="text-xs">
                    <LabelHint v-if="scope.row.status == 'error' || scope.row.status == 'warning'" :title="getStatusText(scope.row.status)" :content="scope.row.error_message" />
                    <template v-else>
                      {{ getStatusText(scope.row.status) }}
                    </template>
                  </span>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <!-- Summary -->
        <div v-if="!isDeleting && deletedOrders.length" class="!mt-2 p-3 bg-gray-50 dark:bg-primary-500 rounded">
          <div class="flex justify-between text-sm">
            <span>{{ translate('Total') }}: {{ deletedOrders.length }}</span>
            <span class="text-green-600">
              {{ translate('Success') }}: {{ deletedOrders.filter(o => o.status === 'success').length }}
            </span>
            <span class="text-red-600">
              {{ translate('Failed') }}: {{ deletedOrders.filter(o => o.status === 'error').length }}
            </span>
          </div>
        </div>

        <div v-if="deletedOrders.filter(o => o.status === 'warning').length">
          <p class="text-sm text-red-600">
            {{
              /* translators: %s is the number of completed orders */
              translate('We have found some completed orders that are not deletable (%s)', deletedOrders.filter(o => o.status === 'warning').length)
            }}
          </p>
        </div>
      </div>

      <template #footer>
        <div class="flex justify-end">
          <el-button
              @click="handleCloseModal()"
              :disabled="isDeleting"
              type="primary"
          >
            {{ isDeleting ? translate('Processing...') : translate('Done') }}
          </el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>
