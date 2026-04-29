<template>
    <el-skeleton animated>
        <template #template>
          <el-table :data="nextPageCount" class="w-full compact-table responsive">
            <el-table-column v-if="false" type="selection" width="45"/>

            <el-table-column :label="$t('Customer')" width="300">
              <div class="table-cell">
                <div class="fct-product-info-card w-full">
                  <div class="fct-product-info-media">
                    <el-skeleton-item varient="image" class="w-10 h-10.5 rounded-full flex-none" />
                  </div>
                  <div class="fct-product-info-details grow-0 shrink-0 basis-[calc(100%-52px)]" style="overflow: hidden;">
                    <div class="fct-product-info-name flex flex-col items-start gap-3">
                      <el-skeleton-item varient="p" class="max-w-[190px]" />
                      <el-skeleton-item varient="p" class="max-w-[100px]" />
                    </div>
                  </div>
                </div>
              </div>
            </el-table-column>

            <el-table-column :label="$t('Address')" width="300">
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-4/5" />
              </div>
            </el-table-column>

            <el-table-column :label="$t('Purchases')" width="150">
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-3/5" />
              </div>
            </el-table-column>

            <el-table-column
                v-if="customerTable.isColumnVisible('last_purchase_date')"
                :label="$t('Last Purchase Date')"
                width="200"
            >
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-4/5" />
              </div>
            </el-table-column>

            <el-table-column
                v-if="customerTable.isColumnVisible('customer_since')"
                :label="$t('Customer Since')"
                width="150"
            >
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-4/5" />
              </div>
            </el-table-column>

            <el-table-column
                v-if="customerTable.isColumnVisible('actions') && false"
                :label="$t('Actions')"
                width="125"
            >
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-3/5" />
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
    customerTable: {
        type: Object,
        default: () => ({})
    }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);

</script>
