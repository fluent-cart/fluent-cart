<template>
    <el-skeleton animated>
        <template #template>
          <el-table :data="nextPageCount" class="w-full compact-table responsive">
            <el-table-column v-if="false" type="selection" width="45"/>

            <el-table-column :label="$t('Products')" width="380">
              <div class="table-cell">
                <div class="fct-product-info-card w-full">
                  <div class="fct-product-info-media">
                    <el-skeleton-item varient="image" class="w-10 h-10.5 flex-none" />
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

            <el-table-column v-if="productTable.isColumnVisible('product_type')" :label="$t('Type')" width="100">
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-4/5" />
              </div>
            </el-table-column>

            <el-table-column v-if="productTable.isColumnVisible('variation_type')" :label="$t('Variation')" width="150">
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-4/5" />
              </div>
            </el-table-column>

            <el-table-column v-if="productTable.isColumnVisible('item_price')" :label="$t('Price')" width="130">
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-4/5" />
              </div>
            </el-table-column>

            <el-table-column v-if="productTable.isColumnVisible('stock_availability')" :label="$t('Stock')" width="100">
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-4/5" />
              </div>
            </el-table-column>

            <el-table-column v-if="productTable.isColumnVisible('post_status')" :label="$t('Status')" width="130">
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-4/5" />
              </div>
            </el-table-column>

            <el-table-column v-if="productTable.isColumnVisible('post_date')" :label="$t('Created At')" width="120">
              <div class="table-cell">
                <el-skeleton-item varient="p" class="w-4/5" />
              </div>
            </el-table-column>

            <el-table-column :label="$t('Actions')" width="80" align="right">
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
    productTable: {
        type: Object,
        default: () => ({})
    }
});

const nextPageCount = Array.from({length: props.nextPageCount}, (_, i) => i + 1);

</script>
