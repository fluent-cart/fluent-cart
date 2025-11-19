<template>
  <div class="fct-order-bulk-action-modal">
    <el-dropdown trigger="click" class="fct-more-option-wrap" popper-class="fct-dropdown"
                 @command="handleCommandAction">
      <el-button v-if="triggerMode === 'button'">
        {{ translate('More Action') }}
        <DynamicIcon name="ChevronDown"/>
      </el-button>
      <span v-else class="more-btn">
          <DynamicIcon name="More"/>
      </span>
      <template #dropdown>
        <el-dropdown-menu>
          <div v-if="trigger_action !== 'add'">
            <el-dropdown-item command="shipping_status" v-if="!isCancelled && order?.fulfillment_type === 'physical'">
              <DynamicIcon name="ShipmentStatus"/>
              {{ translate('Change Shipping Status') }}
            </el-dropdown-item>
            <el-dropdown-item command="delete_order" v-if="isCancelled" class="item-destructive">
              <DynamicIcon name="Delete"/>
              {{ translate('Delete order') }}
            </el-dropdown-item>
            <el-dropdown-item command="order_complete" v-if="order.status === 'processing'">
              <DynamicIcon name="Check"/>
              {{ translate('Mark As Complete') }}
            </el-dropdown-item>
            <el-dropdown-item command="order_cancel" v-if="!isCancelled">
              <DynamicIcon name="Cross"/>
              {{ translate('Cancel Order') }}
            </el-dropdown-item>
            <el-dropdown-item command="order_processing" v-if="order.status === 'completed'">
              <DynamicIcon name="Refresh"/>
              {{ translate('Back to processing') }}
            </el-dropdown-item>
            <el-dropdown-item command="order_receipt" class="item-link">
              <a target="_blank" :href="order.receipt_url">
                <DynamicIcon name="External"/>
                {{ translate('Receipt') }}
              </a>
            </el-dropdown-item>
            <el-dropdown-item command="generate_missing_licenses" v-if="order?.has_missing_licenses">
              <DynamicIcon name="Files"/>
              {{ translate('Generate missing licenses') }}
            </el-dropdown-item>

            <el-dropdown-item command="refund_order" class="bulk-action-only-mobile" v-if="shouldShowRefund">
              <DynamicIcon name="Money"/>
              {{ translate('Refund') }}
            </el-dropdown-item>

            <el-dropdown-item :command="{
                action: 'order_edit',
                isEditingItem: isEditingItem
            }" class="bulk-action-only-mobile" :disabled="shouldDisableEditing">
              <DynamicIcon name="Edit"/>
              <template v-if="!isEditingItem">
                <template v-if="shouldDisableEditing">
                  <el-tooltip
                      placement="top"
                      popper-class="fct-tooltip fct-disabled-edit-order-tooltip"
                  >
                    <template #content>
                      {{ warningMessage }}
                    </template>
                    {{ translate("Edit") }}
                  </el-tooltip>
                </template>

                <template v-else>
                  {{ translate("Edit") }}
                </template>
              </template>

              <template v-else>
                {{ translate("Disable Editing") }}
              </template>
            </el-dropdown-item>



          </div>
        </el-dropdown-menu>
      </template>
    </el-dropdown>

    <el-dialog v-model="showModal" :title="translate('Update Shipping Status')">
      <el-form :data="status_data" label-position="top">
        <el-form-item v-if="selected_action === 'shipping_status' || selected_action === 'all'"
                      :label="translate('Shipping Status')">
          <el-select v-model="status_data.shipping_status" :placeholder="translate('Select Shipping Status')">
            <el-option
                v-for="(statusName, status) in appVars.shipping_statuses"
                :key="status"
                :label="statusName"
                :value="status"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <span class="dialog-footer">
          <el-button @click="changeShippingStatus()" type="primary">{{ translate('Update') }}</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script type="text/babel">
import {markRaw} from 'vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {handleSuccess, handleError} from "@/Bits/common";
import translate from "../../utils/translator/Translator";
import Notify from "@/utils/Notify";

