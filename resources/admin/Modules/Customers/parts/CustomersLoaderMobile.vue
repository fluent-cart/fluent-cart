<template>
  <el-skeleton animated>
    <template #template>
      <div class="px-4 py-3">
        <div class="flex items-center justify-between mb-3">
          <el-skeleton-item varient="p" class="w-1/2"/>
          <el-skeleton-item varient="p" class="w-[100px]"/>
        </div>

        <div class="flex items-center justify-between mb-3">
          <el-skeleton-item varient="p" class="w-[50px]"/>
          <el-skeleton-item varient="p" class="w-[80px]"/>
        </div>

        <div class="flex items-center justify-between mb-3">
          <el-skeleton-item varient="p" class="w-[120px]"/>
          <el-skeleton-item varient="p" class="w-[100px]"/>
        </div>

        <div class="flex items-center justify-between mb-3">
          <el-skeleton-item varient="p" class="w-[100px]"/>
          <el-skeleton-item varient="p" class="w-[100px]"/>
        </div>

        <el-skeleton-item varient="p" class="w-[230px]"/>

      </div>
    </template>
  </el-skeleton>
</template>

