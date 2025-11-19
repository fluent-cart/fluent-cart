<template>
  <div class="fct-order-bulk-action-modal">
    <el-dropdown trigger="click" class="fct-more-option-wrap" popper-class="fct-dropdown" @command="handleCommandAction">
      <el-button v-if="triggerMode == 'button'" size="small">
        {{$t('More Action')}}
        <DynamicIcon name="ChevronDown"/>
      </el-button>
      <span v-else class="more-btn">
          <DynamicIcon name="More"/>
      </span>
      <template #dropdown>
        <el-dropdown-menu>
          <div v-if="trigger_action !== 'add'">
            <el-dropdown-item command="order_status" v-if="!isCancelled">
              <el-icon>
                <shopping-cart/>
              </el-icon>
              {{ $t('Change Order Status') }}
            </el-dropdown-item>
            <el-dropdown-item command="shipping_status" v-if="!isCancelled">
                <el-icon>
                    <box/>
                </el-icon>
                {{ $t('Change Shipping Status')}}
            </el-dropdown-item>
            <el-dropdown-item command="delete_order" v-if="isCancelled">
              <el-icon>
                <delete/>
              </el-icon>
              {{ $t('Delete order') }}
            </el-dropdown-item>
            <el-dropdown-item command="order_complete" v-if="!isCancelled">
              <el-icon><Check /></el-icon>
              {{ $t('Mark As Complete') }}
            </el-dropdown-item>
            <el-dropdown-item command="order_cancel" v-if="!isCancelled">
              <el-icon><Close /></el-icon>
              <span class="text-red-300" >{{ $t('Cancel Order') }}</span>
            </el-dropdown-item>
          </div>
        </el-dropdown-menu>
      </template>
    </el-dropdown>



    <el-dialog>

    </el-dialog>
    <el-dialog v-model="showModal" :title=" $t('Update Status')">
      <el-form :data="status_data" label-position="top">
        <el-form-item v-if="selected_action == 'order_status' || selected_action == 'all'" :label="$t('Order Status')">
          <el-select v-model="status_data.order_status" :placeholder="$t('Select Order Status')">
            <el-option
                v-for="(statusName, status) in appVars.editable_order_statues"
                :key="status"
                :label="statusName"
                :value="status"
            />
          </el-select>
        </el-form-item>
        <!-- <el-form-item
            v-if="selected_action == 'order_status' && ['cancelled','failed', 'processing', 'completed', 'pending'].includes(status_data.order_status)">
          <el-checkbox v-model="manage_stock" true-value="true" false-value="false">
            {{ $t('Manage Stock') }}
          </el-checkbox>
        </el-form-item> -->
        <el-form-item v-if="selected_action == 'shipping_status' || selected_action == 'all'"
                      :label="$t('Shipping Status')">
            <el-select v-model="status_data.shipping_status" :placeholder="$t('Select Shipping Status')">
                <el-option
                    v-for="(statusName, status) in appVars.shipping_statuses"
                    :key="status"
                    :label="statusName"
                    :value="status"
                />
            </el-select>
        </el-form-item>
        <el-form-item v-if="selected_action == 'payment_status'" :label="$t('Payment Status')">
          <el-select v-model="status_data.payment_status" :placeholder="$t('Select Payment Status')">
            <el-option
                v-for="(statusName, status) in appVars.payment_statuses"
                :key="status"
                :label="statusName"
                :value="status"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <span class="dialog-footer">
                <el-button @click="changeStatus()" type="primary">{{ $t('Update') }}</el-button>
            </span>
    </el-dialog>



  </div>
</template>

<script type="text/babel">
import {
  Box,
  Money,
  ShoppingCart,
  ArrowDown,
  Setting,
  Delete,
  DocumentAdd,
  Plus,
  Link,
  CreditCard,
  Check,
  Close
} from '@element-plus/icons-vue'
import {markRaw} from 'vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {handleSuccess, handleError} from "@/Bits/common";
import Notify from "@/utils/Notify";

