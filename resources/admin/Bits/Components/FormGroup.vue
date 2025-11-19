<template>
  <div class="fct-form-group">
    <div :class="`fct-form-group-icon ${iconPlacement ?? ''}`" v-if="icon">
      <DynamicIcon :name="icon" />
    </div>
    <slot/>
  </div>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

export default {
    name: "FormGroup",
    props: ['icon', 'iconPlacement'],
    components: {
        DynamicIcon
    }
}
</script>
