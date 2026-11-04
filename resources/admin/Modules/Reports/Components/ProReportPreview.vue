<script setup>
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import Permission from "@/utils/permission/Permission";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";

defineProps({
  title: {type: String, required: true},
  text: {type: String, required: true},
  bullets: {type: Array, default: () => []},
  placement: {type: String, required: true},
});

// Pro is active but did not register this report: it is older than the report's move to Pro
const needsUpdate = !!AppConfig.get('app_config.isProActive');
const isAdmin = Permission.isSuperAdmin();
const updateUrl = `${AppConfig.get('wp_admin_url')}/plugins.php?s=fluent-cart&plugin_status=all&fluent-cart-pro-check-update=${Date.now()}`;
</script>

<template>
  <div class="fct-pro-preview">
    <div class="fct-pro-preview-sample" inert aria-hidden="true">
      <slot/>
    </div>
    <span class="fct-pro-preview-label">{{ translate('Sample data') }}</span>
    <div class="fct-pro-preview-card">
      <ProFeatureNotice
          v-if="needsUpdate"
          :title="title"
          :text="isAdmin
              ? translate('This report needs a newer FluentCart Pro. Update FluentCart Pro to see it.')
              : translate('Report_Report_Needs')"
          :show-button="isAdmin"
          :button-text="translate('Update FluentCart Pro')"
          :button-url="updateUrl"
          :placement="placement"
      />
      <ProFeatureNotice v-else :title="title" :text="text" :placement="placement">
        <ul v-if="bullets.length" class="fct-pro-preview-bullets">
          <li v-for="bullet in bullets" :key="bullet">{{ bullet }}</li>
        </ul>
      </ProFeatureNotice>
    </div>
  </div>
</template>
