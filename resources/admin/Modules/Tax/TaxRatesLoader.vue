<script setup>
import {ref} from "vue";

const widths = ref(
    Array.from({ length: 6 }, () => `${Math.floor(Math.random() * 21) + 120}px`)
)
</script>

<template>
  <div class="fct-tax-rates-wrap-loader">
    <div v-for="(width, index) in widths" :key="index" class="flex items-center justify-between border-[1px] border-solid border-gray-divider dark:border-dark-400 rounded h-12 pr-4 pl-6 mb-4">
      <el-skeleton animated :style="{ width }" class="h-4">
        <template #template>
          <el-skeleton-item variant="p" class="h-4" />
        </template>
      </el-skeleton>
      <el-skeleton animated class="w-[100px] h-4">
        <template #template>
          <el-skeleton-item variant="p" class="h-4" />
        </template>
      </el-skeleton>
    </div>
  </div>
</template>

<style scoped>

</style>
