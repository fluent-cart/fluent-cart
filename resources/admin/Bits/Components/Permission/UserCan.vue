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
  <!--
    Optional read-only fallback. Some controls must still be *shown* to a user who
    may not operate them — a shipment's status, for instance, is information first
    and a control second. Call sites without a `denied` slot render nothing when
    the check fails, exactly as before.
  -->
  <slot v-else name="denied"/>
</template>