export default {
  name: 'OrderBulkActions',
  components: {
    ArrowDown: markRaw(ArrowDown),
    Box: markRaw(Box),
    CreditCard: markRaw(CreditCard),
    ShoppingCart: markRaw(ShoppingCart),
    Setting: markRaw(Setting),
    Delete: markRaw(Delete),
    DocumentAdd: markRaw(DocumentAdd),
    Plus: markRaw(Plus),
    Link: markRaw(Link),
    DynamicIcon,
    Close,
    Check
  },
  emits: ['reload', 'addItem', 'addProduct', 'enableItemEditing', 'disableItemEditing', 'handlePaymentActions'],
  props: ['order', 'trigger_action', 'isEditingItem', 'mode', 'triggerMode'],
  computed: {
    isSingleProcessable() {
      return this.order.payment_status == 'paid'
          && this.order.shipping_status != 'shipped'
          && (this.order.status == 'processing' || this.order.status == 'on-hold');

    }
  },
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
      shouldDisableEditing: ['completed', 'archived', 'canceled'].includes(this.order.status),
      isCancelled: ['canceled'].includes(this.order.status),
      isUnshippable: ['unshippable'].includes(this.order.shipping_status),
    }
  },
  methods: {
    handleCommandAction(status) {
      if (status === 'order_complete') {

      } else if (status === 'shipping_status') {

      } else if (status === 'delete_order') {

      } else if (status === 'order_cancel') {

      } else {
        this.showModal = true;
      }
    },
    handleCommand(status) {
      if (status === 'disableItemEditing') {
        this.$emit('disableItemEditing');
      } else if (status === 'enableItemEditing') {
        this.$emit('enableItemEditing');
      } else if (status === 'delete_order') {
        this.$confirm('Are you sure you want to delete this order? This action can not be undone!',
            'Confirm Deleting',
            {
              confirmButtonText: 'Yes, Delete',
              cancelButtonText: 'Cancel',
              cancelButtonClass: 'el-button--info is-plain',
              type: 'warning'
            }
        )
            .then(() => {
              this.deleteOrder();
            })
            .catch(() => {

            });
        return false;
      } else if (status == 'add_product') {
        this.$emit('addProduct');
      } else if (status == 'add_item') {
        this.$emit('addItem');
      } else if (status === 'create_next_invoice') {
        this.$emit('handlePaymentActions', 'create_next_invoice');
      } 
      else {
        this.selected_action = status;
        this.showModal = true;
      }
    },
    changeStatus() {
      let statuses = false;
      if (this.selected_action == 'all') {
        statuses = this.status_data;
      } else if (this.selected_action == 'payment_status') {
        return this.updateBulkStatuses(this.status_data[this.selected_action]);
      } else if (this.selected_action == 'shipping_status') {
        this.manage_stock = true;
        statuses = {};
        statuses[this.selected_action] = this.status_data[this.selected_action];
      } else if (this.selected_action == 'order_status') {
        this.manage_stock = ['canceled'].includes(this.status_data[this.selected_action]) ? true : false;
        statuses = {};
        statuses[this.selected_action] = this.status_data[this.selected_action];
      } else {
        return false;
      }

      if (statuses.order_status === 'canceled') {
        this.$confirm('Are you sure you want to change the order status to ' + statuses.order_status + '? This action is irreversible and cannot be undone.',
        'Please confirm',
          {
            confirmButtonText: 'Yes, Changed status to ' + statuses.order_status,
            cancelButtonText: 'Cancel',
            type: 'warning'
          }
        )
        .then(() => {
          this.updateOrderStatuses(statuses);
        })
        .catch(() => {

        });
      } else {
        this.updateOrderStatuses(statuses);
      }
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
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            this.deleting = false;
          });
    },
    updateOrderStatuses(statuses) {
      this.doing_actions = true;

      this.$put('orders/' + this.order.id + '/statuses', {
        action: 'change_' + this.selected_action,
        statuses: statuses,
        manage_stock: this.manage_stock
      })
          .then(response => {
            handleSuccess(response.message);
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
    updateBulkStatuses(statuses) {
      this.$post('orders/do-bulk-action', {
        action: 'change_' + this.selected_action,
        new_status: statuses,
        order_ids: [this.order.id]
      })
          .then(response => {
            handleSuccess(response.message, '', 5000);
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
            this.showModal = false;
          });
    }
  }
}
</script>
