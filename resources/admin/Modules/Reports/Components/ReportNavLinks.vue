<template>
  <div class="fct-report-nav-links-wrap">
    <div class="fct-report-nav-links-toggle">
      <el-button @click="toggleReportMenu">
        <!-- <DynamicIcon name="LineChart" class="icon"/> -->
        {{ translate('Reports') }}
      </el-button>
    </div>

    <div class="fct-report-nav-links" ref="reportNavLinks">
      <div class="fct-report-nav-links-overlay" @click="toggleReportMenu"></div>
      <Tab.Container>
        <Tab.Items class="is-open">
          <Tab.Item
              v-for="(route, i) in navLinks"
              :key="i"
              :class="{'fct-tab-item-active': isRouteActive(route)}">
            <Tab.Link
                @click="handleRouterPush(route)">
              <DynamicIcon v-if="route.icon" :name="route.icon"/>
              {{ route.label }}
              <DynamicIcon name="ChevronRight" class="tab-icon-right"/>
            </Tab.Link>
            <Animation accordion :visible="isRouteActive(route) && route.child" class="fct-settings-menu-child-wrap">
              <Tab.Item v-for="(child, i) in route.child" :key="i"
                        :class="{ 'fct-tab-item-active': isChildActive(child, i, route) }"
              >
                <Tab.Link
                    @click="handleRouterPush(child)">
                  {{ child.label }}
                </Tab.Link>
              </Tab.Item>
            </Animation>
          </Tab.Item>
        </Tab.Items>
      </Tab.Container>
    </div>
  </div>
</template>

<script setup>
import { useRouter, useRoute } from "vue-router";
import { ref, onMounted, onUnmounted, watch } from "vue";
import translate from "@/utils/translator/Translator";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Permission from "@/utils/permission/Permission";
import Animation from "@/Bits/Components/Animation.vue";
import * as Tab from '@/Bits/Components/Tab/Tab.js';

const router = useRouter();
const route = useRoute();
const activeBar = ref(null);
const navButtons = ref([]);
const menuWrap = ref(null);
const reportNavLinks = ref(null);
const isMobileView = ref(false);

const navLinks = [
  {
    route: 'reports_overview',
    label: translate('Overview'),
    url: '/reports/overview',
  },
  {
    route: 'reports_sales',
    label: translate('Sales'),
    url: '/reports/sales',
  },
  {
    route: 'reports_orders',
    label: translate('Orders'),
    url: '/reports/orders'
  },
  {
    route: 'reports_revenue',
    label: translate('Revenue'),
    url: '/reports/revenue'
  },
  {
    route: 'reports_refunds',
    label: translate('Refunds'),
    url: '/reports/refunds'
  },
  {
    route: 'reports_subscriptions',
    label: translate('Subscriptions'),
    url: '/reports/subscriptions',
    child: [
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
    url: '/reports/products'
  },
  {
    route: 'reports_customer',
    label: translate('Customers'),
    url: '/reports/customer'
  },
  {
    route: 'reports_sources',
    label: translate('Sources'),
    url: '/reports/sources'
  },
];

const checkMobileView = () => {
  isMobileView.value = window.innerWidth < 1025;
  updateActiveBar();
};

const updateActiveBar = () => {
  if (!activeBar.value || !reportNavLinks.value) return;

  const activeIndex = navLinks.findIndex(link => link.route === route.name);
  if (activeIndex === -1) return;

  const activeButton = navButtons.value[activeIndex];
  if (!activeButton) return;

  const containerRect = reportNavLinks.value.getBoundingClientRect();
  const buttonRect = activeButton.$el.getBoundingClientRect();

  if (isMobileView.value) {
    activeBar.value.style.width = `${buttonRect.width}px`;
    activeBar.value.style.height = '3px';
    activeBar.value.style.left = `${buttonRect.left - containerRect.left}px`;
    activeBar.value.style.top = `${buttonRect.top - containerRect.top + buttonRect.height}px`;
  } else {
    activeBar.value.style.width = `${buttonRect.width}px`;
    activeBar.value.style.height = '2px';
    activeBar.value.style.left = '0px';
    activeBar.value.style.top = 'auto';
    activeBar.value.style.transform = `translateX(${buttonRect.left - containerRect.left}px)`;
  }
};

const toggleReportMenu = () => {
  reportNavLinks.value.classList.toggle('is-active');
  updateActiveBar();
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
  router.push({ name: route.route });
  const reportBody = document.querySelector('#fct-report-body');
  if (reportBody) {
    reportBody.scrollTop = 0;
  }
}

// Watch for route changes to update the active bar
watch(() => route.name, () => {
  updateActiveBar();
}, { immediate: true });

onMounted( () => {
  menuWrap.value = document.querySelector('.fct_admin_menu_wrap');
  checkMobileView();
  window.addEventListener('resize', updateActiveBar);
});
</script>
