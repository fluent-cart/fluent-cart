<template>
  <div class="fct-order-bulk-action-modal">
    <fct-dropdown trigger="click" class="fct-more-option-wrap" :items="actionItems" @command="handleCommandAction">
      <fct-button v-if="triggerMode === 'button'">
        {{ translate('More Action') }}
        <DynamicIcon name="ChevronDown"/>
      </fct-button>
      <span v-else class="more-btn">
          <DynamicIcon name="More"/>
      </span>
      <template #item="{item}">
        <a v-if="item.href" target="_blank" :href="item.href">
          <DynamicIcon :name="item.icon"/>
          {{ item.label }}
        </a>
        <template v-else>
          <DynamicIcon :name="item.icon"/>
          <fct-tooltip v-if="item.tooltip" placement="top" popper-class="fct-disabled-edit-order-tooltip" :content="item.tooltip">
            {{ item.label }}
          </fct-tooltip>
          <template v-else>{{ item.label }}</template>
        </template>
      </template>
    </fct-dropdown>

    <fct-dialog v-model="showModal" :title="translate('Update Shipping Status')">
      <div>
          <fct-select
              v-if="selected_action === 'shipping_status' || selected_action === 'all'"
              v-model="status_data.shipping_status"
              :label="translate('Shipping Status')"
              :placeholder="translate('Select Shipping Status')"
              :options="shippingStatusOptions"
          />
            <!--
              editable_shipping_statuses (not shipping_statuses) — `partially_shipped`
              is a DERIVED status, computed from the shipment records. Offering it here
              would let an admin hand-set it, which zeroes every item's fulfilled_quantity
              server-side while the shipment rows survive.
            -->
            <!--
              The order may currently sit in a derived status that is not settable
              (partially_shipped). Element Plus falls back to printing the raw slug
              when the bound value has no matching option, so surface it as a
              disabled option purely to give the select a label to render.
            -->
      </div>
      <span class="dialog-footer">
          <fct-button @click="changeShippingStatus()" type="primary">{{ translate('Update') }}</fct-button>
      </span>
    </fct-dialog>
  </div>
</template>

<script type="text/babel">
import {markRaw} from 'vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {handleSuccess, handleError} from "@/Bits/common";
import translate from "../../utils/translator/Translator";
import Notify from "@/utils/Notify";
import {FULFILLMENT_TYPE_PHYSICAL} from "@/utils/orderConstants";

export default {
  name: 'OrderBulkActions',
  components: {
    DynamicIcon,
  },
  props: ['order', 'trigger_action', 'isEditingItem', 'mode', 'triggerMode', 'shouldShowRefund', 'warningMessage'],
  emits: ['reload', 'addItem', 'addProduct', 'enableItemEditing', 'disableItemEditing', 'handlePaymentActions'],
  data() {
    return {
      selected_action: '',
      showModal: false,
      status_data: {
        order_status: this.order.status,
        shipping_status: this.order.shipping_status,
      },
      doing_actions: false,
      manage_stock: true,
      shouldDisableEditing: ["completed", "archived", "canceled"].includes(this.order.status),
      isCancelled: ['canceled'].includes(this.order.status),
      isCompleted: ['completed'].includes(this.order.status),
      isUnshippable: ['unshippable'].includes(this.order.shipping_status),
    }
  },
  computed: {
    actionItems() {
      if (this.trigger_action === 'add') {
        return [];
      }
      const editDisabled = !this.isEditingItem && this.shouldDisableEditing;
      return [
        !this.isCancelled && this.isPhysicalOrder && {command: 'shipping_status', icon: 'ShipmentStatus', label: translate('Change Shipping Status')},
        (this.order.mode === 'test' || this.isCancelled) && {command: 'delete_order', icon: 'Delete', label: translate('Delete order'), danger: true},
        this.order.status === 'processing' && {command: 'order_complete', icon: 'Check', label: translate('Mark As Complete')},
        !this.isCancelled && {command: 'order_cancel', icon: 'Cross', label: translate('Cancel Order')},
        this.order.status === 'completed' && {command: 'order_processing', icon: 'Refresh', label: translate('Back to processing')},
        !this.isCompleted && !this.isCancelled && {command: 'sync_order_statuses', icon: 'Refresh', label: translate('Sync Order Statuses')},
        {command: 'order_receipt', icon: 'External', label: translate('Receipt'), href: this.order.receipt_url},
        this.order?.has_missing_licenses && {command: 'generate_missing_licenses', icon: 'Files', label: translate('Generate missing licenses')},
        this.shouldShowRefund && {command: 'refund_order', icon: 'Money', label: translate('Refund'), class: 'bulk-action-only-mobile'},
        {
          command: {action: 'order_edit', isEditingItem: this.isEditingItem},
          icon: 'Edit',
          label: this.isEditingItem ? translate('Disable Editing') : translate('Edit'),
          tooltip: editDisabled ? this.warningMessage : '',
          class: 'bulk-action-only-mobile',
          disabled: this.shouldDisableEditing,
        },
      ].filter(Boolean);
    },
    isPhysicalOrder() {
      return this.order?.fulfillment_type === FULFILLMENT_TYPE_PHYSICAL;
    },
    shippingStatusOptions() {
      // editable_shipping_statuses, not shipping_statuses: `partially_shipped` is derived from the
      // shipment records, and hand-setting it zeroes every item's fulfilled_quantity server-side.
      const current = this.status_data.shipping_status;
      const options = Object.entries(this.appVars.editable_shipping_statuses).map(([status, statusName]) => ({label: statusName, value: status}));
      // A current derived status has no editable option; show it disabled so the select prints its label, not the slug.
      if (current && !this.appVars.editable_shipping_statuses[current]) {
        options.unshift({label: this.appVars.shipping_statuses[current] || current, value: current, disabled: true});
      }
      return options;
    },
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
      } else if(status === 'sync_order_statuses') {
        this.syncOrderStatuses();
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
            this.$router.replace({
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
      this.$confirm(translate('Order_Sure_Want'),
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
    },
    syncOrderStatuses() {
      this.doing_actions = true;
      this.$put('orders/' + this.order.id + '/sync-statuses')
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
        });
    }
  }
}
</script>
