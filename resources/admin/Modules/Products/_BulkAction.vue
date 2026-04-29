<template>
  <div class="bulks-wrap">
    <div class="fct-bulk-actions">
      <el-select v-model="bulkOptionValue" :placeholder="$t('Bulk Actions')">
        <el-option
            v-for="item in bulkOptions"
            :key="item.value"
            :label="item.label"
            :value="item.value"
        />
      </el-select>

      <el-button @click="handleBulkAction" type="info" plain>
        {{ $t('Apply') }}
      </el-button>
    </div>

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
                <p>{{process_errors.message}}</p>
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
  </div>
</template>

<script type="text/babel">
import {each, chunk} from "@/utils/Utils";
import {ElMessage} from "element-plus";

export default {
  name: 'BulkAction',
  components: {},
  emits: ['reload'],
  props: ['selectedProducts'],
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
      selected_action: '',
      bulkOptionValue: '',
      bulkOptions: [
        {
          value: 'duplicate_products',
          label: 'Duplicate products',
        },
        {
          value: 'delete_products',
          label: 'Delete selected products',
        }
      ],
    }
  },
  computed: {},
  methods: {
    handleBulkAction() {
      if (this.bulkOptionValue === '') {
        return ElMessage({
          offset: 40,
          message: 'Please select a bulk action',
          type: 'error'
        });
      }
      const productIds = [];
      each(this.selectedProducts, (product) => {
        productIds.push(product.ID);
      });

      if (!productIds.length) {
        return ElMessage({
          offset: 40,
          message: 'Please select product',
          type: 'error'
        });
      }

      if (this.bulkOptionValue === 'duplicate_products') {
        this.doBulkAction({
          action: 'duplicate_products',
          product_ids: productIds
        })
        return;
      }

      this.$confirm('Are you sure you want to delete these ' + productIds.length + ' products. This action is not recoverable',
          'Confirm Delete!',
          {
            confirmButtonText: 'Yes, Delete these products',
            cancelButtonText: 'Cancel',
            type: 'error'
          }).then(() => {
        // this.doing_bulk_actions = true;
        this.doBulkAction({
          action: 'delete_products',
          product_ids: productIds
        }, 10);
      }).catch(() => {

      });
      return false;
    },
    async doBulkAction(data, perPage = 5) {
      if (!data.product_ids.length) {
        return this.handleError('No eligible product found based on your selection');
      }
      // this.doing_bulk_actions = true;

      const chunks = chunk(data.product_ids, perPage);
      // this.bulk_action_settings.total = chunks.length;

      for (let i = 0; i < chunks.length; i++) {
        if (!this.process_errors) {
          await this.repeatBulkAction(chunks[i], data)
              .then(response => {
                // this.bulk_action_settings.completed = i + 1;
                if (i === (chunks.length - 1)) {
                  this.handleSuccess(response.message);
                  // this.completed_message = response.message;
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
      data.product_ids = chunk;
      return this.$post('products/do-bulk-action', data);
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
