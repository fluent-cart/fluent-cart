<script setup>
import translate from '@/utils/translator/Translator';
import * as Card from '@/Bits/Components/Card/Card.js';
import SettingsHeader from '../Settings/Parts/SettingsHeader.vue';
import {CARRIER_MANUAL} from '@/utils/orderConstants';
import {ArrowRight} from '@element-plus/icons-vue';

const manualCarrierRoute = {name: 'carrier_settings', params: {slug: CARRIER_MANUAL}};
</script>

<template>
  <div class="setting-wrap">
    <SettingsHeader :heading="translate('Shipping Zones')" :show-save-button="false"/>

    <div class="setting-wrap-inner">
      <Card.Container>
        <Card.Body>
          <div class="fct-shipping-zones-moved grid gap-3 justify-items-start">
            <h4 class="text-base font-semibold m-0 text-system-dark dark:text-gray-50">
              {{ translate('Shipping zones have moved') }}
            </h4>
            <p class="text-sm m-0 text-system-mid dark:text-gray-300">
              {{ translate('Ship_Shipping_Zones') }}
            </p>
            <!-- The new location, as the path a user follows in Settings. -->
            <el-breadcrumb class="fct-shipping-zones-moved-path" :separator-icon="ArrowRight" :aria-label="translate('New location')">
              <el-breadcrumb-item>{{ translate('Shipping') }}</el-breadcrumb-item>
              <el-breadcrumb-item :to="{name: 'shipping_carriers'}">{{ translate('Carriers') }}</el-breadcrumb-item>
              <el-breadcrumb-item :to="manualCarrierRoute">{{ translate('Manual / Other') }}</el-breadcrumb-item>
              <el-breadcrumb-item>{{ translate('Shipping Zones') }}</el-breadcrumb-item>
            </el-breadcrumb>
            <el-button tag="router-link" type="primary" :to="manualCarrierRoute">
              {{ translate('Go to Manual carrier') }}
            </el-button>
          </div>
        </Card.Body>
      </Card.Container>
    </div>
  </div>
</template>
