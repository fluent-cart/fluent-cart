<template>
  <div>
    <div class="fct-setting-form-row">
      <div class="w-[300px]">
        <el-skeleton animated class="max-w-[70%]">
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-4"/>
          </template>
        </el-skeleton>
        <el-skeleton animated class="max-w-[80%]">
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-4"/>
            <el-skeleton-item variant="p" class="w-1/2 h-4"/>
          </template>
        </el-skeleton>
      </div>
      <div class="flex-grow flex-shrink flex-basis-0 self-center">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-4"/>
          </template>
        </el-skeleton>
      </div>
    </div>
    <div class="fct-setting-form-row">
      <div class="w-[300px]">
        <el-skeleton animated class="max-w-[60%]">
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-4"/>
          </template>
        </el-skeleton>
        <el-skeleton animated class="max-w-full">
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-4"/>
          </template>
        </el-skeleton>
      </div>
      <div class="flex-grow flex-shrink flex-basis-0 self-center">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-4"/>
          </template>
        </el-skeleton>
      </div>
    </div>
    <div class="fct-setting-form-row">
      <div class="w-[300px]">
        <el-skeleton animated class="max-w-[50%]">
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-4"/>
          </template>
        </el-skeleton>
        <el-skeleton animated class="max-w-full">
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-4"/>
          </template>
        </el-skeleton>
      </div>
      <div class="flex-grow flex-shrink flex-basis-0 self-center">
        <el-skeleton animated>
          <template #template>
            <el-skeleton-item variant="p" class="w-full h-4"/>
          </template>
        </el-skeleton>
      </div>
    </div>
  </div>
</template>
