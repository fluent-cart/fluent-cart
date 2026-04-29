<script setup>
defineProps({
  steps: {
    type: Array,
    required: true
  },
  currentStep: {
    type: Number,
    default: 1
  }
});

const emit = defineEmits(['step-click']);
</script>

<template>

  <div class="fct-step-indicator">
    <div class="indicator-title">
      {{steps[currentStep-1].label}}
    </div>
    <div class="indicator-steps">
      <div class="indicator-progress">
        {{currentStep}}/{{ steps.length}} {{ $t('Completed') }}
      </div>
      <div class="indicator-steps-bar">
        <template v-for="(step, index) in steps" :key="index">
          <div
              class="indicator-step"
              @click="emit('step-click', index + 1)"
          >
            <div
                class="indicator-step-bar"
                :class="index < currentStep
            ? 'is-completed'
            : ''"
            ></div>

          </div>

        </template>
      </div>
    </div>
  </div>

</template>
