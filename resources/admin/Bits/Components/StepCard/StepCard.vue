<template>
  <div class="step-card group" :class="active">
    <div class="step-card-inner">
      <div class="step-card-head">
          <!-- <div class="step-card-marker" v-if="active">
              <el-icon><SuccessFilled /></el-icon>
          </div> -->
          <div class="step-card-icon">
              <slot name="icon" v-if="!active"/>
              <DynamicIcon name="CheckCircle" v-if="active"/>
          </div>
          <h5 class="title"> {{ title }}</h5>
      </div>
      <div class="step-card-content">
          <p class="text">{{ text }}</p>
          <a v-if="!active" :href="url" class="link">{{ $t('Setup Now')}}</a>
          <span v-else class="step-card-completed-text">{{ $t('Completed')}}</span>
      </div>
    </div><!-- step-card-inner -->
  </div><!-- step-card -->
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
export default {
    name: 'StepCard',
    props: ['title', 'text', 'active', 'url'],
    components: {
      DynamicIcon
    }
}
</script>

