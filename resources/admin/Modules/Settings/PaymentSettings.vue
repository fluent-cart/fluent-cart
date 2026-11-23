<template>
  <SettingsPage
      :heading="translate('Payment Settings')"
      :show-save-button="false"
  >
    <AdminNotice/>

    <Section>
      <fct-skeleton v-if="loading" animated :rows="6"/>
      <SortableList
          v-if="!loading"
          v-model="availableGateways"
          class="fct-payment-method-list"
          item-key="route"
          :handle-label="gateway => translate('Reorder %1$s — drag, or use arrow up and down keys', gateway.admin_title || gateway.title)"
          @reorder="onDragEnd"
      >
        <template #item="{item: gateway, handle}">
          <ListItem
              :class="gateway?.upcoming ? 'upcoming' : 'cursor-pointer'"
              @click="openGateway(gateway)"
          >
            <template #handle>
              <fct-tooltip :content="translate('Drag to reorder. Checkout shows payment methods in this order.')" placement="top">
                <component :is="handle"/>
              </fct-tooltip>
            </template>

            <template #title>
              <!-- The logo is the keyboard path to the manage page; the row click is for the mouse. -->
              <component
                  :is="gateway?.upcoming ? 'span' : 'router-link'"
                  :to="gateway?.upcoming ? undefined : {name: gateway.route}"
                  :class="['fct-payment-method-logo', gateway.route]"
                  class="inline-flex rounded focus-visible:outline-2 focus-visible:outline-offset-2"
                  @click.stop
              >
                <img :src="gateway.logo" :alt="gateway.admin_title || gateway.title" :class="gateway.logo_light ? 'dark:hidden' : ''"/>

                <img v-if="gateway.logo_light" :src="gateway.logo_light" :alt="gateway.admin_title || gateway.title" class="hidden dark:block"/>
              </component>
            </template>

            <template #badges>
              <span v-if="gateway?.tag" class="fct_payment_method_tag">
                <Badge size="small" status="info" :text="gateway.tag" />
              </span>

              <Badge v-if="needsPro(gateway)" size="small" status="warning" :text="translate('Requires Pro')" :hide-icon="true"/>

              <Badge v-if="showBadge(gateway)" size="small" :status="gateway.status ? 'active' : 'disabled'"
                     :hide-icon="true">
                {{ getBadgeTitle(gateway.status) }}
              </Badge>
            </template>

            <template #meta>{{ gateway.description }}</template>

            <template #actions>
              <div class="flex items-center" @click.stop>
                <div v-if="gateway?.upcoming">
                  {{ translate('Coming Soon!') }}
                </div>

                <!-- Same as the gateway's settings page: a Pro-only add-on cannot install or activate without Pro. -->
                <fct-button
                    v-else-if="needsPro(gateway)"
                    type="primary"
                    size="x-small"
                    tag="a"
                    :href="gateway.addon_source?.repo_link || upgradeUrl('feature_lock_gateway_' + gateway.route)"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                  {{ translate('Upgrade to Pro') }}
                </fct-button>

                <fct-button
                    v-else-if="gateway?.addon_status && !gateway.addon_status.is_installed"
                    type="primary"
                    size="x-small"
                    :loading="busyRoute === gateway.route"
                    :disabled="!!busyRoute"
                    @click="installAddon(gateway)"
                >
                  {{ isProActive ? translate('Install & Activate') : translate('Download') }}
                </fct-button>

                <fct-button
                    v-else-if="gateway?.addon_status && !gateway.addon_status.is_active"
                    type="success"
                    size="x-small"
                    :loading="busyRoute === gateway.route"
                    :disabled="!!busyRoute"
                    @click="activateAddon(gateway)"
                >
                  {{ translate('Activate') }}
                </fct-button>

                <fct-button v-else size="x-small"
                           @click="openGateway(gateway)">
                  {{ translate('Manage') }}
                  <img v-if="gateway?.requires_pro" :src="appVars?.asset_url + 'images/crown.svg'" alt="pro feature" class="pro-feature-icon">
                </fct-button>
              </div>
            </template>
          </ListItem>
        </template>
      </SortableList><!-- .payment-method-list -->
    </Section>
  </SettingsPage>
</template>

<script setup>
import {ref, onMounted, getCurrentInstance} from 'vue';
import Badge from "@/Bits/Components/Badge.vue";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import { useRouter } from 'vue-router';
import AppConfig from "@/utils/Config/AppConfig";
import Url from "@/utils/support/Url";
import {upgradeUrl} from "@/Bits/common";
import SettingsPage from "@/Modules/Settings/Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import SortableList from "@/Bits/Components/Layout/SortableList.vue";
import ListItem from "@/Bits/Components/Layout/ListItem.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";

const selfRef = getCurrentInstance().ctx;
const router = useRouter();
const loading = ref(false);
const availableGateways = ref([]);
const isProActive = AppConfig.get('app_config.isProActive');

const showBadge = (gateway) => {
  if (gateway?.upcoming == true) {
    return false;
  }
  if (gateway?.requires_pro && !isProActive) {
    return false;
  }
  // Hide only for inactive, uninstalled addon placeholders; an addon gateway that
  // registered itself without reporting addon_status is installed by definition,
  // and an active gateway always gets its badge.
  if (gateway?.is_addon == true && !gateway?.status && gateway?.addon_status && !gateway.addon_status.is_installed) {
    return false;
  }
  return true;
};

const busyRoute = ref('');

// A Pro-only add-on that is not running yet; once active it registers its own gateway.
const needsPro = (gateway) => !!gateway?.requires_pro && !isProActive && !!gateway?.addon_status && !gateway.addon_status.is_active;

const openGateway = (gateway) => {
  if (!gateway?.upcoming) {
    router.push({name: gateway.route});
  }
};

// Same endpoints and free/Pro split as the gateway's own settings page (PaymentComponet/Renderer.vue).
const installAddon = (gateway) => {
  if (!isProActive) {
    window.open(gateway.addon_source?.repo_link, '_blank', 'noopener,noreferrer');
    return;
  }
  busyRoute.value = gateway.route;
  selfRef.$post('settings/payment-methods/install-addon', {
    plugin_slug: gateway.addon_source?.slug
  })
      .then(() => Url.reloadAt(router.resolve({name: gateway.route}).path))
      .catch((error) => {
        Notify.error(error?.data?.message || translate('Failed to install addon'));
        busyRoute.value = '';
      });
};

const activateAddon = (gateway) => {
  busyRoute.value = gateway.route;
  selfRef.$post('settings/payment-methods/activate-addon', {
    plugin_file: gateway.addon_status.plugin_file
  })
      .then(() => Url.reloadAt(router.resolve({name: gateway.route}).path))
      .catch((error) => {
        Notify.error(error?.data?.message || translate('Failed to activate addon'));
        busyRoute.value = '';
      });
};

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
      })
      .finally(() => {
        loading.value = false;
      });
};

const onDragEnd = () => {
  // Save the new order
  const order = availableGateways.value.map(g => g.route);
  selfRef.$post('settings/payment-methods/reorder', { order })
      .then((response) => {
        Notify.success(response.message);
      })
      .catch((e) => {
      });
};

onMounted(() => {
  getPaymentMethods();
});

</script>

