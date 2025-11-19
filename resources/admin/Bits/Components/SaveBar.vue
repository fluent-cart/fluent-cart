<template>
    <div :class="`fct-save-bar ${isActive ?? ''} ${unsaveAlert ? 'shake-unsave-changes' : ''}`">
      <div class="fct-save-bar-inner">
        <div class="fct-save-bar-left">
          <DynamicIcon name="AlertIcon"/>
          <h4 class="title">{{ unSaveChangesText }}</h4>
        </div><!-- .fct-save-bar-left -->

        <div class="fct-save-bar-right">
          <div class="fct-btn-group">
            <el-button @click="discardEmitEvent" type="info" soft> {{ discardButtonText}}</el-button>
            <el-button type="primary" @click="saveEmitEvent" :loading="loading">
              <span v-if="showCmndIcon && !loading" class="cmd">⌘s</span>
              <template v-if="loading">
                {{ loadingText }}
              </template>
              <template v-if="!loading">
                {{ saveButtonText }}
              </template>
            </el-button>
          </div>
        </div><!-- .fct-save-bar-right -->
      </div>
    </div><!-- .fct-save-bar -->
</template>

<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {defineProps, defineEmits, onBeforeUnmount, ref, onMounted, watch} from 'vue';
import {onBeforeRouteLeave} from "vue-router";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  isActive: String,
  saveButtonText: {
    type: String,
    default: translate('Save')
  },
  discardButtonText: {
    type: String,
    default: translate('Discard')
  },
  loading: {
    type: Boolean,
    default: false
  },
  loadingText: {
    type: String,
    default: translate('Saving')
  },
  showCmndIcon: {
    type: Boolean,
    default: false
  },
  unSaveChangesText: {
    type: String,
    default: translate('Unsaved Changes')
  }
});
const unsaveAlert = ref(false);
const isChangeActive = ref('');

// add watch on props.isActive
watch(() => props.isActive, (newVal, oldVal) => {
  isChangeActive.value = newVal;
});

const emit = defineEmits(['discard', 'save']);

const discardEmitEvent = () => {
  isChangeActive.value = '';
  emit('discard');
};

const saveEmitEvent = () => {
  isChangeActive.value = '';
  emit('save');
};

const handleBeforeUnload = (event) => {
  if (isChangeActive.value && isChangeActive.value !== '') {
    event.preventDefault();
    unsaveAlert.value = true;
    setTimeout(() => {
      unsaveAlert.value = false;
    }, 2000);
    event.returnValue = '';
  } else {
    unsaveAlert.value = false;
  }
}

onBeforeRouteLeave((to, from, next) => {
  if (isChangeActive.value && isChangeActive.value !== '') {
    unsaveAlert.value = true;
    setTimeout(() => {
      unsaveAlert.value = false;
    }, 1000);
    // Prevent navigation
    next(false)
  } else {
    next()
    unsaveAlert.value = false;
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
})

// Commented code can be converted to lifecycle hooks if needed:
onMounted(() => {
  // jQuery('.fct_global_menu_wrap').hide();
  window.addEventListener('beforeunload', handleBeforeUnload)
});
// 
// onUnmounted(() => {
//   jQuery('.fct_global_menu_wrap').show();
// });
</script>
