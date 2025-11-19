<template>
  <div v-click-outside="onClickOutside">
    <el-popover
        ref="popoverRef"
        :placement="placement"
        :width="500"
        trigger="click"
        popper-class="fct-input-dropdown-popover-wrap"
        :show-arrow="true"
        :visible="visible"
        :teleported="false"
    >
      <div class="el_pop_data_group">
        <div class="el_pop_data_headings">
          <ul>
            <li v-for="(item, item_index) in data" :key="item_index" :data-item_index="item_index"
                :class="(activeIndex == item_index) ? 'item_selected' : ''"
                @click="activeIndex = item_index">
              {{ item.title }}
            </li>
          </ul>
        </div>
        <div class="el_pop_data_body">
          <el-input v-model="searchQuery" class="sticky top-0 bg-white dark:bg-primary-600" :placeholder="translate('Search')" clearable/>
          <ul v-for="(item,current_index) in data" :key="current_index" v-show="activeIndex == current_index"
              :class="'el_pop_body_item_'+current_index">
            <li @click="insertShortcode(code)"
                v-for="(label, code, index) in getFilteredShortcodes(item.shortcodes)"
                :key="index">
              <span>{{ label }}</span>
              <span>{{ code }}</span>
            </li>
          </ul>
        </div>
      </div>
      <template #reference>
        <el-button @click="visible = !visible" class="editor-add-shortcode" :type="btnType"
                   :plain="plain" :size="btnSize">
          &#123;&#123;:&#125;&#125;
        </el-button>
      </template>
    </el-popover>
  </div>
</template>

<script setup>
import {onMounted, ref} from 'vue';
import {ClickOutside as vClickOutside} from 'element-plus';
import translate from "@/utils/translator/Translator";

defineOptions({
  name: 'inputPopoverDropdownExtended'
})

const props = defineProps({
  data: Array,
  close_on_insert: {
    type: Boolean,
    default() {
      return true;
    }
  },
  btnType: {
    type: String,
    default() {
      return 'success';
    }
  },
  btnSize: {
    type: String,
    default: ''
  },
  plain: {
    type: Boolean,
    default() {
      return false
    }
  },
  placement: {
    type: String,
    default: 'top'
  }
})

const popoverRef = ref()
const visible = ref(false)
const searchQuery = ref('')
const emit = defineEmits(['command'])
const activeIndex = ref('')
const onClickOutside = () => {
  visible.value = false
}

const insertShortcode = (code) => {
  emit('command', code)
  if (props.close_on_insert) {
    visible.value = false;
  }
}

// Filter shortcodes based on search query
const getFilteredShortcodes = (shortcodes) => {
  if (!searchQuery.value.trim()) {
    return shortcodes;
  }

  const query = searchQuery.value.toLowerCase().trim();
  const filtered = {};

  Object.entries(shortcodes).forEach(([code, label]) => {
    if (label.toLowerCase().includes(query) || code.toLowerCase().includes(query)) {
      filtered[code] = label;
    }
  });

  return filtered;
}

onMounted(() => {
  activeIndex.value = Object.keys(props.data)[0];
})

</script>
