<template>
  <el-skeleton animated>
    <template #template>
      <el-table :data="nextPageCount" class="w-full compact-table responsive">
        <el-table-column v-if="false" type="selection" width="45"/>

        <el-table-column :label="$t('ID')" width="100">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column :label="$t('Customer')" width="280">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column :label="$t('Product')" width="360" show-overflow-tooltip>
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-3/5" />
          </div>
        </el-table-column>

        <el-table-column :label="$t('Status')" width="120">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column :label="$t('Billing')" width="230">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column :label="$t('Created At')" width="130">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column v-if="subscriptionTable.data.columns.indexOf('valid_till') !== -1" :label="$t('Valid Till')" width="120">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column v-if="subscriptionTable.data.columns.indexOf('next_billing_date') !== -1" :label="$t('Next Billing Date')" width="160">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column v-if="subscriptionTable.data.columns.indexOf('collection_method') !== -1" :label="$t('Collection Method')" width="160">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column v-if="subscriptionTable.data.columns.indexOf('bills_count') !== -1" :label="$t('Bills Count')" width="100">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column v-if="subscriptionTable.data.columns.indexOf('payment_method') !== -1" :label="$t('Payment Method')" width="150">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <el-table-column v-if="subscriptionTable.data.columns.indexOf('order_id') !== -1" :label="$t('Order ID')" width="100">
          <div class="table-cell">
            <el-skeleton-item varient="p" class="w-4/5" />
          </div>
        </el-table-column>

        <template #empty>

        </template>
      </el-table>
    </template>
  </el-skeleton>
</template>

<script setup>
const props = defineProps({
  nextPageCount: {
    type: Number,
    default: 0
  },
  subscriptionTable: {
    type: Object,
    default: () => ({})
  }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);

</script>
