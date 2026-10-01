<template>
    <div class="fct-reviews-route-wrap">
        <router-view :key="route.query.post_id || 'default'" />
    </div>
</template>

<script setup>
import {useRoute} from "vue-router";

defineOptions({
  name: 'ReviewsRoute'
})

const route = useRoute();
</script>
