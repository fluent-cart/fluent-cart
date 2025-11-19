<template>
  <el-skeleton :loading="loading" animated>
    <template #template>
      <div class="px-5 pb-4">
        <el-skeleton-item class="w-full h-7" />
      </div>
      <div v-for="index in 8" :key="index" class="flex items-center gap-5 px-5">
        <el-skeleton-item class="w-1/2 h-4 mb-3" />
        <el-skeleton-item class="w-1/2 h-4 mb-3" />
        <el-skeleton-item class="w-1/2 h-4 mb-3" />
        <el-skeleton-item class="w-1/2 h-4 mb-3" />
      </div>
    </template>
  </el-skeleton>
</template>

<script setup>
const props = defineProps({
  loading: {
    type: Boolean,
    default: false
  }
});
</script>
