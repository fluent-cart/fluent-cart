<script setup>
import {ref, onMounted, onBeforeMount, nextTick} from "vue";
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
  currentTheme.value = localStorage.getItem('fcart_admin_theme') ?? Theme.MODE_SYSTEM;

  if (currentTheme.value.toString().startsWith(Theme.MODE_SYSTEM)) {
    currentTheme.value = Theme.MODE_SYSTEM;
  }
  //Theme.apply(currentTheme.value, true);

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
  window.addEventListener("onFluentCartThemeChange", onThemeChanged, false);
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
