<script setup>
import translate from "@/utils/translator/Translator";

// Stands in for a section whose request failed, so a failure never reads as zero or "no data"
defineProps({
  message: {type: String, default: ''},
});
defineEmits(['retry']);
</script>

<template>
  <div class="fct-report-load-error flex flex-col items-center gap-3 py-6 text-center" role="alert">
    <p class="m-0 text-sm text-system-mid dark:text-gray-300">{{ message || translate('This report could not be loaded.') }}</p>
    <el-button size="small" @click="$emit('retry')">{{ translate('Retry') }}</el-button>
  </div>
</template>
