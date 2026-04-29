<template>
  <div class="fct-upgrade-plan">
    <el-button size="small" @click="showUpgradePlanModal" v-if="has_upgrades">
      {{ $t('Upgrade') }}
    </el-button>


    <el-dialog
        v-model="upgradePlanModal"
        :append-to-body="appendToBody"
        :title="$t('Upgrade')"
        class="fct-upgrade-plan-dialog fluent-cart-customer-profile-app"
    >
      <div v-if="upgradePaths.length > 0" class="fct-upgrade-variation-table">
        <el-table :data="upgradePaths" style="width: 100%">
          <el-table-column :label="$t('Package')" :width="290">
            <template #default="scope">
              <div class="upgrade-title">{{ scope.row.title }}</div>
            </template>
          </el-table-column>

          <el-table-column :label="$t('Price')" :width="100">
            <template #default="scope">
              <div class="plan-price">
                <div class="plan-price">
                  {{ formatNumber(scope.row.price, order.currency) }}
                </div>
              </div>
            </template>
          </el-table-column>


          <el-table-column :label="$t('Amount to pay')">
            <template #default="scope">
              <div class="upgrade-cost">
                <div class="upgrade-title">
                  <span v-html="scope.row.payment_summary"></span>
                  <div v-if="scope.row.prorate_credit" class="upgrade-text">
                  </div>
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column :label="$t('Action')" :width="110">
            <template #default="scope">
              <div class="upgrade-action">
                <el-button class="el-button--x-small" tag="a" :href="scope.row.upgrade_url" target="_blank">
                  {{ $t('Upgrade') }}
                </el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <div v-else>
        {{ $t('No upgrade plan found') }}
      </div>

    </el-dialog>
  </div>
</template>

<script>
import Str from "@/utils/support/Str";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import Rest from "@/utils/http/Rest";
import AppConfig from "@/utils/Config/AppConfig";

export default {
  name: 'UpgradePlan',
  components: {
    CopyToClipboard,
    DynamicIcon
  },
  props: {
    variation_id: {
      type: [String, Number],
      required: true
    },
    is_admin: {
      type: Boolean,
      required: false,
      default: false
    },
    product_id: {
      type: [String, Number],
      required: true
    },
    order: {
      type: Object,
      required: true
    },
    has_upgrades: {
      type: Boolean,
      required: true
    },
    props_variations: {
      type: [Array, Object],
      required: true
    },
    endpoint_path: {
      type: String,
      default: 'products'
    },
    appendToBody: {
      type: Boolean,
      default: true
    }
  },
  data() {
    return {
      variations: this.props_variations,
      upgradePlanModal: false,
      selectedUpgradePath: '',
      upgradePaths: [],
    }
  },
  computed: {
    currencySign() {
      if (AppConfig.get('shop.currency_sign')) {
        return AppConfig.get('shop.currency_sign');
      } else if (window.fluentcart_customer_profile_vars?.shop?.currency_sign) {
        return window.fluentcart_customer_profile_vars.shop.currency_sign;
      }
      return ''; // will get the correct currency sign for the frontend app
    }
  },
  methods: {
    translate,
    showUpgradePlanModal() {
      // call the rest api to get upgrade-paths
      Rest.get(`${this.endpoint_path}/variation/${this.variation_id}/upgrade-paths`, {
        params: {
          variation_id: this.variation_id,
          order_hash: this.order.uuid
        }
      }).then((response) => {
        this.upgradePaths = response.upgradePaths;
        this.upgradePlanModal = true;
      })
    },
    closeUpgradePlanModal() {
      this.upgradePlanModal = false;
    }
  },
}
</script>
