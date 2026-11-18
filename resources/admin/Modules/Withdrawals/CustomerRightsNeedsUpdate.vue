<script setup>
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import Permission from "@/utils/permission/Permission";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import * as Card from "@/Bits/Components/Card/Card.js";

// Set by MenuHandler only while a Customer Rights release older than the one
// that ships this screen is active: {installed, required}.
const update = AppConfig.get('app_config.customerRightsUpdate', {});

// Only site administrators can update plugins.
const isAdmin = Permission.isSuperAdmin();
const updateUrl = `${AppConfig.get('wp_admin_url')}/plugins.php?s=fluent-cart-customer-rights&plugin_status=all`;
</script>

<template>
  <div class="fct-withdrawal-requests-page fct-layout-width">
    <PageHeading :title="translate('Withdrawal Requests')"/>

    <Card.Container>
      <Card.Body>
        <div class="fct-addon-update-required flex flex-col items-center text-center py-10">
          <div class="w-10 h-10 mb-3 rounded flex items-center justify-center bg-warning-50 text-warning-500 dark:bg-warning-alpha-16">
            <DynamicIcon name="WarningFill" class="w-5 h-5"/>
          </div>

          <h3 class="m-0 mb-1 text-base font-medium text-system-dark dark:text-gray-50">
            {{ translate('Update Customer Rights to manage withdrawals') }}
          </h3>

          <p class="m-0 max-w-[480px] text-sm text-system-mid dark:text-gray-300">
            {{ translate('Withdrawal requests now ship with the Customer Rights add-on.') }}
            {{ isAdmin ? translate('Update it to see and process them again.') : translate('Ask a site administrator to update it.') }}
          </p>

          <p class="m-0 mt-3 text-xs text-system-light dark:text-gray-400">
            {{ translate('Installed: v%s').replace('%s', update.installed) }}
            <span class="mx-1">·</span>
            {{ translate('Required: v%s or later').replace('%s', update.required) }}
          </p>

          <fct-button v-if="isAdmin" class="mt-5" type="primary" tag="a" :href="updateUrl">
            {{ translate('Update Customer Rights') }}
          </fct-button>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
