<script setup>
import {computed, onMounted, ref} from "vue";
import translate from "../../../../../utils/translator/Translator";

const props = defineProps({
  table: {
    type: Object,
    required: true
  },
  pagerSizes: {
    Boolean,
    default: true
  },
  extra_sizes: {
    required: false,
    type: Array,
    default() {
      return [];
    }
  },
  hide_on_single: {
    required: false,
    type: Boolean,
    default() {
      return true;
    }
  },
  background: {
    type: Boolean,
    default: true
  },
  layout: {
    type: String,
    default: 'prev, pager, next'
  },
})

onMounted(() => {
  props.table.fetch()
})

const page_sizes = computed(() => {
  const sizes = [];
  if (props.table.paginate.per_page < 10) {
    sizes.push(props.table.per_page);
  }

  const defaults = [
    10,
    20,
    50,
    80,
    100,
    120,
    150
  ];

  return [...sizes, ...defaults, ...props.extra_sizes];
})

const currentPage = ref(1);

const totalPage = computed(() => {
  return Math.ceil(props.table.paginate.total / props.table.paginate.per_page);
});

const changePage = (page) => {
  let currentPageCount = getCurrentPageCount(page, props.table.paginate.per_page);
  currentPage.value = page;
  props.table.onCurrentPageChanged({page, currentPageCount});

};

const changeSize = (size) => {
  currentPage.value = 1;
  props.table.onPerPageChanged(size);
}

const getCurrentPageCount = (page, perPage) => {
  let currentPageCount = page < props.table.paginate.last_page ?
      perPage : props.table.paginate.total % perPage
  ;
  if (!currentPageCount) {
    currentPageCount = perPage;
  }

  return currentPageCount;
}


</script>

<template>
  <div class="pagination-wrap">
    <div class="pager-wrap" v-if="pagerSizes">
      <span class="text">{{
          /* translators: %1$s - current page, %2$s - total page */
          translate('Page %1$s of %2$s', currentPage, totalPage)
        }}</span>
      <div class="el-pagination">
        <el-select @change="changeSize" v-model=" props.table.paginate.per_page" placeholder="Select">
          <el-option
              v-for="item in page_sizes"
              :key="item"
              :label="
                /* translators: %s - number of items per page */
                translate('%s / page', item)
              "
              :value="item"
          />
        </el-select>
      </div>
    </div>



    <el-pagination
        ref="paginationRef"
        :hide-on-single-page="hide_on_single"
        :background="background"
        :current-page="currentPage"
        :layout="layout"
        @current-change="changePage"
        :page-sizes="page_sizes"
        :total=" table.paginate.total"
        :page-count=" table.paginate.last_page"
        v-model:page-size=" table.paginate.per_page"
    />

  </div>
</template>
