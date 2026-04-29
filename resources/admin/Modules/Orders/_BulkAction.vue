<template>
  <div class="bulks-wrap">
    <div class="fct-bulk-actions">
      <el-select v-model="selected_action" :placeholder="$t('Bulk Actions')" clearable>
        <template v-for="(item, index) in bulkOptions" :key="index">
          <el-option
              :label="item.label"
              :value="item.value"
              v-if="checkBulkRender(item.value)"
          />
        </template>
      </el-select>

      <el-button @click="handleBulkAction" plain>
        {{ $t('Apply') }}
      </el-button>
    </div>

    <!-- <ul class="table-filter-bulks-actions">
        <li v-if="shortActions.shippables">
            <div class="bulk-mark-shipped">
                <button @click="initShippingStatusChange('shipped')">
                    <el-icon>
                        <Box/>
                    </el-icon>
                    {{ $t('Mark as Shipped')}} ({{ shortActions.shippables }} {{shortActions.shippables > 1 ? $t('orders') : $t('order')}})
                </button>
            </div>
        </li>
        <li v-if="shortActions.completables">
            <div class="bulk-mark-completed">
                <button @click="initOrderStatusChange('completed')">
                    <el-icon>
                        <ShoppingCart/>
                    </el-icon>
                    {{ $t('Mark as Completed')}} ({{ shortActions.completables }} {{shortActions.completables > 1 ? $t('orders') : $t('order')}})
                </button>
            </div>
        </li>
        <li v-if="shortActions.capturables">
            <div class="bulk-payment">
                <button @click="initCapturePayments()">
                    <el-icon>
                        <Money/>
                    </el-icon>
                    {{ $t('Capture')}} ( {{ shortActions.capturables }} {{shortActions.capturables > 1 ? $t('payments') : $('payment')}} )
                </button>
            </div>
        </li>
        <li>
            <el-dropdown @command="handleBulkCommand" trigger="click">
                <div class="bulk-more-action">
                    <button>
                        <el-icon>
                            <MoreFilled/>
                        </el-icon>
                        {{$t('Bulk Actions')}}
                    </button>
                </div>
                <template #dropdown>
                    <el-dropdown-menu>
                        <el-dropdown-item command="order_status">
                            <el-icon>
                                <shopping-cart/>
                            </el-icon>
                            {{$t('Change Order Status')}}
                        </el-dropdown-item>
                        <el-dropdown-item command="shipping_status">
                            <el-icon>
                                <box/>
                            </el-icon>
                            {{ $t('Change Shipping Status')}}
                        </el-dropdown-item>
                        <el-dropdown-item v-if="appVars.allow_bulk_payment_status_change" command="payment_status">
                            <el-icon>
                                <money/>
                            </el-icon>
                            {{ $t('Change Payment Status')}}
                        </el-dropdown-item>
                        <el-dropdown-item command="delete_orders">
                            <el-icon>
                                <delete/>
                            </el-icon>
                            {{ $t('Delete selected')}} {{selectedOrders.length > 1 ? $t('orders') : $t('order')}}
                        </el-dropdown-item>
                    </el-dropdown-menu>
                </template>
            </el-dropdown>
        </li>
    </ul> -->

    <!-- <el-dialog :show-close="false"
               v-model="doing_bulk_actions"
               :append-to-body="true"
               :close-on-click-modal="false"
               :close-on-press-escape="false"
               :title="bulk_action_settings.title">
        <div class="text-align-center">
            <template v-if="completed_message">
                <h3>{{ completed_message }}</h3>
                <el-button type="success" @click="reset()">{{ $t('Awesome')}}</el-button>
            </template>
            <div style="text-align: left;" v-else-if="process_errors">
                <h3>{{ $t('Error occurred when processing')}}</h3>
                <pre>{{process_errors}}</pre>
                <el-button type="primary" @click="reset()">{{$t('OK')}}</el-button>
            </div>
            <template v-else>
                <h3>{{ bulk_action_settings.message }}</h3>
                <el-progress
                    :percentage="parseInt((bulk_action_settings.completed / bulk_action_settings.total) * 100) || 10"
                    :indeterminate="true"/>
            </template>
        </div>
    </el-dialog> -->

    <el-dialog :append-to-body="true" v-model="showStatusModal"
               :title="`${$t('Update')} ${(selected_action == 'order_status') ? $t('Order') : (selected_action == 'shipping_status') ? $t('Shipping') : (selected_action == 'payment_status') ? $t('Payment') : ''} ${$t('Status')}`"
               width="34%">
      <el-form label-position="top">
        <el-form-item v-if="selected_action == 'order_status'" :label="$t('Order Status')">
          <el-select v-model="new_status" :placeholder="$t('Select Order Status')">
            <el-option
                v-for="(statusName, status) in appVars.editable_order_statues"
                :key="status"
                :label="statusName"
                :value="status"
            />
          </el-select>
        </el-form-item>
        <el-form-item v-if="selected_action == 'shipping_status' " :label="$t('Shipping Status')">
          <el-select v-model="new_status" :placeholder="$t('Select Order Status')">
            <el-option
                v-for="(statusName, status) in appVars.shipping_statuses"
                :key="status"
                :label="statusName"
                :value="status"
            />
          </el-select>
        </el-form-item>
        <el-form-item v-if="selected_action == 'payment_status' " :label="$t('Payment Status')">
          <el-select v-model="new_status" :placeholder="$t('Select Payment Status')">
            <el-option
                v-for="(statusName, status) in appVars.editable_payment_statuses"
                :key="status"
                :label="statusName"
                :value="status"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <div class="dialog-footer">
        <el-button :disabled="!new_status" @click="changeBulkStatus()" type="primary">
          {{ $t('Update Status') }}
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script type="text/babel">
import {each, chunk} from "@/utils/Utils";

