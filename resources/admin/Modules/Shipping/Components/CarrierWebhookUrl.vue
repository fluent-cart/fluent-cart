<template>
  <div class="fct-carrier-webhook">
    <div id="fct-carrier-webhook-label" class="text-sm font-medium text-system-dark dark:text-gray-50 mb-2">
      {{ translate('Webhook URL') }}
    </div>
    <div role="group" aria-labelledby="fct-carrier-webhook-label" aria-describedby="fct-carrier-webhook-hint">
      <CopyToClipboard :text="url"/>
    </div>
    <p id="fct-carrier-webhook-hint" class="text-xs text-system-mid dark:text-gray-300 mt-1 mb-0">
      {{ hint }}
    </p>
  </div>
</template>

<script setup>
import translate from '@/utils/translator/Translator';
import CopyToClipboard from '@/Bits/Components/CopyToClipboard.vue';

defineProps({
  url: {
    type: String,
    required: true
  },
  hint: {
    type: String,
    default: ''
  }
});
</script>
