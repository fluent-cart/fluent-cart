<template>
  <div class="fct-subscription-plan-wrap">
    <div class="fct-subs-status-wrap float-end">
      <!-- <span class="fluent_cart_subs_method">{{subscription.current_payment_method}}</span> -->
      <!--      <span :class="'fluent_cart_subs_status badge ' + subscription.status">{{subscription.status}}</span>-->
      <Badge :status="subscriptionStatus"/>
    </div>
    <router-link :to="{name: 'view_subscription', params: {subscription_id: subscription?.id}}">
      <p class="fct-subs-title underline">{{ $t(subscription?.item_name) }}</p>
    </router-link>
    <p v-html="subscription?.payment_info"></p>

    <p class="cursor-pointer" v-if="parseInt(order?.parent_id)">
      {{ translate('Parent Order:') }}
      <a @click="getOrderUrl(order?.parent_id)">#{{ order?.parent_id }}</a>
    </p>

  </div>
</template>
<script>

import Badge from "@/Bits/Components/Badge.vue";
import translate from "../../utils/translator/Translator";

export default {
  name: "_Plans.vue",
  components: {Badge},
  data() {
    return {}
  },
  props: ['subscription', 'header', 'order', 'getOrderUrl'],
  computed: {
    subscriptionStatus() {
      return this.subscription?.status;
    }
  },
  methods: {
    translate
  }

}
</script>
