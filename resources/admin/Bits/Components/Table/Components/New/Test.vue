<script setup>
import {onMounted} from "vue";
import {ArrowDown} from "@element-plus/icons-vue";

const props = defineProps({
  row: {
    type: Object,
  },
  index: {
    type: Number,
    required: true
  },
  table: {
    type: Object,
    required: true
  },

  data: {
    type: Object,
    required: true
  }
})

onMounted(() => {

})
</script>

<template>
  <el-popover
      placement="bottom-start"
      :width="300"
      trigger="click"
  >
    <div class="fluent-cart-admin-pages">
      <div class="fct-popover-content relative overflow-x-auto">
        <table>
          <thead>
          <tr>
            <th scope="col">
              {{ $t('Product name') }}
            </th>
            <th scope="col">
              {{ $t('Quantity') }}
            </th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="item in row.filtered_order_items" :key="item.id">
            <td>{{ item.title }}</td>
            <td>{{ item.quantity }}</td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
    <template #reference>
      <div class="fct-action-link-btn">
        {{ row.filtered_order_items.length }}
        <template v-if="row.filtered_order_items.length > 1">
          {{ $t('items') }}
        </template>
        <template v-else>
          {{ $t('item') }}
        </template>
        <el-icon>
          <arrow-down/>
        </el-icon>
      </div>
    </template>
  </el-popover>
</template>

<style scoped>

</style>
