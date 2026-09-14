<script setup>
import translate from '@/utils/translator/Translator';

// Vue renders an async component's error component with the error alone.
defineProps({
  error: { type: [Error, Object, String], default: null },
});
</script>

<!--
  Shown in a Product Gallery tab when its component chunk fails to load (an
  offline admin, a stale asset URL after a deploy), so the reader is not left
  with an empty pane and no explanation.

  It asks for a page load rather than offering a retry on purpose: the browser
  keeps a failed dynamic import in its module map, so importing the same chunk
  again resolves to that same failure without a request.
-->
<template>
  <div class="fct-gallery-tab-error" data-fct-gallery-tab-error role="alert">
    <p class="fct-gallery-tab-error__text">{{ translate('This tab could not be loaded. Reload the page to try again.') }}</p>
  </div>
</template>
