<template>
  <button
      type="button"
      class="fct-fluid-tab-item"
      :aria-pressed="isActiveItem($refs.btn)"
      @click="clicked"
      ref="btn"
  >
    <template v-if="label">
      {{ label }}
    </template>

    <template v-else>
      <slot/>
    </template>
  </button>
</template>

<script>
export default {
  name: 'FluidTabItem',
  inject: ['setActiveItem', 'isActiveItem'],
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
  data() {
    return {
      isActive: false,
    };
  },
  mounted() {
    this.updateActive();
  },
  updated() {
    this.isActive = this.isActiveItem(this.$refs.btn);
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
};
</script>

