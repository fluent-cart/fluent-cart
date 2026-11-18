<template>
  <section class="fct-core-update">
    <div class="fct-core-update__body">
      <span class="fct-core-update__icon" aria-hidden="true">
        <DynamicIcon name="Refresh"/>
      </span>

      <div class="fct-core-update__content">
        <Title class="fct-core-update__title" size="sm">{{ title }}</Title>
        <p class="fct-core-update__text">
          {{ text }}
          <template v-if="!isAdmin"> {{ translate('Ask a site administrator to update FluentCart.') }}</template>
        </p>

        <div class="fct-core-update__actions">
          <a v-if="isAdmin" :href="updateUrl" class="el-button el-button--primary fct-core-update__button">
            {{ translate('Update FluentCart') }}
          </a>
          <span v-if="coreVersion" class="fct-core-update__version">
            {{ translate('Installed') }}: <strong>FluentCart {{ coreVersion }}</strong>
          </span>
        </div>
      </div>
    </div>

    <p v-if="note" class="fct-core-update__note">
      <DynamicIcon name="ShieldCheck"/>
      <span>{{ note }}</span>
    </p>
  </section>
</template>

<script setup>
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import Permission from "@/utils/permission/Permission";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Title from "@/Bits/Components/Layout/Title.vue";

// An add-on shows this when FluentCart is older than its screens need.
defineProps({
  title: {type: String, required: true},
  // Why, in the add-on's words: "This version of Gift Wrap needs a newer FluentCart."
  text: {type: String, required: true},
  // What still works meanwhile; left out when nothing does.
  note: {type: String, default: ''},
});

const coreVersion = AppConfig.get('app_config.version');
// Only site administrators can update plugins.
const isAdmin = Permission.isSuperAdmin();
const updateUrl = `${AppConfig.get('wp_admin_url')}/plugins.php?s=fluent-cart&plugin_status=all&fluent-cart-check-update=${Date.now()}`;
</script>
