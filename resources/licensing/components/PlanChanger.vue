<script setup>
import {computed, getCurrentInstance, onMounted, ref} from "vue";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import Str from "../../admin/utils/support/Str";
import AppConfig from "@/utils/Config/AppConfig";

const props = defineProps({
  variations: {},
  product_id: '',
  variation_id: '',
  license: null,
  order: null,
  upgrade_path_base: ''
})

const showModal = ref(false)
const openModal = () => {
  showModal.value = true;
}
const selfRef = getCurrentInstance().ctx;
const selectedVariation = ref('')
const planableVariations = ref([])
let upgradeTo = ref(null)

const currencySign = computed(() => {
   if (AppConfig.get('shop.currency_sign')) {
    return AppConfig.get('shop.currency_sign');
   } else if (window.fluentcart_customer_profile_vars?.shop?.currency_sign) {
      return window.fluentcart_customer_profile_vars.shop.currency_sign;
   }
   return ''; // will get the correct currency sign for the frontend app
})

const getCurrentSubscriptionInfo = computed(() => {
  return props.order?.order_items.find(item => item.payment_type === 'subscription' || item.payment_type === 'adjustment' || item.payment_type === 'onetime');
})

const getVariationDetails = function (variation) {
  let details = '';
  const payment_type = variation?.payment_type;
  const otherInfo = variation?.other_info;
  if (otherInfo?.payment_type === "subscription" && 'adjustment' !== payment_type) {
    if (otherInfo?.manage_setup_fee === 'yes') {
      details += otherInfo.signup_fee_name + ' : ' + currencySign.value + parseFloat(otherInfo.signup_fee / 100) + ', ';
    }

    const occurrence = !Number(otherInfo?.times) ? ', Until Cancel' : otherInfo?.times + ' times';
    details += variation?.formatted_total + ' Subscriptions ' + otherInfo.repeat_interval + ' ' + occurrence;
    return details;
  } else if (otherInfo?.payment_type === "subscription" && 'adjustment' === payment_type) {
    if (otherInfo?.manage_setup_fee === 'yes') {
      details += otherInfo.signup_fee_name + ' :' + parseFloat(otherInfo.signup_fee / 100) + ', ';
    }
    const occurrence = !Number(otherInfo?.times) ? ', Until Cancel' : otherInfo?.times + ' times';
    details += variation?.formatted_total + ' Subscriptions ' + otherInfo.repeat_interval + ' ' + occurrence;
    return details;
  } else if (otherInfo?.payment_type === 'onetime' ) {
    return details += variation?.formatted_total + ' One Time Payment';
  }

  return variation?.other_info.billing_summary;
}

let upgradeDetails = ref('')

const upgradeSelector = (selectedVariation) => {
  upgradeTo = props.variations.find(variation => variation.id.toString() === selectedVariation);
  upgradeDetails.value = getVariationDetails(upgradeTo);
}

const planTitle = (variation) => {
  let otherInfo = variation?.other_info?.payment_type;
  if ('subscription' === otherInfo) {
    return variation?.variation_title + ' - ' + Str.capitalize(variation?.other_info?.repeat_interval);
  }
  return variation?.variation_title + ' - ' + 'One Time Payment';
}

const fetchUpgradeSettings = () => {
  selfRef.$get(`products/${props.product_id}/upgrade-paths`, {
    variation_id: props.variation_id,
  })
      .then((response) => {
        response.data.forEach( (item, key) => {
          if (item.object_id === props.variation_id) {
            item.value.to_variants.forEach( (to_variant, key) => {
              planableVariations.value.push({
                id: to_variant,
                variation_title: planTitle(props.variations.find(variation => variation.id.toString() === to_variant)),
                payment_type: item.payment_type,
                is_prorate: item.value.is_prorate,
                discount_amount: item.value.discount_amount,
              });
            });
          }
        })
      })
}

const getVariationLabel = variation => {
  // need to add discount amount, if any
  let discount_amount = variation.discount_amount;
  if (discount_amount > 0) {
    return variation.variation_title + ' - '
        + (variation.is_prorate.toString() === '1' ? '( Prorated )' : '(Full Charge Applies)')
        + ' ( ' + discount_amount + currencySign.value + ' discount )';
  }
  return variation.variation_title + ' - '
      + (variation.is_prorate.toString() === '1' ? '( Prorated )' : '(Full Charge Applies)');
}

onMounted(() => {
  fetchUpgradeSettings();
})

const generateUpgradeUrl = () => {
  if (selectedVariation.value) {
    const url = new URL(props.upgrade_path_base);
    url.searchParams.append('order_hash', props.order.uuid);
    url.searchParams.append('mode', 'upgrade_plan');
    url.searchParams.append('variation_id', selectedVariation.value);
    return url.href;
  }
  return false;
}
</script>

<template>
  <template v-if="planableVariations.length > 0 && getCurrentSubscriptionInfo">
    <el-button class="el-button--x-small" @click="openModal" type="primary">
      {{ $t('Change Plan') }}
    </el-button>

    <el-dialog
        v-model="showModal"
        :title="$t('Change Plan')"
        :append-to-body="true"
    >
      <div class="el-form--label-top fct-plan-changer-content-wrap">
        <el-form-item :label="$t('Change Plan to')" v-if="planableVariations.length > 0">
          <el-select
              v-model="selectedVariation"
              @change="upgradeSelector"
              clearable
          >
            <el-option
                v-for="variation in planableVariations"
                :key="variation.id"
                :label="getVariationLabel(variation)"
                :value="variation.id.toString()"
            />
          </el-select>
        </el-form-item>

        <el-form-item :label="$t('New Plan')">
          <span v-if="upgradeDetails">
            <span v-html="upgradeDetails" class="fct-plan-changer-text"></span>
          </span>
          <span v-else>---</span>
        </el-form-item>

        <el-form-item :label="$t('Current Plan')">
          <span v-html="getVariationDetails(getCurrentSubscriptionInfo)" class="fct-plan-changer-text"></span>
        </el-form-item>

        <div v-if="generateUpgradeUrl()" class="fct-btn-group">
          <el-button
              type="primary"
              tag="a"
              :href="generateUpgradeUrl()"
              target="_blank"
              rel="noopener noreferrer"
              size="small"
          >
            {{$t(' Upgrade to New Plan')}}
          </el-button>

          <CopyToClipboard
              showMode="basic_copy_btn"
              :text="generateUpgradeUrl()"
          />

        </div>
      </div>
    </el-dialog>
  </template>
</template>
