<template>
  <MultiCurrencySettings v-if="isProActive"/>
  <SettingsPage v-else :heading="translate('Multi Currency')" :show-save-button="false">
    <Section flush>
      <ProFeatureNotice
          class="pt-12.5 pb-7.5"
          placement="feature_lock_multi_currency"
          :title="translate('Multi Currency')"
          :text="translate('Settings_Sell_Currency')"
      />
    </Section>
  </SettingsPage>
</template>

<script setup>
import AppConfig from "@/utils/Config/AppConfig";
import translate from "@/utils/translator/Translator";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import SettingsPage from "../Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import MultiCurrencySettings from "../MultiCurrencySettings.vue";

const isProActive = AppConfig.get('app_config.isProActive');
</script>
