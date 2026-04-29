<script setup>
import {computed, getCurrentInstance, ref} from 'vue';
import AppConfig from '@/utils/Config/AppConfig';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';

const props = defineProps({
  variant: {
    type: Object,
    required: true,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['changed']);

const self = getCurrentInstance().ctx;
const appVars = AppConfig.get();
const hasPro = AppConfig.get('app_config.isProActive');

const popoverVisible = ref(false);

const currencySign = computed(() => appVars?.shop?.currency_sign || '$');

const otherInfo = computed(() => props.variant?.other_info || {});

const isSubscription = computed(() => otherInfo.value.payment_type === 'subscription');

const hasInstallment = computed(() => otherInfo.value.installment === 'yes');

const installmentCount = computed(() => Number(otherInfo.value.times) || 1);

const totalPrice = computed(() => {
  const price = Number(props.variant?.item_price) || 0;
  return hasInstallment.value ? price * installmentCount.value : price;
});

const tooltipContent = computed(() => {
  const parts = [];
  if (hasInstallment.value) {
    parts.push(self.$t('Installment') + ': ' + installmentCount.value + 'x');
  }
  if (otherInfo.value.manage_setup_fee === 'yes') {
    const name = otherInfo.value.signup_fee_name || self.$t('Setup fee');
    const amount = otherInfo.value.signup_fee || 0;
    parts.push(self.$t('Setup fee') + ': ' + name + ' (' + currencySign.value + amount + ')');
  }
  return parts.length > 0 ? parts.join('\n') : self.$t('Subscription settings');
});

const onInstallmentChange = (val) => {
  if (val === 'no') {
    props.variant.other_info.times = '';
  } else if (!props.variant.other_info.times) {
    props.variant.other_info.times = 1;
  }
  emit('changed');
};

const onSetupFeeChange = (val) => {
  if (val === 'no') {
    props.variant.other_info.signup_fee = '';
  }
  emit('changed');
};

const onFieldChange = () => {
  emit('changed');
};

const closePopover = () => {
  popoverVisible.value = false;
};
</script>

<template>
  <el-tooltip
    :content="tooltipContent"
    placement="top"
    :disabled="!isSubscription || popoverVisible"
    popper-class="fct-tooltip"
    :show-after="400"
  >
    <el-popover
      v-model:visible="popoverVisible"
      placement="bottom"
      :width="320"
      trigger="click"
      :disabled="disabled || !isSubscription"
      popper-class="bulk-sub-popover"
    >
      <template #reference>
        <button
          class="fc-bulk-sub-icon-btn"
          :class="{
            'is-active': isSubscription,
            'is-configured': isSubscription && (hasInstallment || otherInfo.manage_setup_fee === 'yes'),
          }"
          :disabled="disabled || !isSubscription"
          @click.prevent
        >
          <!-- Loop/repeat icon -->
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
            <path d="M6 4H21C21.5523 4 22 4.44772 22 5V12H20V6H6V9L1 5.5L6 2V4ZM18 20H3C2.44772 20 2 19.5523 2 19V12H4V18H18V15L23 18.5L18 22V20Z"/>
          </svg>
          <!-- Installment count badge -->
          <span v-if="hasInstallment" class="fct-bulk-sub-count">{{ installmentCount }}</span>
        </button>
      </template>

    <div class="fct-bulk-sub-popover-body">
      <h4 class="fct-bulk-sub-popover-title">{{ $t('Subscription Settings') }}</h4>

      <!-- Installment Payment -->
      <div class="fct-bulk-sub-section">
        <div class="fct-bulk-sub-row">
          <el-checkbox
            true-value="yes"
            false-value="no"
            :label="$t('Enable installment payment')"
            v-model="variant.other_info.installment"
            :disabled="!hasPro || disabled"
            @change="onInstallmentChange"
          />
          <el-tooltip v-if="!hasPro" popper-class="fct-tooltip">
            <template #content>{{ $t('This feature is available in pro version only.') }}</template>
            <DynamicIcon name="Crown" class="fct-pro-icon" />
          </el-tooltip>
        </div>

        <template v-if="otherInfo.installment === 'yes'">
          <div class="fct-bulk-sub-row">
            <label class="fct-bulk-sub-label">{{ $t('Installment Count') }}</label>
            <el-input
              type="number"
              :min="1"
              size="small"
              v-model.number="variant.other_info.times"
              :placeholder="$t('Count')"
              style="width: 100px;"
              @input="onFieldChange"
            />
          </div>
          <div class="fct-bulk-sub-row">
            <span class="fct-bulk-sub-label">{{ $t('Total Price') }}</span>
            <span class="fct-bulk-sub-value" v-html="currencySign + totalPrice"></span>
          </div>
        </template>
      </div>

      <!-- Setup Fee -->
      <div class="fct-bulk-sub-section">
        <div class="fct-bulk-sub-row">
          <el-switch
            :active-text="$t('Setup fee')"
            v-model="variant.other_info.manage_setup_fee"
            active-value="yes"
            inactive-value="no"
            :disabled="!hasPro || disabled"
            size="small"
            @change="onSetupFeeChange"
          />
          <el-tooltip v-if="!hasPro" popper-class="fct-tooltip">
            <template #content>{{ $t('This feature is available in pro version only.') }}</template>
            <DynamicIcon name="Crown" class="fct-pro-icon"/>
          </el-tooltip>
        </div>

        <template v-if="otherInfo.manage_setup_fee === 'yes'">
          <div class="fct-bulk-sub-row">
            <label class="fct-bulk-sub-label">{{ $t('Label') }}</label>
            <el-input
              size="small"
              v-model="variant.other_info.signup_fee_name"
              :placeholder="$t('e.g. Initial Setup')"
              @input="onFieldChange"
            />
          </div>
          <div class="fct-bulk-sub-row">
            <label class="fct-bulk-sub-label">{{ $t('Amount') }}</label>
            <el-input
              size="small"
              type="number"
              :min="0"
              v-model.number="variant.other_info.signup_fee"
              :placeholder="$t('Amount')"
              @input="onFieldChange"
            >
              <template #prefix>
                <span v-html="currencySign"></span>
              </template>
            </el-input>
          </div>
        </template>
      </div>

      <div class="fct-bulk-sub-footer">
        <el-button size="small" type="primary" @click="closePopover">{{ $t('Done') }}</el-button>
      </div>
    </div>
    </el-popover>
  </el-tooltip>
</template>


