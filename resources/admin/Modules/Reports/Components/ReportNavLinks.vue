<template>
  <div class="fct-report-nav-links-wrap">
    <div
        ref="overlayRef"
        class="fct-settings-menu-overlay"
        @click="closeMenu"
    />

    <div
        ref="sidebarRef"
        class="fct-settings-nav-container"
        :class="{
          'is-collapsed': isSidebarCollapsed,
          'is-expanded': isSidebarExpanded,
        }"
    >
      <div class="fct-settings-nav-collapse-button-wrapper">
        <el-tooltip
            :content="translate('Toggle reports')"
            placement="right"
        >
          <el-button
              @click="toggleCollapse"
          >
            <DynamicIcon name="Window" />

            <span class="fct-menu-collapse-button-text">
                {{ translate('Reports') }}
              </span>
          </el-button>
        </el-tooltip>
      </div>

      <ul
          class="fct-settings-nav"
          @mouseenter="isDesktopView && isMenuCollapsed && (isMenuExpanded = true)"
          @mouseleave="isMenuExpanded = false"
      >
        <li v-for="(route, i) in navLinks" :key="i" class="fct-settings-nav-item" :class="{'fct-settings-nav-item-active': isRouteActive(route)}">
          <div @click="handleRouterPush(route)" class="fct-settings-nav-link">
            <DynamicIcon v-if="route.icon" :name="route.icon" class="w-5 h-5"/>

            <span class="fct-settings-nav-link-text">
                {{ route.label }}
                <DynamicIcon name="ChevronRight" class="fct-settings-nav-link-icon"/>
              </span>
          </div>

          <!-- Child Components -->
          <Animation
              accordion
              :visible="shouldShowDropdown(route)"
              class="fct-settings-nav-child-wrap"
          >
            <ul class="fct-settings-nav-child-list">
              <li
                  v-for="(child, i) in route.child"
                  :key="i"
                  class="fct-settings-nav-item"
                  :class="{ 'fct-settings-nav-item-active': isChildActive(child, i, route) }"
              >
                <div @click="handleRouterPush(child)" class="fct-settings-nav-link">
                  {{ child.label }}
                </div>
              </li>
            </ul>
          </Animation>
        </li>
      </ul>
    </div>


  </div>
</template>

<script setup>
import { useRouter, useRoute } from "vue-router";
import { ref, onMounted, onUnmounted, watch, computed } from "vue";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Animation from "@/Bits/Components/Animation.vue";

const router = useRouter();
const route = useRoute();

const navLinks = [
  {
    route: 'reports_overview',
    label: translate('Overview'),
    url: '/reports/overview',
    icon: 'Overview'
  },
  {
    route: 'reports_sales',
    label: translate('Sales'),
    url: '/reports/sales',
    icon: 'LineChart'
  },
  {
    route: 'reports_orders',
    label: translate('Orders'),
    url: '/reports/orders',
    icon: 'Cart'
  },
  {
    route: 'reports_revenue',
    label: translate('Revenue'),
    url: '/reports/revenue',
    icon: 'Revenue'
  },
  {
    route: 'reports_refunds',
    label: translate('Refunds'),
    url: '/reports/refunds',
    icon: 'Refund'
  },
  {
    route: 'reports_subscriptions',
    label: translate('Subscriptions'),
    url: '/reports/subscriptions',
    icon: 'Subscription',
    always_open: true,
    child: [
      {
        route: 'subscriptions-retention',
        label: translate('Retention'),
        url: '/reports/subscriptions/subscriptions-retention'
      },
      {
        route: 'subscriptions-cohorts',
        label: translate('Cohorts'),
        url: '/reports/subscriptions/subscriptions-cohorts'
      },
      {
        route: 'future_renewals',
        label: translate('Future Renewals'),
        url: '/reports/subscriptions/future-renewals'
      },
    ]
  },
  {
    route: 'reports_products',
    label: translate('Products'),
    url: '/reports/products',
    icon: 'Product'
  },
  {
    route: 'reports_customer',
    label: translate('Customers'),
    url: '/reports/customer',
    icon: 'Users'
  },
  {
    route: 'reports_sources',
    label: translate('Sources'),
    url: '/reports/sources',
    icon: 'Source'
  },
];
const isMenuCollapsed = ref(false);
const isMenuExpanded = ref(false);
const isDesktopView = ref(window.innerWidth >= 1024);
const emit = defineEmits(['update:menuState']);
const sidebarRef = ref(null);
const overlayRef = ref(null);

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/
const shouldShowDropdown = (route) => {
  if (!route.child) return false;

  // Hide dropdowns when sidebar is collapsed (unless temporarily expanded on hover)
  const isVisuallyExpanded = !isSidebarCollapsed.value || isSidebarExpanded.value;
  if (!isVisuallyExpanded) return false;

  // Otherwise show if active OR always_open
  return isRouteActive(route) || route.always_open;
};


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

  //console.log(currentPath, '---', tabRoute.url)
  return false;
};
const isChildActive = (child, index, routeGroup) => {
  const currentPath = route.path;

  // Exact match for child
  if (currentPath === child.url) {
    return true;
  }

  return false;
};

const handleRouterPush = (route) => {
  router.push({ name: route.route }).catch(err => {
    // Ignore navigation duplicated errors
    if (err.name !== 'NavigationDuplicated') {
      console.error('Navigation error:', err);
    }
  });

  const reportBody = document.querySelector('#fct-report-body');
  if (reportBody) {
    reportBody.scrollTop = 0;
  }
}

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

const emitMenuState = () => {
  emit('update:menuState', {
    isMenuCollapsed: isMenuCollapsed.value,
    isMenuExpanded: isMenuExpanded.value,
    isDesktopView: isDesktopView.value
  });
};

watch([isMenuCollapsed, isMenuExpanded, isDesktopView], emitMenuState, {
  immediate: true
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

onMounted( () => {
  updateViewportMode();
  window.addEventListener('resize', updateViewportMode);
});

onUnmounted(() => {
  window.removeEventListener('resize', updateViewportMode);
});


</script>
