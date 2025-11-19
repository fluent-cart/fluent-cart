<template>
  <div class="fct-setting-container setting-container">
    <div class="fct-settings-menu-overlay" @click="toggleMenu" v-if="isMenuOpen"></div>
    <div class="fct-settings-menu-toggle">
      <el-button @click="toggleMenu">
        <div class="relative w-6 h-6">
          <Animation :visible="isMenuOpen" fade>
            <DynamicIcon name="Cross" class="absolute justify-center inset-0 w-full h-full"/>
          </Animation>
          <Animation :visible="!isMenuOpen" fade>
            <DynamicIcon name="Setting" class="absolute justify-center inset-0 w-full h-full"/>
          </Animation>
        </div>
        {{ translate('Settings') }}
      </el-button>
    </div>
    <Tab.Container @mouseenter="disableBodyScroll" @mouseleave="enableBodyScroll">
      <Tab.Items :class="{'is-open': isMenuOpen}">
        <Tab.Item v-for="(route, i) in routes" :key="i" :class="{'fct-tab-item-active': isRouteActive(route)}">
          <Tab.Link v-if="Permission.hasAny(route.permission)" :to="route.url">
            <DynamicIcon :name="route.icon"/>
            {{ route.name }}
            <DynamicIcon name="ChevronRight" class="tab-icon-right"/>
          </Tab.Link>
          <!-- Child Components -->
          <Animation :visible="isRouteActive(route) && route.child" accordion class="fct-settings-menu-child-wrap">
            <Tab.Item v-for="(child, i) in route.child" :key="i" @click="toggleMenu"
                      :class="{ 'fct-tab-item-active': isChildActive(child, i, route) }"
            >
              <Tab.Link :to="child.url">
                {{ child.name }}
              </Tab.Link>
            </Tab.Item>
          </Animation>
        </Tab.Item>
      </Tab.Items>

      <Tab.Content>
        <div class="fct-tab-content-inner">
          <AdminNotice/>
          <router-view/>
        </div>
      </Tab.Content>
    </Tab.Container>
  </div>
  <!-- .setting-container -->
</template>

<script setup>
import {ref} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import * as Tab from '@/Bits/Components/Tab/Tab.js';
import {useRouter, useRoute} from 'vue-router';
import translate from "@/utils/translator/Translator";
import Animation from "@/Bits/Components/Animation.vue";
import AppConfig from "@/utils/Config/AppConfig";
import Permission from "@/utils/permission/Permission";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import RoleSettings from "@/Modules/Settings/Roles/RoleSettings.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";

defineOptions({
  name: "SettingsView",
});

const route = useRoute();
const router = useRouter();

const isModuleTabEnabled = AppConfig.config.get('isModuleTabEnabled');
const isMenuOpen = ref(false);

const disableBodyScroll = () => {
  // document.body.style.overflow = 'hidden';
};

const enableBodyScroll = () => {
  document.body.style.overflow = '';
};

Permission.has('store/settings');

const routes = ref([
  {
    name: translate("Store Settings"),
    icon: "Gift",
    permission: ["store/settings", 'store/sensitive'],
    url: '/settings/store-settings/',
    child: [
      {
        name: translate('Store Setup'),
        url: '/settings/store-settings/'
      },
      {
        name: translate('Pages Setup'),
        url: '/settings/store-settings/pages_setup'
      },
      {
        name: translate('Product Page'),
        url: '/settings/store-settings/single_product_setup'
      },
      {
        name: translate('Cart & Checkout'),
        url: '/settings/store-settings/cart_and_checkout'
      },
      {
        name: translate('Checkout Fields'),
        url: '/settings/store-settings/checkout_fields'
      }

    ]
  },
  {
    name: translate("Payment Settings"),
    icon: "PaymentIcon",
    permission: ["is_super_admin"],
    url: '/settings/payments'
  },
  {
    name: translate("Invoice & Packing"),
    icon: "Invoice",
    permission: ["is_super_admin"],
    url: '/settings/invoice-packing'
  },
  {
    name: translate("Tax & Duties"),
    icon: "Tax",
    permission: ["is_super_admin"],
    url: '/settings/tax_settings',
    child: [
      {
        name: translate('Configuration & Classes'),
        url: '/settings/tax_settings'
      },
      // {
      //   name: translate('Tax Classes'),
      //   url: '/settings/tax_settings/tax_classes'
      // },
      {
        name: translate('Rates'),
        url: '/settings/tax_settings/tax_rates'
      },
      {
        name: translate('European Union'),
        url: '/settings/tax_settings/eu'
      },
    ]
  },
  {
    name: translate('Email Configuration'),
    icon: 'Message',
    permission: ["store/sensitive"],
    url: '/settings/email_mailing_settings',
    child: [
      {
        name: translate('Mailing Settings'),
        url: '/settings/email_mailing_settings'
      },
      {
        name: translate('Notifications'),
        url: '/settings/email_notifications'
      }
    ]
  },

  {
    name: translate('Roles and Permissions'),
    icon: 'ShieldCheck',
    permission: ["is_super_admin"],
    url: '/settings/roles'
  },
  {
    name: translate('Storage Settings'),
    icon: 'Database',
    permission: ["is_super_admin"],
    url: '/settings/storage'
  }
]);


if (router.hasRoute('shipping')) {
  routes.value.push({
    name: translate('Shipping'),
    icon: 'Truck',
    permission: ["store/sensitive"],
    url: '/settings/shipping',
    child: [
      {
        name: translate('Shipping Zones'),
        permission: ["store/sensitive"],
        url: '/settings/shipping'
      },
      {
        name: translate('Shipping Classes'),
        permission: ["store/sensitive"],
        url: '/settings/shipping/shipping_classes'
      }
    ]
  });
}

routes.value.push({
  name: translate('Features & addon'),
  icon: "Module",
  permission: ["is_super_admin"],
  url: '/settings/addons'
},);


const hasPro = AppConfig.get('app_config.isProActive');
if (hasPro) {
  routes.value.push({
    name: translate('License Settings'),
    icon: 'License',
    permission: "store/sensitive",
    url: '/settings/licensing'
  });
}

const toggleMenu = () => {
  isMenuOpen.value = !isMenuOpen.value;
}


const isRouteActive = (tabRoute) => {
  const currentPath = route.path;

  // First check if any child route matches exactly
  if (tabRoute.child && tabRoute.child.length) {
    const childMatches = tabRoute.child.some(child => currentPath === child.url || currentPath.includes(child.url));
    if (childMatches) {
      return true;
    }
  }

  // Then check if current path exactly matches the parent route
  if (currentPath === tabRoute.url || currentPath.includes(tabRoute.url)) {
    return true;
  }
  return false;
};

const isChildActive = (child, index, routeGroup) => {
  const currentPath = route.path;

  // Exact match for child
  if (currentPath === child.url) {
    return true;
  }

  // Special case: when on parent route, mark first child active
  if (currentPath === routeGroup.url && index === 0) {
    return true;
  }

  return false;
};
</script>
