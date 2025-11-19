<script setup>
import {defineProps} from 'vue';

defineProps({
  type: String,
  title: String,
  description: String,
  closable: Boolean,
  showIcon: Boolean
});

</script>


<template>
  <el-alert
      :title="title"
      :type="type"
      :description="description"
      :show-icon="showIcon"
      :closable="closable"
      role="alert"
  >
    <slot></slot>
  </el-alert>
</template>
