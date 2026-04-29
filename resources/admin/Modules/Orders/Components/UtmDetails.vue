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


            <li v-if="Array.isArray(order_operation.meta)" v-for='(meta, key) in order_operation.meta' class="fct-admin-summary-item">
              <span class="font-medium">{{ key }}</span>
              <span>{{ meta || '--' }}</span>
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
  }
};
</script>

