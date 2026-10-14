<template>
  <MultiCurrencySettings v-if="isProActive"/>
  <div v-else class="setting-wrap">
    <SettingsHeader :heading="translate('Multi Currency')" :show-save-button="false"/>
    <div class="setting-wrap-inner">
      <Card.Container>
        <Card.Body class="px-0 pb-0">
          <ProFeatureNotice
              class="py-7.5"
              placement="feature_lock_multi_currency"
              :title="translate('Multi Currency')"
              :text="translate('Sell in more than one currency with exchange rates, price adjustments, rounding and charm pricing.')"
          />
        </Card.Body>
      </Card.Container>
    </div>
  </div>
</template>

<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import AppConfig from "@/utils/Config/AppConfig";
import translate from "@/utils/translator/Translator";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import SettingsHeader from "../Parts/SettingsHeader.vue";
import MultiCurrencySettings from "../MultiCurrencySettings.vue";

const isProActive = AppConfig.get('app_config.isProActive');
</script>
