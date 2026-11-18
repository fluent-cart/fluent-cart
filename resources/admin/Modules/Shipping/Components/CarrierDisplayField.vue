<template>
  <!-- Status: a strip with key/value facts and buttons that run carrier actions at once, without Save. -->
  <div v-if="field.type === 'status'">
  <div class="fct-carrier-strip" :class="{ 'is-err': field.tone === 'err' && !working }">
    <div class="min-w-0">
      <div class="fct-carrier-strip__title">{{ working ? working.title : field.title }}</div>
      <div class="fct-carrier-kv">
        <template v-if="working">
          <span>{{ working.text || translate('Usually takes a second.') }}</span>
        </template>
        <template v-else>
          <span v-if="field.text">{{ field.text }}</span>
          <span v-for="(item, index) in (field.items || [])" :key="index">
            {{ item.label }} <b>{{ item.value }}</b>
          </span>
        </template>
      </div>
    </div>
    <div class="fct-carrier-strip__actions">
      <fct-button v-if="working" size="small" disabled>
        <span class="fct-carrier-spin" aria-hidden="true"></span>{{ working.button || translate('Please wait') }}
      </fct-button>
      <template v-else>
        <fct-button
            v-for="action in (field.actions || [])"
            :key="action.action"
            size="small"
            :type="action.variant === 'primary' ? 'primary' : undefined"
            :class="{ 'fct-carrier-btn-danger': action.variant === 'danger' }"
            :disabled="!!running"
            :aria-expanded="action.confirm ? String(confirming === action.action) : undefined"
            @click="onAction(action)"
        >
          {{ action.label }}
        </fct-button>
      </template>
      <div v-if="confirmAction" class="fct-carrier-pop" role="dialog" :aria-labelledby="popTitleId" @keydown.esc="confirming = null">
        <div :id="popTitleId" class="fct-carrier-pop__title">{{ confirmAction.confirm.title }}</div>
        <div class="fct-carrier-pop__text">{{ confirmAction.confirm.text }}</div>
        <div class="fct-carrier-pop__actions">
          <fct-button size="small" @click="confirming = null">{{ translate('Cancel') }}</fct-button>
          <fct-button size="small" type="danger" @click="run(confirmAction)">{{ confirmAction.confirm.button }}</fct-button>
        </div>
      </div>
    </div>
  </div>
  <p v-if="field.note" class="fct-carrier-hint fct-carrier-strip__note">{{ field.note }}</p>
  </div>

  <!-- Read-only: a value managed somewhere else, with a link there. -->
  <div v-else-if="field.type === 'readonly'">
    <span class="fct-carrier-label">{{ field.label }}</span>
    <div class="fct-carrier-ro">
      <div>
        <div class="fct-carrier-ro__value">
          <template v-for="(line, index) in lines(field.value)" :key="index"><br v-if="index">{{ line }}</template>
        </div>
        <div v-if="field.hint" class="fct-carrier-hint is-flush">{{ field.hint }}</div>
      </div>
      <router-link v-if="field.link" :to="field.link.route" class="fct-carrier-link">{{ field.link.label }}</router-link>
    </div>
  </div>

  <div v-else-if="field.type === 'notice'" class="fct-carrier-alert" :class="'is-' + (field.tone || 'mute')" :role="field.tone === 'err' ? 'alert' : null">
    <span>
      {{ field.text }}
      <router-link v-if="field.link" :to="field.link.route" class="fct-carrier-link">{{ field.link.label }}</router-link>
    </span>
  </div>

  <!-- Manual webhook setup: the secret stays masked until Show; Copy always copies the full URL. -->
  <div v-else-if="field.type === 'webhook_setup' && url" class="fct-carrier-man">
    <button
        type="button"
        class="fct-carrier-man__toggle"
        :class="{ 'is-open': manualOpen }"
        :aria-expanded="manualOpen ? 'true' : 'false'"
        :aria-controls="manualBodyId"
        @click="manualOpen = !manualOpen"
    >
      <DynamicIcon name="ChevronRight" class="fct-carrier-man__chevron" aria-hidden="true"/>
      {{ field.label }}
    </button>
    <Animation :visible="manualOpen" accordion>
    <div :id="manualBodyId" class="fct-carrier-man__body">
      <div class="fct-carrier-urlrow">
        <fct-input :model-value="shown ? url : maskedUrl" readonly :aria-label="translate('Webhook URL')"/>
        <fct-button @click="shown = !shown">{{ shown ? translate('Hide') : translate('Show') }}</fct-button>
        <fct-button @click="copy">{{ translate('Copy') }}</fct-button>
      </div>
      <ol>
        <li v-for="(step, index) in (field.steps || [])" :key="index">
          <template v-for="(part, partIndex) in boldParts(step)" :key="partIndex"><b v-if="partIndex % 2">{{ part }}</b><template v-else>{{ part }}</template></template>
        </li>
      </ol>
    </div>
    </Animation>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import Animation from '@/Bits/Components/Animation.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import translate from '@/utils/translator/Translator';
import Notify from '@/utils/Notify';

const props = defineProps({
  field: {
    type: Object,
    required: true
  },
  // The carrier's webhook URL, for webhook_setup.
  url: {
    type: String,
    default: ''
  },
  // The action running on this page, if any.
  running: {
    type: String,
    default: null
  }
});

const emit = defineEmits(['action']);

const confirming = ref(null);
const shown = ref(false);

// The carrier opens the steps after a failed registration; the admin can still close them.
const manualOpen = ref(!!props.field.open);
watch(() => props.field.open, (open) => {
  if (open) {
    manualOpen.value = true;
  }
});

const manualBodyId = computed(() => 'fct-carrier-man-' + props.field.key);

const popTitleId = computed(() => 'fct-carrier-pop-' + props.field.key);

const working = computed(() => {
  const action = (props.field.actions || []).find((item) => item.action === props.running);
  return action ? (action.working || {title: translate('Talking to the carrier')}) : null;
});

const confirmAction = computed(() => (props.field.actions || []).find((item) => item.action === confirming.value && item.confirm) || null);

const run = (action) => {
  confirming.value = null;
  emit('action', action);
};

const onAction = (action) => {
  if (action.confirm) {
    confirming.value = confirming.value === action.action ? null : action.action;
    return;
  }
  run(action);
};

const lines = (value) => String(value || '').split('\n');

// "Add it for the **track_updated** event" → plain, bold, plain.
const boldParts = (text) => String(text).split('**');

const maskedUrl = computed(() => {
  const param = props.field.mask_param || 'fcs_token';
  return props.url.replace(new RegExp('([?&]' + param + '=)[^&#]*'), '$1••••••••••••');
});

const copy = () => {
  const done = () => Notify.success(translate('Webhook URL copied'));
  try {
    navigator.clipboard.writeText(props.url).then(done, () => {
      shown.value = true;
      Notify.error(translate('Copy failed. Select the URL and copy it.'));
    });
  } catch (error) {
    shown.value = true;
    Notify.error(translate('Copy failed. Select the URL and copy it.'));
  }
};
</script>