import {Box, ShoppingCart, ArrowDown, Money, Setting, Delete, MoreFilled} from '@element-plus/icons-vue'
import {markRaw} from "vue";

export default {
  name: 'BulkAction',
  components: {
    Money: markRaw(Money),
    Box: markRaw(Box),
    ShoppingCart: markRaw(ShoppingCart),
    ArrowDown: markRaw(ArrowDown),
    Setting: markRaw(Setting),
    Delete: markRaw(Delete),
    MoreFilled: markRaw(MoreFilled),
  },
  emits: ['reload'],
  props: ['selectedOrders'],
  data() {
    return {
      ShoppingCart: markRaw(ShoppingCart),
      Box: markRaw(Box),
      Money: markRaw(Money),
      // doing_bulk_actions: false,
      // bulk_action_settings: {
      //     title: 'Processing... Please wait',
      //     message: 'Please wait, while processing the selected action',
      //     completed: 0,
      //     total: 1
      // },
      process_errors: false,
      completed_message: '',
      showStatusModal: false,
      new_status: '',
      selected_action: ''
    }
  },
  computed: {
    bulkOptions() {
      return [
        // {
        //     value: 'shipped',
        //     label: 'Mark as Shipped (' + this.shortActions.shippables + ' ' + (this.shortActions.shippables > 1 ? 'Orders' : 'Order') + ')'
        // },
        // {
        //     value: 'completed',
        //     label: 'Mark as Completed (' + this.shortActions.completables + ' ' + (this.shortActions.completables > 1 ? 'Orders' : 'Order') + ')'
        // },
        {
          value: 'require_capture',
          label: 'Capture (' + this.shortActions.capturables + ' ' + (this.shortActions.capturables > 1 ? 'Payments' : 'Payment') + ')'
        },
        {
          value: 'order_status',
          label: 'Change Order Status'
        },
        // {
        //     value: 'shipping_status',
        //     label: 'Change Shipping Status'
        // },
        // {
        //     value: 'payment_status',
        //     label: 'Change Payment Status'
        // },
        {
          value: 'delete_orders',
          label: 'Delete Selected ' + (this.selectedOrders.length > 1 ? 'Orders' : 'Order')
        }
      ]
    },
    shortActions() {
      const actions = {
        capturables: 0,
        shippables: 0,
        completables: 0
      };

      each(this.selectedOrders, (order) => {
        if (order.payment_status == 'require_capture') {
          actions.capturables += 1;
        }
        if (order.shipping_status != 'shipped') {
          actions.shippables += 1;
        }
        if (order.order_status != 'completed') {
          actions.completables += 1;
        }
      });

      return actions;
    }
  },
  methods: {
    checkBulkRender(value) {
      switch (value) {
        case 'shipped':
          return this.shortActions.shippables;
        case 'completed':
          return this.shortActions.completables;
        case 'require_capture':
          return this.shortActions.capturables;
        default:
          return true;
      }
    },
    handleBulkAction() {
      // Access the selected value here
      const selectedValue = this.selected_action;

      if (!selectedValue) {
        this.handleMessage('Please select a bulk action', 'error');
        return;
      }

      if (!this.selectedOrders.length) {
        this.handleMessage('Please select an order', 'error');
        return;
      }

      switch (selectedValue) {
        case 'shipped':
          this.initShippingStatusChange('shipped');
          break;
        case 'completed':
          this.initOrderStatusChange('completed');
          break;
        case 'require_capture':
          this.initCapturePayments();
          break;
        case 'delete_orders':
          this.initDeleteOrders();
          break;
        default:
          this.showStatusModal = true;
      }
    },
    initShippingStatusChange(status) {
      const orderIds = [];
      each(this.selectedOrders, (order) => {
        if (order.shipping_status != status) {
          orderIds.push(order.id);
        }
      });

      this.initBulkAction({
        action: 'change_shipping_status',
        order_ids: orderIds,
        new_status: status
      }, 5, 'shipping status');
      return false;
    },
    initOrderStatusChange(status) {
      const orderIds = [];
      each(this.selectedOrders, (order) => {
        if (order.order_status != status) {
          orderIds.push(order.id);
        }
      });

      this.initBulkAction({
        action: 'change_order_status',
        order_ids: orderIds,
        new_status: status
      }, 5, 'order status');

      return false;
    },
    initCapturePayments() {
      const orderIds = [];
      each(this.selectedOrders, (order) => {
        if (order.payment_status == 'require_capture') {
          orderIds.push(order.id);
        }
      });

      if (!orderIds.length) {
        this.handleError('No payments found that needs to be captured');
        return false;
      }

      this.$confirm('Are you sure you want to capture these ' + orderIds.length + ' payments',
          'Please confirm',
          {
            confirmButtonText: 'Yes, Capture these payments',
            cancelButtonText: 'Cancel',
            type: 'info'
          }
      )
          .then(() => {
            // this.doing_bulk_actions = true;
            this.doBulkAction({
              action: 'capture_payments',
              order_ids: orderIds,
              new_status: 'paid'
            }, 2);
          })
          .catch(() => {

          });
      return false;
    },
    initDeleteOrders() {
      const orderIds = [];
      each(this.selectedOrders, (order) => {
        orderIds.push(order.id);
      });

      if (!orderIds.length) {
        this.$notify.error('No orders found');
        return false;
      }

      this.$confirm('Are you sure you want to delete these ' + orderIds.length + ' orders. This action is not recoverable',
          'Confirm Delete!',
          {
            confirmButtonText: 'Yes, Delete!',
            cancelButtonText: 'Cancel',
            type: 'error'
          }
      )
          .then(() => {
            // this.doing_bulk_actions = true;
            this.doBulkAction({
              action: 'delete_orders',
              order_ids: orderIds
            }, 10);
          })
          .catch(() => {

          });
      return false;
    },
    initBulkAction(payload, perPage = 10, status_title = 'status') {

      if (!payload.order_ids || !payload.order_ids.length) {
        this.$notify.error('No orders found that requires ' + status_title + ' changed to ' + payload.new_status);
        return false;
      }

      this.$confirm('Are you sure you want to change the status of ' + payload.order_ids.length + ' orders',
          'Please confirm',
          {
            confirmButtonText: 'Yes, Changed status to ' + payload.new_status,
            cancelButtonText: 'Cancel',
            type: 'warning'
          }
      )
          .then(() => {
            // this.doing_bulk_actions = true;
            this.doBulkAction(payload, perPage);
          })
          .catch(() => {

          });
      return false;
    },
    async doBulkAction(data, perPage = 5) {
      if (!data.order_ids.length) {
        this.$notify.error('No eligible order found based on your selection');
        return false;
      }
      // this.doing_bulk_actions = true;

      const chunks = chunk(data.order_ids, perPage);
      // this.bulk_action_settings.total = chunks.length;

      for (let i = 0; i < chunks.length; i++) {
        // if(!this.process_errors) {
        await this.repeatBulkAction(chunks[i], data)
            .then(response => {
              // this.bulk_action_settings.completed = i + 1;
              if (i === (chunks.length - 1)) {
                this.handleSuccess(response.message);
                this.completed_message = response.message;
                this.reset();
              }
            })
            .catch(errors => {
              this.process_errors = errors;
              this.handleError(errors);
            });
        // }
      }
    },
    repeatBulkAction(chunk, data) {
      data.order_ids = chunk;
      return this.$post('orders/do-bulk-action', data);
    },
    // handleBulkCommand(command) {
    //     if (command == 'delete_orders') {
    //         this.initDeleteOrders();
    //         return false;
    //     }

    //     this.selected_action = command;
    //     this.showStatusModal = true;
    // },
    changeBulkStatus() {
      const orderIds = [];
      each(this.selectedOrders, (order) => {
        if (order[this.selected_action] != this.new_status) {
          orderIds.push(order.id);
        }
      });

      if (!orderIds.length) {
        this.$notify.error('All selected orders are already in the provided status');
        return false;
      }

      if (this.selected_action != 'payment_status') {
        this.showStatusModal = false;
        // this.doing_bulk_actions = true;
        this.doBulkAction({
          action: 'change_' + this.selected_action,
          new_status: this.new_status,
          order_ids: orderIds
        }, 10);
        return false;
      }

      let confirmationMessage = '';

      switch (this.new_status) {
        case 'refunded':
          confirmationMessage = 'Only paid status orders will be refunded';
          break;
        case 'paid':
          confirmationMessage = 'Only pending payment status orders will be marked as paid';
          break;
        case 'pending':
          confirmationMessage = 'Only failed payment status orders will be marked as pending';
          break;
        case 'failed':
          confirmationMessage = 'Only pending payment status orders will be marked as failed';
          break;
        default:
          confirmationMessage = '';
      }

      if (confirmationMessage) {
        this.$confirm(confirmationMessage,
            'Please confirm',
            {
              confirmButtonText: 'Yes, Changed status to ' + this.new_status,
              cancelButtonText: 'Cancel',
              type: 'info'
            }
        )
            .then(() => {
              this.showStatusModal = false;
              // this.doing_bulk_actions = true;
              this.doBulkAction({
                action: 'change_' + this.selected_action,
                new_status: this.new_status,
                order_ids: orderIds
              }, 2);
            })
            .catch(() => {

            });
      } else {
        this.showStatusModal = false;
        // this.doing_bulk_actions = true;
        this.doBulkAction({
          action: 'change_' + this.selected_action,
          new_status: this.new_status,
          order_ids: orderIds
        }, 2);
        return false;
      }
    },
    reset() {
      // this.doing_bulk_actions = false;
      // this.bulk_action_settings = {
      //     title: 'Processing... Please wait',
      //     message: 'Please wait, while processing the selected action',
      //     completed: 0,
      //     total: 1
      // };
      this.process_errors = false;
      this.completed_message = '';
      this.showStatusModal = false;
      this.new_status = '';
      this.selected_action = '';
      this.$emit('reload');
    }
  },
  mounted() {

  }
}
</script>
