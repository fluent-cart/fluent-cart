<template>
  <Page header="bar" class="setting-wrap" :title="heading">
    <template #leading>
      <button type="button" class="fct-setting-menu-toggle" :aria-label="translate('Settings menu')" @click="toggleMenu">
        <DynamicIcon name="Window"/>
      </button>
    </template>

    <template v-if="slots.heading" #title>
      <slot name="heading"/>
    </template>

    <template #actions>
      <fct-button v-if="showSaveButton" type="primary" @click="emit('onSave')" :loading="loading" :disabled="disabled" size="small">
        <span v-if="showCmndIcon && !loading" class="cmd">⌘s</span>
        {{ saveButtonText }}
      </fct-button>
      <slot name="action"/>
    </template>

    <slot/>
  </Page>
</template>

<script setup>
import {useSlots} from 'vue';
import translate from '@/utils/translator/Translator';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import Page from '@/Bits/Components/Layout/Page.vue';

defineProps({
  heading: {
    type: String,
    default: () => translate('Settings')
  },
  saveButtonText: {
    type: String,
    default: () => translate('Save')
  },
  loading: {
    type: Boolean,
    default: false
  },
  disabled: {
    type: Boolean,
    default: false
  },
  showSaveButton: {
    type: Boolean,
    default: true
  },
  showCmndIcon: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['onSave']);
const slots = useSlots();

// The settings nav lives in SettingsView; on small screens this opens it.
const toggleMenu = () => {
  document.querySelector('.fct-settings-nav-container')?.classList.toggle('is-nav-open');
  document.querySelector('.fct-settings-menu-overlay')?.classList.toggle('is-overlay-open');
};
</script>
