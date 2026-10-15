<script setup>
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import Permission from "@/utils/permission/Permission";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import {LICENSING_MIN_PRO_VERSION} from "./legacyLicensingRoutes.js";

const isProActive = AppConfig.get('app_config.isProActive');
const proVersion = AppConfig.get('app_config.proVersion');
const needsUpdate = isProActive && proVersion
    && proVersion.localeCompare(LICENSING_MIN_PRO_VERSION, undefined, {numeric: true}) < 0;

// Only site administrators can update plugins or change modules.
const isAdmin = Permission.isSuperAdmin();

// Same screen core's "FluentCart Pro needs to be updated" admin notice opens.
const updateUrl = `${AppConfig.get('wp_admin_url')}/plugins.php?s=fluent-cart&plugin_status=all&fluent-cart-pro-check-update=${Date.now()}`;
</script>

<template>
  <div class="fct-licensing-unavailable">
    <ProFeatureNotice
        v-if="needsUpdate"
        :title="translate('Update FluentCart Pro to use Licensing')"
        :text="(isAdmin
            ? translate('Licensing now ships with FluentCart Pro v%s or later. Update FluentCart Pro to keep managing licenses and activated sites.')
            : translate('Licensing now ships with FluentCart Pro v%s or later. Ask a site administrator to update FluentCart Pro.')).replace('%s', LICENSING_MIN_PRO_VERSION)"
        :show-button="isAdmin"
        :button-text="translate('Update FluentCart Pro')"
        :button-url="updateUrl"
        placement="feature_lock_licensing"
    />

    <ProFeatureNotice
        v-else-if="!isProActive"
        :title="translate('Software Licensing')"
        :text="translate('Sell software licenses, manage activations and sites with FluentCart Pro.')"
        placement="feature_lock_licensing"
    />

    <el-alert
        v-else
        type="info"
        show-icon
        :closable="false"
        :title="translate('Licensing is not enabled')"
        :description="isAdmin
            ? translate('The Licensing module is turned off. Turn it on in Settings → Features & addon to manage licenses.')
            : translate('The Licensing module is turned off. Ask a site administrator to turn it on.')"
    />
  </div>
</template>
