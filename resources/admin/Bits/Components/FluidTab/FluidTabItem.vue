<template>
  <span
      class="fct-fluid-tab-item"
      @click="clicked"
      ref="btn"
  >
    <template v-if="label">
      {{ label }}
    </template>

    <template v-else>
      <slot/>
    </template>
  </span>
</template>

<script>
export default {
  name: 'FluidTabItem',
  props: {
    label: String,
    requiredConfirmation: {
      type: Boolean,
      default: false
    },
    onClick: {
      type: Function,
      default: null
    }
  },
  inject: ['setActiveItem', 'isActiveItem'],
  data() {
    return {
      isActive: false,
    };
  },
  mounted() {
    this.updateActive();
  },
  methods: {
    clicked() {
      if (this.requiredConfirmation) {

        if (typeof this.onClick === 'function') {
          this.onClick(this.updateActive);
        }
        return;
      }

      this.updateActive();
      this.$emit('click');
    },
    updateActive() {

      if (this.$refs?.btn) {
        this.setActiveItem(this.$refs.btn);
      }
      this.isActive = this.isActiveItem(this.$refs.btn);
    },
  },
  updated() {
    this.isActive = this.isActiveItem(this.$refs.btn);
  },
};
</script>

