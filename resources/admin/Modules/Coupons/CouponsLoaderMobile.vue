<template>
  <el-skeleton animated>
    <template #template>
      <div class="px-4 py-3">
        <div v-for="index in 2" :key="index" class="flex items-center justify-between mb-3">
          <el-skeleton-item varient="p" class="w-1/2"/>
          <el-skeleton-item varient="p" class="w-[100px]"/>
        </div>

        <div class="grid grid-cols-3 gap-5 mt-5">
          <div v-for="index in 3" :key="index">
            <el-skeleton-item varient="p" class="w-1/2"/>
            <el-skeleton-item varient="p" class="w-[100px]"/>
          </div>
        </div>
      </div>
    </template>
  </el-skeleton>
</template>
