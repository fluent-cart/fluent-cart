<script setup>
import {computed} from "vue";
import Permission from "@/utils/permission/Permission";

const props = defineProps({
  permission: {
    type: [String, Array],
    required: true,
  },

  permissionType: {
    type: String,
    default: 'any'
  }
});

const isAllowed = computed(() => {
  return Permission.resolve({
    permission: props.permission,
    permissionType: props.permissionType,
  })
});
</script>

<template>
  <slot v-if="isAllowed"/>
</template>
