<script setup>
import {ref, onMounted, onBeforeMount, onBeforeUnmount, nextTick} from "vue";
import Theme from "@/utils/Theme";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";

const currentTheme = ref(Theme.MODE_LIGHT);

const changeTheme = (mode) => {
  if (currentTheme.value === mode) {
    return;
  }
  currentTheme.value = mode;
  Theme.apply(mode);
};

const themeOptions = [
  {
    value: 'light',
    label: translate('Light'),
    icon: 'Sun'
  },
  { 
    value: 'dark',
    label: translate('Dark'),
    icon: 'Moon'
  },
  {
    value: 'system',
    label: translate('System'),
    icon: 'Tools'
  },
];

onBeforeMount(() => {
  // Remove the PHP pre-rendered placeholder before Vue appends its own button.
  // Vue teleport appends rather than replaces, so without this both would show.
  const container = document.getElementById('theme-button-container');
  if (container) {
    const placeholder = container.querySelector('.fct-theme-button-container');
    if (placeholder) placeholder.remove();
  }

  currentTheme.value = Theme.getCurrentTheme();

  if (currentTheme.value.toString().startsWith(Theme.MODE_SYSTEM)) {
    currentTheme.value = Theme.MODE_SYSTEM;
  }
});

const onThemeChanged = (event) => {
  nextTick(() => {
    currentTheme.value = Theme.getCurrentTheme();
  });
}

const handleThemeChange = (command) => {
  changeTheme(command);
}

onMounted(() => {
  window.addEventListener(Theme.THEME_CHANGE_EVENT, onThemeChanged, false);
});

onBeforeUnmount(() => {
  window.removeEventListener(Theme.THEME_CHANGE_EVENT, onThemeChanged, false);
});
</script>
<template>

  <div class="fct-theme-button-container">
    <el-dropdown
        placement="bottom-end"
        trigger="click"
        popper-class="fct-dropdown"
        @command="handleThemeChange"
    >
      <div class="theme-selected-button">
        <DynamicIcon v-if="currentTheme === 'light'" name="Sun"/>
        <DynamicIcon v-else-if="currentTheme === 'dark'" name="Moon"/>
        <DynamicIcon v-else-if="currentTheme === 'system'" name="Tools"/>
      </div>

      <template #dropdown>
        <el-dropdown-menu>
          <el-dropdown-item
              v-for="option in themeOptions"
              :key="option.value"
              :command="option.value"
              :class="{ 'active': currentTheme === option.value }"
          >
            <DynamicIcon :name="option.icon" />
            {{ option.label }}
          </el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>

  </div>


</template>
