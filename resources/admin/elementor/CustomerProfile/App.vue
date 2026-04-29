<script setup>
import {onBeforeMount, ref} from "vue";
import useElementor from "@/elementor/mixins/useElementor";
import ServerSidePreview from "@/elementor/Components/ServerSidePreview.vue";

let elementorHelper = null;

const props = defineProps({
  settings: {
    type: String,
    default: ''
  }
});

const settings = ref(JSON.parse(props.settings));
// const sectionTitles = ref(settings.value.sectionTitles);


onBeforeMount(() => {
  elementorHelper = useElementor();
})
</script>

<template>
  <div class="fct-customer-profile-block-editor">
    <ServerSidePreview block="fluent-cart/customer-profile" :attributes="settings"/>
  </div>
</template>

<style scoped>

</style>
