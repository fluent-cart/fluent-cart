<template>
  <div v-if="order_operation">
    <Card.Container>
      <Card.Header
          :title="$t('UTM Details')"
          border_bottom
          title_size="small"
      >
      </Card.Header>
      <Card.Body>
        <div class="fct-admin-sidebar-item">
          <ul class="fct-admin-summary-item-list">
            <li class="fct-admin-summary-item">
              <span class="font-medium">{{ $t('UTM Campaign') }}</span>
              <span>{{ order_operation.utm_campaign || '--' }}</span>
            </li>
            <li v-if="order_operation.utm_medium" class="fct-admin-summary-item">
              <span class="font-medium">{{ $t('UTM Medium') }}</span>
              <span>{{ order_operation.utm_medium || '--' }}</span>
            </li>
            <li v-if="order_operation.utm_source" class="fct-admin-summary-item">
              <span class="font-medium">{{ $t('UTM Source') }}</span>
              <span>{{ order_operation.utm_source || '--' }}</span>
            </li>
            <li v-if="order_operation.utm_content" class="fct-admin-summary-item">
              <span class="font-medium">{{ $t('UTM Content') }}</span>
              <span>{{ order_operation.utm_content || '--' }}</span>
            </li>

            <li v-if="order_operation.utm_id" class="fct-admin-summary-item">
              <span class="font-medium">{{ $t('UTM ID') }}</span>
              <span>{{ order_operation.utm_id || '--' }}</span>
            </li>


            <li v-if="order_operation.utm_term" class="fct-admin-summary-item">
              <span class="font-medium">{{ $t('UTM Term') }}</span>
              <span>{{ order_operation.utm_term || '--' }}</span>
            </li>

            <li v-if="order_operation.refer_url" class="fct-admin-summary-item">
              <span class="font-medium">{{ $t('Refer Url') }}</span>
              <span>{{ order_operation.refer_url || '--' }}</span>
            </li>


            <li v-for="item in metaItems" :key="item.key" class="fct-admin-summary-item">
              <span class="font-medium">{{ item.label }}</span>
              <span>{{ item.value }}</span>
            </li>

          </ul>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>

<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
</script>

<script type="text/babel">
import translate from "@/utils/translator/Translator";

export default {
  name: "UtmDetails",
  props: {
    order_operation: {
      type: Object,
    }
  },
  computed: {
    /**
     * Ad-network click identifiers live in the `meta` JSON column rather than in
     * their own columns. The API sends that column as an object when it holds
     * values and as an empty array when it does not, so normalise both shapes
     * before rendering. Unknown keys still render under their raw name — a feed
     * added through the `fluent_cart/utm/allowed_keys` filter stays visible.
     */
    metaItems() {
      const meta = this.order_operation && this.order_operation.meta;

      if (!meta || typeof meta !== 'object') {
        return [];
      }

      const labels = {
        gclid: translate('Google Click ID (gclid)'),
        gbraid: translate('Google iOS Web Click ID (gbraid)'),
        wbraid: translate('Google iOS App Click ID (wbraid)'),
        gad_campaignid: translate('Google Ads Campaign ID'),
        gad_source: translate('Google Ads Source'),
        msclkid: translate('Microsoft Click ID (msclkid)'),
        fbclid: translate('Facebook Click ID (fbclid)'),
      };

      return Object.keys(meta)
          .filter((key) => meta[key] !== '' && meta[key] !== null && meta[key] !== undefined)
          .map((key) => ({
            key,
            label: labels[key] || key,
            value: meta[key],
          }));
    }
  }
};
</script>

