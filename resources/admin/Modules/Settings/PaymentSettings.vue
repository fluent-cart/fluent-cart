<template>
  <div class="setting-wrap">
    <Card.Container>
      <Card.Header :title="$t('Payment Settings')" border_bottom/>
      <Card.Body>
        <el-skeleton :loading="loading" animated :rows="6"/>
        <div v-if="!loading" class="payment-method-list fct-content-card-list">
          <VueDraggableNext
              v-bind="dragOptions"
              :list="availableGateways"
              item-key="route"
              handle=".drag-handle"
              @end="onDragEnd"
          >
            <div
                v-for="gateway in availableGateways"
                :key="gateway.route"
                :class="gateway?.upcoming ? 'upcoming fct-content-card-list-item' : 'fct-content-card-list-item'"
            >
              <div class="flex items-start gap-3">
                <span class="drag-handle cursor-move text-gray-400 hover:text-gray-600 mt-1 w-7.5 h-7.5" title="Drag to reorder">
                  <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M8 6h8v2H8V6zm0 4h8v2H8v-2zm0 4h8v2H8v-2z"/>
                  </svg>
                </span>
                
                <div @click="() => {
                  if(!gateway?.addon_status?.is_installed && gateway?.addon_source){
                    return;
                  }
                  $router.push({name: gateway.route})
                }" class="cursor-pointer grid gap-2 flex-1">
                  <div class="fct-content-card-list-head" :class="gateway.route">
                    <img :src="gateway.icon" :alt="gateway.admin_title || gateway.title"/>

                    <span v-if="gateway?.tag" class="fct_payment_method_tag">
                      <Badge size="small" status="info" :text="gateway.tag" />
                    </span>

                    <Badge v-if="gateway?.upcoming === false && !gateway?.requires_pro && !gateway?.is_addon" size="small" :status="gateway.status ? 'active' : 'disabled'"
                           :hide-icon="true">
                      {{ getBadgeTitle(gateway.status) }}
                    </Badge>
                  </div>

                  <div class="fct-content-card-list-content">
                    <p>{{ gateway.description }}</p>
                  </div>
                </div>

                <div class="fct-content-card-list-action">
                  <template v-if="gateway?.is_addon">
                    <el-button v-if="(!gateway?.addon_status?.is_installed && gateway?.addon_source) || (gateway?.addon_status?.is_active && !gateway?.addon_status?.is_installed)"
                               class="el-button--x-small el-button--primary"
                               :loading="installingAddon === gateway.route"
                               @click="() => installAndActivateAddon(gateway)">
                      {{ $t('Install & Activate') }}
                    </el-button>
                    <el-button v-else-if="gateway?.addon_status?.is_installed && !gateway?.addon_status?.is_active"
                               class="el-button--x-small el-button--primary"
                               :loading="activatingAddon === gateway.route"
                               @click="() => activateAddon(gateway)">
                      {{ $t('Activate') }}
                    </el-button>
                    <Badge v-else-if="gateway?.addon_status?.is_active && gateway?.addon_status?.is_plugin_installed" size="small" status="active" :hide-icon="true">
                      {{ $t('Active') }}
                    </Badge>
                    <div v-else>
                      {{ translate('Coming Soon!') }}
                    </div>
                  </template>
                  <div v-else-if="gateway?.upcoming">
                    {{ translate('Coming Soon!') }}
                  </div>
                  <el-button v-else-if="gateway?.requires_pro" class="el-button--x-small el-button--primary" @click="() => routeToUpgrade()">
                    {{ $t('Upgrade to Pro') }}
                  </el-button>
                  <el-button v-else class="el-button--x-small"
                             @click="() => $router.push({name: gateway.route})">
                    {{ $t('Manage') }}
                  </el-button>
                </div>
              </div>
            </div>
          </VueDraggableNext>
        </div><!-- .payment-method-list -->
      </Card.Body>
    </Card.Container>
  </div><!-- .setting-wrap -->
</template>

<script setup>
import {ref, onMounted, getCurrentInstance, computed} from 'vue';
import {useRoute, useRouter} from 'vue-router';
import * as Card from '@/Bits/Components/Card/Card.js';
import Badge from "@/Bits/Components/Badge.vue";
import Asset from "@/utils/support/Asset";
import translate from "@/utils/translator/Translator";
import { VueDraggableNext } from 'vue-draggable-next';
import Notify from "@/utils/Notify";


const selfRef = getCurrentInstance().ctx;
const loading = ref(false);
const availableGateways = ref([]);
const route = useRoute();
const router = useRouter();
const installingAddon = ref(null);
const activatingAddon = ref(null);

const dragOptions = computed(() => {
  return {
    animation: 200,
    ghostClass: 'ghost'
  }
});

const getBadgeTitle = (status) => {
  return status === true ? 'active' : 'inactive';
};

const getPaymentMethods = () => {
  loading.value = true;
  selfRef.$get('settings/payment-methods/all', {})
      .then(response => {
        availableGateways.value = response.gateways;
      })
      .catch((e) => {
        console.error(e);
      })
      .finally(() => {
        loading.value = false;
      });
};

const routeToUpgrade = () => {
  window.open('https://fluentcart.com/discount-deal', '_blank');
}

const getUpcoming = () => {
  return Asset.getUrl('images/payment-methods/upcoming.png');
}

const onDragEnd = () => {
  // Save the new order
  const order = availableGateways.value.map(g => g.route);
  selfRef.$post('settings/payment-methods/reorder', { order })
      .then(() => {
        // Order saved successfully
      })
      .catch((e) => {
        console.error('Failed to save payment method order:', e);
      });
};

const installAndActivateAddon = (gateway) => {
  if (!gateway.addon_source) {
    return;
  }
  
  installingAddon.value = gateway.route;
  selfRef.$post('settings/payment-methods/install-addon', { 
    source_type: gateway.addon_source.type,
    source_link: gateway.addon_source.link,
    plugin_slug: gateway.addon_source.slug
  })
      .then(response => {
        Notify.success(response.message || 'Payment addon installed and activated successfully!');
        getPaymentMethods();
      })
      .catch((error) => {
        Notify.error(error.message || 'Failed to install payment addon');
      })
      .finally(() => {
        installingAddon.value = null;
      });
};

const activateAddon = (gateway) => {
  if (!gateway.addon_status?.plugin_file) {
    return;
  }
  
  activatingAddon.value = gateway.route;
  selfRef.$post('settings/payment-methods/activate-addon', { 
    plugin_file: gateway.addon_status.plugin_file 
  })
      .then(response => {
        Notify.success(response.message || 'Payment addon activated successfully!');
        getPaymentMethods();
      })
      .catch((error) => {
        Notify.error(error.message || 'Failed to activate payment addon');
      })
      .finally(() => {
        activatingAddon.value = null;
      });
};

onMounted(() => {
  getPaymentMethods();
});

</script>
<style lang="scss">
.fct_upcoming_badge {
  position: absolute;
  top: 23px;
  right: 139px;
}

.upcoming .fct-content-card-list-head img {
  opacity: 0.6;
}
</style>

