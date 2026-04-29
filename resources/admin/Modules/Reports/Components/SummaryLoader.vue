<template>
  <el-skeleton loading="loading" animated>
    <template #template>
      <div class="grid gap-5 bg-white rounded dark:bg-dark-700 fct-summary-loader"
        :class="`mb-${marginBottom} p-${padding}`"
        :style="`--loader-grid-cols: ${count};`">
        <template v-for="row in rows" :key="row">
          <div v-for="index in count" :key="index">
            <el-skeleton-item />
            <el-skeleton-item class="w-[90%]" />
          </div>
        </template>
      </div>
    </template>
  </el-skeleton>
</template>

<script setup>
const props = defineProps({
  loading: {
    type: Boolean,
    default: false
  },
  count: {
    type: Number,
    default: 1
  },
  marginBottom: {
    type: Number,
    default: 7.5
  },
  padding: {
    type: Number,
    default: 4
  },
  rows: {
    type: Number,
    default: 1
  }
});
</script>
