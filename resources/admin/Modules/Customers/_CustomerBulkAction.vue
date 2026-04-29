<template>
  <div class="bulks-wrap">
    <div class="fct-bulk-actions">
      <el-select v-model="selected_action" :placeholder="$t('Bulk Actions')">
        <el-option
            v-for="item in bulkOptions"
            :key="item.value"
            :label="$t(item.label)"
            :value="item.value"
        />
      </el-select>
      <el-button @click="handleBulkAction" type="info" plain>
        {{ $t('Apply') }}
      </el-button>
    </div>

    <!-- <ul class="table-filter-bulks-actions">
        <li>
            <el-dropdown @command="handleBulkCommand" trigger="click">
                <div class="bulk-more-action">
                    <button>
                        <el-icon>
                            <MoreFilled/>
                        </el-icon>
                        {{ $t('Bulk Actions')}}
                    </button>
                </div>
                <template #dropdown>
                    <el-dropdown-menu>
                        <el-dropdown-item command="customer_status">
                            <el-icon>
                                <user />
                            </el-icon>
                            {{ $t('Change Customer Status')}}
                        </el-dropdown-item>
                        <el-dropdown-item command="delete_customers">
                            <el-icon>
                                <delete/>
                            </el-icon>
                            {{ $t('Delete selected')}} {{selectedCustomers.length > 1 ? $t('customers') : $t('customer')}}
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
                <div>
                    <h3>{{ completed_message }}</h3>
                    <el-button type="success" @click="reset()">{{ $t('Awesome')}}</el-button>
                </div>
            </template>
            <div style="text-align: left;" v-else-if="process_errors">
                <h3>{{ $t('Error occurred when processing')}}</h3>
                <el-text size="large" type="danger">{{process_errors.message}}</el-text><br/><br/>
                <el-button class="mt-4" type="primary" @click="reset()">{{ $t('OK')}}</el-button>
            </div>
            <template v-else>
                <div>
                    <h3>{{ bulk_action_settings.message }}</h3>
                    <el-progress
                        :percentage="parseInt((bulk_action_settings.completed / bulk_action_settings.total) * 100) || 10"
                        :indeterminate="true"/>
                    </div>
            </template>
        </div>
    </el-dialog> -->

    <el-dialog :append-to-body="true" v-model="showStatusModal" :title="$t('Update Customer Status')" width="34%">
      <el-form label-position="top">
        <el-form-item v-if="selected_action == 'customer_status'" :label="$t('Customer Status')">
          <el-select v-model="new_status" :placeholder="$t('Select Customer Status')">
            <el-option
                v-for="(statusName, status) in appVars.editable_customer_statues"
                :key="status"
                :label="statusName"
                :value="status"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <div class="dialog-footer">
        <el-button :disabled="!new_status" @click="changeBulkStatus()" type="primary">
          {{ $t('Update') }}
        </el-button>
      </div>
    </el-dialog>

  </div>
</template>

<script>
import {ArrowDown, Delete, User, MoreFilled} from "@element-plus/icons-vue";
import {each} from "@/utils/Utils";

import {chunk} from "@/utils/Utils";

export default {
  name: 'CustomerBulkAction',
  components: {
    ArrowDown,
    User,
    Delete,
    MoreFilled
  },
  emits: ['reload'],
  props: ['selectedCustomers'],
  data() {
    return {
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
        {
          value: 'customer_status',
          label: 'Change Customer Status'
        },
        {
          value: 'delete_customers',
          label: 'Delete Selected ' + (this.selectedCustomers.length > 1 ? 'Customers' : 'Customer')
        }
      ];
    }
  },
  methods: {
    handleBulkAction() {
      // Access the selected value here
      const selectedValue = this.selected_action;

      if (!selectedValue) {
        this.handleMessage('Please select a bulk action', 'error');
      }

      if (!this.selectedCustomers.length) {
        this.handleMessage('Please select customer', 'error');
      } else {
        // delete customer
        if (selectedValue === 'delete_customers') {
          this.initDeleteCustomers();
          return false;
        }

        // show modal
        this.showStatusModal = true;
      }
    },
    initDeleteCustomers() {
      const customerIds = [];
      each(this.selectedCustomers, (customer) => {
        customerIds.push(customer.id);
      });

      if (!customerIds.length) {
        this.handleError('No customers found');
        return false;
      }

      this.$confirm('Are you sure you want to delete these ' + customerIds.length + ' customers. This action is not recoverable',
          'Confirm Delete!',
          {
            confirmButtonText: this.$t('Yes, Delete!'),
            cancelButtonText: 'Cancel',
            type: 'error'
          }
      )
          .then(() => {
            // this.doing_bulk_actions = true;
            this.doBulkAction({
              action: 'delete_customers',
              customer_ids: customerIds
            }, 10);
          })
          .catch(() => {

          });
      return false;
    },
    async doBulkAction(data, perPage = 5) {
      if (!data.customer_ids.length) {
        this.handleError('No eligible customer found based on your selection');
        return false;
      }
      // this.doing_bulk_actions = true;

      const chunks = chunk(data.customer_ids, perPage);
      // this.bulk_action_settings.total = chunks.length;

      for (let i = 0; i < chunks.length; i++) {
        if (!this.process_errors) {
          await this.repeatBulkAction(chunks[i], data)
              .then(response => {
                // this.bulk_action_settings.completed = i + 1;
                if (i === (chunks.length - 1)) {
                  this.handleSuccess(response.message);
                  this.completed_message = response.message;
                }
              })
              .catch(errors => {
                this.process_errors = errors;
                this.handleError(errors.message, 5000);
              })
              .finally(() => {
                this.reset();
              });
        }
      }
    },
    repeatBulkAction(chunk, data) {
      data.customer_ids = chunk;
      return this.$post('customers/do-bulk-action', data);
    },
    changeBulkStatus() {
      const customerIds = [];
      each(this.selectedCustomers, (customer) => {
        if (customer[this.selected_action] != this.new_status) {
          customerIds.push(customer.id);
        }
      });

      if (!customerIds.length) {
        this.handleError('All selected customers are already in the provided status');
        return false;
      }

      if (this.selected_action != 'customer_status') {
        this.showStatusModal = false;
        // this.doing_bulk_actions = true;
        this.doBulkAction({
          action: 'change_' + this.selected_action,
          new_status: this.new_status,
          customer_ids: customerIds
        }, 10);
        return false;
      }


      this.showStatusModal = false;
      // this.doing_bulk_actions = true;
      this.doBulkAction({
        action: 'change_' + this.selected_action,
        new_status: this.new_status,
        customer_ids: customerIds
      }, 2);
      return false;
    },
    // handleBulkCommand(command) {
    //     if (command == 'delete_customers') {
    //         this.initDeleteCustomers();
    //         return false;
    //     }

    //     this.selected_action = command;
    //     this.showStatusModal = true;
    // },
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

<style scoped>

</style>
