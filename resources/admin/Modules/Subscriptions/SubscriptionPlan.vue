<template>
  <CardContainer>
    <CardHeader
      :title="$t('Subscription Plan')"
      border_bottom
      title_size="small"
    />

    <CardBody>
      <Plans
        :getOrderUrl="getOrderUrl"
        :subscription="subscription"
        :order="order"
        :header="$t('Subscription Plans #') + (index + 1)"
      />
    </CardBody>
  </CardContainer>
</template>

<script>
import { Container as CardContainer, Header as CardHeader, Body as CardBody } from '@/Bits/Components/Card/Card.js';
import Plans from "../Orders/_Plans.vue";

export default {
  name: "SubscriptionPlanItem",
  components: {
    CardContainer,
    CardHeader,
    CardBody,
    Plans
  },
  props: ["subscription", "order", "index", "getOrderUrl"]
};
</script>
