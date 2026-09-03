<script setup>
import * as VueInstance from 'vue';
import {computed} from 'vue';
import {ElMessage, ElMessageBox} from 'element-plus';
import Rest from '@/utils/http/Rest';
import {parseStringComponent} from '@/utils/DynamicTemplateParser';
import translate from '../../translator/Translator';
import Notify from '../../utils/PortalNotify';
import Badge from './Badge.vue';

defineOptions({
  name: 'PortalTemplateLoader'
});

const props = defineProps({
  section: {
    type: Object,
    required: true
  },
  data: {
    type: Object,
    default: () => ({})
  }
});

const SafeVue = VueInstance.readonly(VueInstance);

const SafeRest = Object.freeze({
  get: (...args) => Rest.get(...args),
  post: (...args) => Rest.post(...args),
  put: (...args) => Rest.put(...args),
  delete: (...args) => Rest.delete(...args),
  patch: (...args) => Rest.patch(...args),
  getNonce: () => Rest.getNonce()
});

// What the host itself hands every add-on section. Everything else — window,
// document, fetch, timers — is shadowed to `undefined` by the parser.
const hostServices = {
  Vue: SafeVue,
  Rest: SafeRest,
  Notify,
  translate,
  Badge,
  ElMessage,
  ElMessageBox
};

/**
 * An add-on that needs a capability the host does not provide (card fields,
 * say) ships its own script alongside its section — see the
 * `fluent_cart/customer_dashboard/enqueue_assets` action — and registers it on
 * `window.fluent_cart_customer_portal.services`.
 *
 * Read lazily rather than at import time: parsing only happens once the
 * sections request has come back, so every page script has long since run.
 * Host services win, so an add-on cannot swap out Rest or Notify.
 */
const resolveServices = () => {
  const registered = window.fluent_cart_customer_portal?.services;

  return registered ? {...registered, ...hostServices} : hostServices;
};

const parsed = computed(() => parseStringComponent(props.section.component, resolveServices()));
</script>

<template>
  <component
      :is="parsed.component"
      v-if="parsed.component"
      :payload="section.payload"
      :data="data"
  />

  <!-- A section that fails to compile is a broken add-on, not a broken
       account. Say so plainly rather than vanishing, but never surface the
       parser's developer-facing reason to a customer. -->
  <div v-else class="fct-portal-section fct-portal-section--error" role="status">
    <p class="fct-portal-section-error-text">
      {{ translate('This section could not be loaded.') }}
    </p>
  </div>
</template>
