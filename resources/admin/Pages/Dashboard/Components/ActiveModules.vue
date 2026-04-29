<template>
  <Card.Container>
    <Card.Header title="Active modules" border_bottom>
    </Card.Header>
    <Card.Body class="min-h-[300px]">
          <div class="p-4 border-gray-500 border-bottom" v-for="activity in activities" :key="activity.id">
            <div class="flex w-full justify-between cursor-pointer">
              <div :class="'fluent-cart-order-activity-details fluent-cart-order-activity-status-' + activity.status ">
                <div class="flex">
                  <h4 class="m-0">{{ activity.title }}</h4>
                </div>
                <p class="fct-activity-content m-0">{{ activity.content }}</p>
              </div>
            </div>
        </div>
    </Card.Body>
  </Card.Container>
</template>
<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
</script>
<script>
export default {
  data() {
    return {
      activities: [{
        id: 1,
        status: 'completed',
        title: 'Stripe',
        created_by: 'Admin',
        content: 'Stripe onetime payment module is active'
      },
        {
          id: 1,
          status: 'completed',
          title: 'Stripe Subscription Module',
          created_by: 'Admin',
          content: 'Stripe Subscription module is active'
        },{
        id: 2,
        status: 'completed',
        title: 'PayPal',
        created_by: 'Admin',
        content: 'PayPal is active'
      },
        {
          id: 2,
          status: 'disables',
          title: 'PayPal Subscription module',
          created_by: 'Admin',
          content: 'PayPal Subscription is not active'
        },
        {
          id: 2,
          status: 'disables',
          title: 'FluentCart License module',
          created_by: 'Admin',
          content: 'License module is active'
        }
      ]
    }
  },
}
</script>
