<template>
  <UserCan permission="is_super_admin">
    <section v-if="addon" class="fct-builder-addon-notice" :aria-labelledby="titleId">
      <!-- A light tile in both themes: builder logos are dark marks on a light ground. -->
      <span class="fct-builder-addon-notice__logo">
        <img v-if="addon.logo" :src="addon.logo" alt=""/>
      </span>

      <div class="fct-builder-addon-notice__body">
        <p :id="titleId" class="fct-builder-addon-notice__title">
          {{ translate('Design product pages in %1$s', addon.builder) }}
        </p>
        <p class="fct-builder-addon-notice__text">
          {{ translate('The %1$s add-on brings FluentCart to %2$s.', addon.title, addon.builder) }}
        </p>
      </div>

      <fct-button size="small" class="fct-builder-addon-notice__action" :loading="busy" :disabled="busy" @click="run">
        {{ actionLabel }}
      </fct-button>

      <button type="button" class="fct-builder-addon-notice__close" :aria-label="translate('Don\'t show again')" :title="translate('Don\'t show again')" @click="hide">
        <DynamicIcon name="Cross"/>
      </button>
    </section>
  </UserCan>
</template>

<script setup>
import {computed, ref} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import {activatePluginAddon, installPluginAddon} from "@/Modules/Settings/pluginAddonActions";

// One entry per page builder that is active without its FluentCart add-on (MenuHandler); usually empty.
const candidates = AppConfig.get('builder_addons', []) || [];
const hasPro = AppConfig.get('app_config.isProActive');
const titleId = 'fct-builder-addon-notice-title';
const storageKey = (item) => 'fct_builder_notice_hidden_' + item.key;

const isHidden = (item) => {
  try {
    return window.localStorage.getItem(storageKey(item)) === 'yes';
  } catch (e) {
    return false;
  }
};

// Closing one builder's notice moves on to the next builder, if any.
const closed = ref(candidates.filter(isHidden).map(item => item.key));
const addon = computed(() => candidates.find(item => !closed.value.includes(item.key)) || null);
const busy = ref(false);

const actionLabel = computed(() => {
  if (addon.value?.is_installed) {
    return translate('Activate');
  }
  return hasPro ? translate('Install & Activate') : translate('Download');
});

// Blocked storage only means the notice comes back on the next page load.
const hide = () => {
  const item = addon.value;
  closed.value = [...closed.value, item.key];
  try {
    window.localStorage.setItem(storageKey(item), 'yes');
  } catch (e) {
    // Ignore: see above.
  }
};

// Success reloads this product page, now with the builder add-on loaded.
const run = () => {
  busy.value = true;
  const item = addon.value;
  const request = item.is_installed ? activatePluginAddon(item) : installPluginAddon(item, hasPro);
  request.finally(() => {
    busy.value = false;
  });
};
</script>
