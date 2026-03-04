<template>
  <div class="fct-setting-container setting-container" :class="{
    'is-collapsed': isSidebarCollapsed,
    'is-expanded': isSidebarExpanded
  }">

    <div
        ref="overlayRef"
        class="fct-settings-menu-overlay"
        @click="closeMenu"
    />

    <div class="fct-settings-nav-wrap">
      <div ref="sidebarRef" class="fct-settings-nav-container"
           :class="{
            'is-collapsed': isSidebarCollapsed,
            'is-expanded': isSidebarExpanded
          }"
      >
        <div class="fct-settings-nav-collapse-button-wrapper">
          <el-tooltip
              :content="translate('Toggle settings')"
              placement="right"
          >
            <el-button
                @click="toggleCollapse"
            >
              <DynamicIcon name="Window" />

              <span class="fct-menu-collapse-button-text">
                {{ translate('Settings') }}
              </span>
            </el-button>
          </el-tooltip>
        </div>

        <ul
            class="fct-settings-nav"
            @mouseenter="isDesktopView && isMenuCollapsed && (isMenuExpanded = true)"
            @mouseleave="isMenuExpanded = false"
        >
          <li v-for="(route, i) in routes" :key="i" class="fct-settings-nav-item" :class="{'fct-settings-nav-item-active': isRouteActive(route)}">
            <router-link v-if="Permission.hasAny(route.permission)" :to="route.url" class="fct-settings-nav-link">
              <DynamicIcon :name="route.icon"/>

              <span class="fct-settings-nav-link-text">
                {{ route.name }}
                <DynamicIcon name="ChevronRight" class="fct-settings-nav-link-icon"/>
              </span>
            </router-link>

            <!-- Child Components -->
            <Animation
                :visible="isDropdownVisible(route)"
                accordion
                class="fct-settings-nav-child-wrap"
            >
              <ul class="fct-settings-nav-child-list">
                <li
                    v-for="(child, i) in route.child"
                    :key="i"
                    class="fct-settings-nav-item"
                    :class="{ 'fct-settings-nav-item-active': isChildActive(child, i, route) }"
                >
                  <router-link :to="child.url" class="fct-settings-nav-link">
                    {{ child.name }}
                  </router-link>
                </li>
              </ul>
            </Animation>
          </li>
        </ul>
      </div>

      <div class="fct-settings-nav-content">
        <div class="fct-settings-nav-content-inner">
          <AdminNotice/>
          <router-view/>
        </div>
      </div>
    </div>

  </div>
  <!-- .setting-container -->
</template>

<script setup>
import {onMounted, onUnmounted, ref, computed} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {useRouter, useRoute} from 'vue-router';
import translate from "@/utils/translator/Translator";
import Animation from "@/Bits/Components/Animation.vue";
import AppConfig from "@/utils/Config/AppConfig";
import Permission from "@/utils/permission/Permission";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";

defineOptions({
  name: "SettingsView",
});

const route = useRoute();
const router = useRouter();
const isDesktopView = ref(window.innerWidth >= 1024);
const isMenuCollapsed = ref(false);
const isMenuExpanded = ref(false);
const sidebarRef = ref(null);
const overlayRef = ref(null);
const pluginAppWrap = ref(null);

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
        name: translate('Subscriptions'),
        url: '/settings/store-settings/subscriptions'
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

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

const toggleCollapse = () => {
  // If not desktop → removes open class and stops
  if (!isDesktopView.value) {
    if (sidebarRef.value) {
      sidebarRef.value.classList.remove('is-nav-open');
    }
    if (overlayRef.value) {
      overlayRef.value.classList.remove('is-overlay-open');
    }
    return;
  }

  isMenuCollapsed.value = !isMenuCollapsed.value;
  isMenuExpanded.value = false;
};

const closeMenu = () => {
  // Only work on mobile
  if (isDesktopView.value) return;

  if (sidebarRef.value) {
    sidebarRef.value.classList.remove('is-nav-open');
  }

  if (overlayRef.value) {
    overlayRef.value.classList.remove('is-overlay-open');
  }
};

const isDropdownVisible = (route) => {
  if (!route.child) return false;

  // If the sidebar is visually expanded (normal or hover)
  const isVisuallyExpanded = !isSidebarCollapsed.value || isSidebarExpanded.value;

  return isVisuallyExpanded && isRouteActive(route);
};
/*
|--------------------------------------------------------------------------
| Responsive Handling
|--------------------------------------------------------------------------
*/
const updateViewportMode = () => {
  const width = window.innerWidth;
  const wasDesktop = isDesktopView.value;

  isDesktopView.value = width >= 1024;

  // Switching from mobile → desktop
  if (!wasDesktop && isDesktopView.value) {
    // Remove mobile open classes
    if (sidebarRef.value) {
      sidebarRef.value.classList.remove('is-nav-open');
    }

    if (overlayRef.value) {
      overlayRef.value.classList.remove('is-overlay-open');
    }
  }

  // Switching from desktop → mobile
  if (wasDesktop && !isDesktopView.value) {
    // Remove desktop collapse visually
    isMenuCollapsed.value = false;
  }
};

onMounted(() => {
  pluginAppWrap.value = document.getElementById('fluent_cart_plugin_app');

  if (pluginAppWrap.value) {
    pluginAppWrap.value.classList.add('fct_settings_page_plugin_app_wrap');
  }

  updateViewportMode();
  window.addEventListener('resize', updateViewportMode);
});

onUnmounted(() => {
  if (pluginAppWrap.value) {
    pluginAppWrap.value.classList.remove('fct_settings_page_plugin_app_wrap');
  }
  window.removeEventListener('resize', updateViewportMode);
});

/*
|--------------------------------------------------------------------------
| Computed States
|--------------------------------------------------------------------------
*/

const isSidebarCollapsed = computed(() => {
  return isMenuCollapsed.value;
});

const isSidebarExpanded = computed(() => {
  return isDesktopView.value &&
      isMenuCollapsed.value &&
      isMenuExpanded.value;
});


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
