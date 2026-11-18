<template>
  <section v-if="items.length" class="fct-addon-reco" :aria-labelledby="titleId">
    <h3 class="fct-addon-reco__heading">
      <button
          type="button"
          class="fct-addon-reco__toggle"
          :aria-expanded="collapsed ? 'false' : 'true'"
          :aria-controls="collapsed ? undefined : bodyId"
          @click="toggle"
      >
        <span :id="titleId">{{ translate('Recommended for your store') }}</span>
        <DynamicIcon name="ChevronDown" class="fct-addon-reco__chevron" :class="{'is-collapsed': collapsed}"/>
      </button>
    </h3>

    <!-- The accordion unmounts the body when collapsed, so aria-controls is only set while it is open. -->
    <Animation :visible="!collapsed" accordion>
      <div :id="bodyId">
        <p class="fct-addon-reco__text">{{ translate('Add-ons that match how you sell.') }}</p>

        <ul class="fct-addon-reco__list">
          <li v-for="item in items" :key="item.key" class="fct-addon-reco__item">
            <!-- A light tile in both themes: some add-on logos are black and have no dark version. -->
            <span class="fct-addon-reco__logo">
              <img :src="item.logo" alt=""/>
            </span>
            <div class="fct-addon-reco__body">
              <div class="fct-addon-reco__name">
                <span>{{ item.title }}</span>
                <Badge v-if="item.badge" size="small" status="warning" :text="item.badge"/>
              </div>
              <p class="fct-addon-reco__reason">{{ item.reason }}</p>
              <fct-button
                  size="small"
                  class="fct-addon-reco__action"
                  :loading="busyKey === item.key"
                  :disabled="!!busyKey"
                  @click="run(item)"
              >
                {{ actionLabel(item) }}
                <span class="sr-only">{{ item.title }}</span>
              </fct-button>
            </div>
          </li>
        </ul>

        <fct-button text size="small" class="fct-addon-reco__all" :to="{path: '/settings/addons'}">
          {{ translate('See all add-ons') }}
          <DynamicIcon name="ArrowRight" class="fct-addon-reco__all-icon"/>
        </fct-button>
      </div>
    </Animation>
  </section>
</template>

<script setup>
import {ref} from "vue";
import Animation from "@/Bits/Components/Animation.vue";
import Badge from "@/Bits/Components/Badge.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import {activatePluginAddon, installPluginAddon} from "@/Modules/Settings/pluginAddonActions";
import {isRecommendationsCollapsed, setRecommendationsCollapsed} from "@/Pages/Dashboard/addonRecommendations";

defineProps({
  items: {
    type: Array,
    default: () => []
  }
});

const titleId = 'fct-addon-reco-title';
const bodyId = 'fct-addon-reco-body';
const hasPro = AppConfig.get('app_config.isProActive');

const collapsed = ref(isRecommendationsCollapsed());
const busyKey = ref('');

const toggle = () => {
  collapsed.value = !collapsed.value;
  setRecommendationsCollapsed(collapsed.value);
};

// Same labels as Settings → Features & addon: without Pro a new add-on is a download.
const actionLabel = (item) => {
  if (item.is_installed) {
    return translate('Activate');
  }
  return hasPro ? translate('Install & Activate') : translate('Download');
};

// Success reloads on the add-on's redirect_url; the busy state clears on failure or after a download.
const run = (item) => {
  busyKey.value = item.key;
  const request = item.is_installed ? activatePluginAddon(item) : installPluginAddon(item, hasPro);
  request.finally(() => {
    busyKey.value = '';
  });
};
</script>
