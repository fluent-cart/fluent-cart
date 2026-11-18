<template>
    <fct-skeleton animated>
          <fct-table :data="nextPageCount" class="w-full compact-table responsive fct-loader-table">
            <fct-table-column :selectable="() => false" type="selection" width="45"/>

            <fct-table-column :label="$t('Products')" width="380">
              <div class="table-cell">
                <div class="fct-product-info-card w-full">
                  <div class="fct-product-info-media">
                    <fct-skeleton variant="image" class="w-10 h-10.5 flex-none rounded-md" />
                  </div>
                  <div class="fct-product-info-details grow-0 shrink-0 basis-[calc(100%-52px)]" style="overflow: hidden;">
                    <div class="fct-product-info-name flex flex-col items-start gap-3">
                      <fct-skeleton variant="p" class="max-w-[190px]" />
                      <fct-skeleton variant="p" class="max-w-[100px]" />
                    </div>
                  </div>
                </div>
              </div>
            </fct-table-column>

            <fct-table-column v-if="productTable.isColumnVisible('product_type')" :label="$t('Type')" width="170">
              <div class="table-cell">
                <fct-skeleton variant="p" class="w-4/5" />
              </div>
            </fct-table-column>

            <fct-table-column v-if="productTable.isColumnVisible('variation_type')" :label="$t('Variation')" width="150">
              <div class="table-cell">
                <fct-skeleton variant="p" class="w-4/5" />
              </div>
            </fct-table-column>

            <fct-table-column v-if="productTable.isColumnVisible('item_price')" :label="$t('Price')" width="130">
              <div class="table-cell">
                <fct-skeleton variant="p" class="w-4/5" />
              </div>
            </fct-table-column>

            <fct-table-column v-if="productTable.isColumnVisible('stock_availability')" :label="$t('Stock')" width="100">
              <div class="table-cell">
                <fct-skeleton variant="p" class="w-4/5" />
              </div>
            </fct-table-column>

            <fct-table-column v-if="productTable.isColumnVisible('post_status')" :label="$t('Status')" width="130">
              <div class="table-cell">
                <fct-skeleton variant="p" class="w-4/5" />
              </div>
            </fct-table-column>

            <fct-table-column v-if="productTable.isColumnVisible('post_date')" :label="$t('Date')" width="100">
              <div class="table-cell">
                <fct-skeleton variant="p" class="w-4/5" />
              </div>
            </fct-table-column>

            <fct-table-column :label="$t('Actions')" width="90" align="right">
              <div class="table-cell">
                <fct-skeleton variant="p" class="w-3/5" />
              </div>
            </fct-table-column>

            <template #empty>
            </template>
          </fct-table>
    </fct-skeleton>
</template>

<script setup>
const props = defineProps({
    nextPageCount: {
        type: Number,
        default: 0
    },
    productTable: {
        type: Object,
        default: () => ({})
    }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);

</script>

<style>
.fct-loader-table .el-checkbox__inner {
  background-color: #f5f6f7;
  border-color: #dcdfe6;
  cursor: not-allowed;
}
</style>
