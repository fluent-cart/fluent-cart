<script setup>
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  cardOptions: {
    type: Object,
    default: () => ({
      heading: "",
      linkButtonText: "",
      linkButtonUrl: "",
      headingIcon: {
        type: Boolean,
        default: false
      }
    }),
  },
});

</script>

<template>
  <div class="fct-report-card">
    <div class="fct-report-card-header" v-if="cardOptions.heading || cardOptions.linkButtonText">
      <div class="flex items-center gap-2" v-if="cardOptions.heading">
        <IconButton size="x-small" bg="danger" soft v-if="cardOptions.headingIcon">
          <DynamicIcon name="FailedCircle"/>
        </IconButton>
        <h4 class="title">{{ cardOptions.heading }}</h4>
      </div>
      <div class="actions">
        <router-link :to="`/${cardOptions.linkButtonUrl}`" class="link" v-if="cardOptions.linkButtonText">
          {{ $t(cardOptions.linkButtonText) }}
        </router-link>

        <slot name="action"/>
      </div>
    </div>
    <div class="fct-report-card-body flex h-full">
      <div class="w-full">
        <slot/>
      </div>
    </div>
  </div>
</template>
