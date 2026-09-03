<script setup>
import {onMounted, ref} from 'vue';
import Rest from '@/utils/http/Rest';
import PortalTemplateLoader from './PortalTemplateLoader.vue';

/**
 * Renders the add-on sections registered for one portal surface.
 *
 * Add-ons (e.g. fluent-cart-pro) attach to the matching PHP filter — for
 * `filter="profile_sections"` that is
 * `fluent_cart/customer_portal/profile_sections` — and return an uncompiled
 * `.vue` string plus its payload. Nothing renders on a store with no add-on
 * registered, which is why this stays quiet instead of showing a placeholder.
 */

defineOptions({
  name: 'PortalSections'
});

const props = defineProps({
  filter: {
    type: String,
    required: true
  },
  data: {
    type: Object,
    default: () => ({})
  }
});

const sections = ref([]);

onMounted(() => {
  Rest.get('customer-profile/sections', {filter: props.filter})
      .then((response) => {
        const items = response && response.sections;
        sections.value = Array.isArray(items) ? items : [];
      })
      .catch(() => {
        // An optional add-on area is not worth interrupting the customer over:
        // if it cannot load, the profile page simply shows its own sections.
        sections.value = [];
      });
});
</script>

<template>
  <template v-for="section in sections" :key="section.key">
    <PortalTemplateLoader
        v-if="section.type === 'vue-template' && section.component"
        :section="section"
        :data="data"
    />
  </template>
</template>
