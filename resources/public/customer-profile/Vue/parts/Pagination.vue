<template>
  <div class="pagination-wrap">
    <div class="pager-wrap" v-if="pagerSizes">
      <span class="text">{{
          /* translators: %1$s - current page, %2$s - total page */
          translate('Page %1$s of %2$s', translateNumber(currentPage), translateNumber(totalPage))
        }}</span>
      <div class="el-pagination">
        <el-select @change="changeSize" v-model="pagination.per_page" :placeholder="translate('Select')">
          <el-option
              v-for="item in page_sizes"
              :key="item"
              :label="
              /* translators: %s - number of items per page */
              translate('%s / page',translateNumber(item))
              "
              :value="item"
          />
        </el-select>
      </div>
      <span class="text">{{
          /* translators: %s - total number of items */
          translate('Total %s', translateNumber(pagination.total))
        }}</span>
    </div>


    <el-pagination
        ref="paginationRef"
        :hide-on-single-page="hide_on_single"
        :background="background"
        :current-page="pagination.current_page"
        :layout="layout"
        @current-change="changePage"
        :page-sizes="page_sizes"
        :total="pagination.total"
        :page-count="pagination.last_page"
        :pager-count="pager_count"
        v-model:page-size="pagination.per_page"
    >
      <template #default=" currentPage ">
        {{ currentPage }}
      </template>
    </el-pagination>

  </div>
</template>

<script>




import translate, {translateNumber} from "../../translator/Translator";

export default {
  props: {
    table: {
      type: Object,
      required: false,
    },
    pagerSizes: {
      type: Boolean,
      default: true
    },
    pagination: {
      required: true,
      type: Object
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
      default: 'prev, pager, next, slot'
    },
    pager_count: {
      type: Number,
      default: 5
    }
  },

  data() {
    return {
      currentPage: 1,
      pickerObserver: null,
    };
  },

  computed: {
    page_sizes() {
      const sizes = [];
      if (this.pagination.per_page < 10) {
        sizes.push(this.pagination.per_page);
      }

      const defaults = [10, 20, 50, 80, 100, 120, 150];

      return [...sizes, ...defaults, ...this.extra_sizes];
    },

    totalPage() {
      const perPage = this.pagination.per_page;
      const total = this.pagination.total;

      // If perPage is undefined, null, or non-numeric, handle accordingly
      if (isNaN(total) || total <= 0) {
        return 0; // Return 0 if the per_page value is invalid
      }

      return Math.ceil(total / perPage);
    }
  },

  watch: {
    // add watch on pagination
    pagination: {
      immediate: true,
      handler(newVal, oldVal) {
        setTimeout(()=>{
          this.translatePickerElements();
        }, 400)
      }
    }
  },

  methods: {
    translateNumber,
    translate,
    changePage(page) {

      let currentPageCount = this.getCurrentPageCount(page, this.pagination.per_page);
      this.currentPage = page;
      this.pagination.current_page = page;
      this.$emit('currentPageChanged', {page, currentPageCount});
      this.$emit('fetch');
      this.table?.handlePageChange();
      setTimeout(()=>{
        this.translatePickerElements();
      }, 300)
    },

    changeSize(size) {
      this.currentPage = 1;
      this.pagination.current_page = 1;
      this.$emit('onPerPageChanged', size);
      this.$emit('fetch');
      this.table?.handlePerPageChange();
    },

    getCurrentPageCount(page, perPage) {
      let currentPageCount = page < this.pagination.last_page
          ? perPage
          : this.pagination.total % perPage;

      return currentPageCount || perPage;
    },


    translatePickerElements() {

      const pickerPanel = document.querySelector('.el-pagination.is-background');

      if (!pickerPanel) {
        return;
      }
      const panel = pickerPanel;
      const elements = panel.querySelectorAll('li.number');
      elements.forEach(elem => {
        const original = elem.textContent.trim();
        if (original && /\d/.test(original) && !elem.dataset.translated) {
          const translated = translateNumber(original);
          elem.style.setProperty('--translated-number', `"${translated}"`);
          elem.dataset.translated = '1';
        }
      });
    }
  },
  mounted() {
    this.translatePickerElements();
  },

};
</script>

