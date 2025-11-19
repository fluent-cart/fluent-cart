<script setup>

</script>

<template>
  <div v-for="taxClass in 5" :key="taxClass" class="fct-tax-classes h-[73px]">
    <div class="fct-tax-classes-content flex-1">
      <el-skeleton animated class=" max-w-[400px]">
        <template #template>
          <el-skeleton-item variant="p" class="w-full h-4"/>
        </template>
      </el-skeleton>
      <el-skeleton animated class="max-w-[300px]">
        <template #template>
          <el-skeleton-item variant="p" class="w-3/4 h-4"/>
        </template>
      </el-skeleton>
    </div>
    <div class="fct-tax-classes-actions">
      <el-skeleton animated>
        <template #template>
          <el-skeleton-item variant="p" class="w-[80px] h-4"/>
        </template>
      </el-skeleton>
    </div>
  </div>
</template>

<style scoped>

</style>