export default {
  name: 'OrderBulkActions',
  components: {
    DynamicIcon,
  },
  emits: ['reload', 'addItem', 'addProduct', 'enableItemEditing', 'disableItemEditing', 'handlePaymentActions'],
  props: ['order', 'trigger_action', 'isEditingItem', 'mode', 'triggerMode', 'shouldShowRefund', 'warningMessage'],
  data() {
    return {
      selected_action: '',
      showModal: false,
      status_data: {
        order_status: this.order.status,
        shipping_status: this.order.shipping_status,
        payment_status: this.order.payment_status,
      },
      doing_actions: false,
      manage_stock: true,
      shouldDisableEditing: ["completed", "archived", "canceled"].includes(this.order.status),
      isCancelled: ['canceled'].includes(this.order.status),
      isCompleted: ['completed'].includes(this.order.status),
      isUnshippable: ['unshippable'].includes(this.order.shipping_status),
    }
  },
  methods: {
    translate,
    handleCommandAction(status) {
      this.selected_action = status;
      if (status === 'shipping_status') {
        this.changeShipping()
      } else if (status === 'delete_order') {
        this.confirmDeleteOrder();
      } else if (status === 'order_complete') {
        this.selected_action = 'order_status';
        this.completeOrder();
      } else if (status === 'order_cancel') {
        this.selected_action = 'order_status';
        this.cancelOrder();
      } else if (status === 'order_processing') {
        this.selected_action = 'order_status';
        this.backToProcessing();
      } else if (status === 'order_receipt') {
        this.showModal = false;
      } else if(status === 'generate_missing_licenses') {
        this.generateMissingLicenses();
      } else if(status === 'refund_order') {
        this.$emit('handlePaymentActions', 'refund');
      } else if(status.action === 'order_edit') {
        if(!status.isEditingItem) {
          if(this.shouldDisableEditing) {
            return;
          }
          this.$emit('enableItemEditing');
        } else {
          this.$emit('disableItemEditing');
        }
      } else {
        this.showModal = true;
      }
    },
    confirmDeleteOrder() {
      this.$confirm(translate('Are you sure you want to delete this order? This action can not be undone!'),
          translate('Confirm Deleting'),
          {
            confirmButtonText: translate('Yes, Delete'),
            cancelButtonText: translate('Cancel'),
            cancelButtonClass: 'el-button--info is-plain',
            type: 'warning'
          }
      )
          .then(() => {
            this.deleteOrder();
          })
    },
    deleteOrder() {
      this.deleting = true;
      this.$del('orders/' + this.order.id)
          .then(response => {
            handleSuccess(response.message);
            this.$router.push({
              name: 'orders'
            });
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors?.data);
            }
          })
          .finally(() => {
            this.deleting = false;
          });
    },
    changeShipping() {
      this.showModal = true;
    },
    completeOrder() {
      this.$confirm(translate('Are you sure you want to complete this order!'),
          translate('Please confirm'),
          {
            confirmButtonText: translate('Yes, Mark order as complete!'),
            cancelButtonText: translate('Cancel'),
            type: 'warning'
          }
      )
          .then(() => {
            this.updateOrderStatuses({order_status: 'completed'});
          })
    },
    generateMissingLicenses() {
      this.$confirm(translate('Are you sure you want to generate missing licenses!'),
          translate('Please confirm'),
          {
            confirmButtonText: translate('Yes, Generate all missing licenses!'),
            cancelButtonText: translate('Cancel'),
            type: 'warning'
          }
      )
          .then(() => {
            this.generateLicenses();
          })
    },
    generateLicenses() {
      this.doing_actions = true;
      this.$post('orders/' + this.order.id + '/generate-missing-licenses', {
      })
          .then(response => {
            Notify.success(response.message)
            this.$emit('reload');
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            this.doing_actions = false;
            this.showModal = false;
          });
    },
    backToProcessing() {
      this.$confirm(translate('Are you sure you want to revert back processing this order!'),
          translate('Please confirm'),
          {
            confirmButtonText: translate('Yes, Mark order as processing!'),
            cancelButtonText: translate('Cancel'),
            type: 'warning'
          }
      )
          .then(() => {
            this.updateOrderStatuses({order_status: 'processing'});
          })
    },
    cancelOrder() {
      this.$confirm(translate('Are you sure you want to change the order status to Cancel? This action is irreversible and cannot be undone.'),
          translate('Please confirm'),
          {
            confirmButtonText: translate('Yes, Changed status to Cancel'),
            cancelButtonText: translate('Cancel'),
            type: 'warning'
          }
      )
          .then(() => {
            this.manage_stock = true;
            this.updateOrderStatuses({order_status: 'canceled'});
          })
    },
    changeShippingStatus() {
      let statuses = {};
      this.manage_stock = true;
      statuses[this.selected_action] = this.status_data[this.selected_action];
      this.updateOrderStatuses(statuses);
    },
    updateOrderStatuses(statuses) {
      this.doing_actions = true;
      this.$put('orders/' + this.order.id + '/statuses', {
        action: 'change_' + this.selected_action,
        statuses: statuses,
        manage_stock: this.manage_stock
      })
          .then(response => {
            Notify.success(response.message)
            this.$emit('reload');
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            this.doing_actions = false;
            this.showModal = false;
          });
    }
  }
}
</script>
