<template>
  <div class="fct-setting-header">
    <div class="fct-setting-header-content">
      <button ref="toggleBtn" class="fct-setting-menu-toggle">
        <DynamicIcon name="Window" />
      </button>

      <template v-if="$slots['heading']">
        <slot name="heading"></slot>
      </template>

      <h3 v-else class="fct-setting-head-title">
        {{heading}}
      </h3>
    </div>

    <div class="fct-setting-header-action">
      <el-button v-if="showSaveButton" type="primary" @click="saveSettings" :loading="loading" size="small">
        {{ loading ? translate(loadingText) : translate(saveButtonText) }}
      </el-button>

      <slot name="action"></slot>
    </div>
  </div>
</template>

<script>
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

export default {
  components: {
    DynamicIcon
  },
  props: {
    heading: {
      type: String,
      default: translate('Settings')
    },
    saveButtonText: {
      type: String,
      default: translate('Save')
    },
    loadingText: {
      type: String,
      default: translate('Saving')
    },
    loading: {
      type: Boolean,
      default: false
    },
    showSaveButton: {
      type: Boolean,
      default: true
    }
  },
  methods: {
    translate,
    saveSettings() {
      // Emit save event to a parent component
      this.$emit('onSave');
    }
  },
  mounted() {
    this.handleToggle = () => {
      const sidebarEl = document.querySelector('.fct-settings-nav-container');
      const overlayEl = document.querySelector('.fct-settings-menu-overlay');

      if (sidebarEl) {
        sidebarEl.classList.toggle('is-nav-open');
      }

      if (overlayEl) {
        overlayEl.classList.toggle('is-overlay-open');
      }
    };

    this.$refs.toggleBtn?.addEventListener('click', this.handleToggle);
  },
  beforeUnmount() {
    this.$refs.toggleBtn?.removeEventListener('click', this.handleToggle);
  }

}
</script>

