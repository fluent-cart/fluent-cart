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
        :pager-count="pager_count"
        :page-size="pagination.per_page"
    >
      <template #default=" currentPage ">
        {{ currentPage }}
      </template>
    </el-pagination>

  </div>
</template>

<script>


import translate, {translateNumber} from "@/utils/translator/Translator";

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
      currentPage: this.pagination.current_page || 1,
      pickerObserver: null,
      paginationUpdating: false,
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
    },
    'pagination.last_page': {
      handler(newVal, oldVal) {
        if (newVal !== oldVal) {
          this.paginationUpdating = true;
          this.$nextTick(() => {
            this.paginationUpdating = false;
            // The page buttons were just re-rendered for a new page count;
            // stamp the fresh ones, and re-stamp any the pager reused for
            // a different number.
            this.translatePickerElements();
          });
        }
      },
      flush: 'sync'
    },
    // The table moves the page on its own - a tab change resets it to 1,
    // a page restored from storage lands past 1 - and none of that goes
    // through a click, so the label's copy follows the table here.
    'pagination.current_page'(page) {
      this.currentPage = page;
    }
  },
  mounted() {
    this.translatePickerElements();
    // The pager re-renders whenever its page count changes - a new
    // per-page size, a new total after a tab change - and reuses buttons
    // when it does. Watching the DOM catches every such re-render at the
    // moment it lands, whichever prop drove it.
    if (typeof MutationObserver === 'function' && this.$el && this.$el.nodeType === 1) {
      this.pickerObserver = new MutationObserver(() => this.translatePickerElements());
      this.pickerObserver.observe(this.$el, {childList: true, subtree: true, characterData: true});
    }
  },
  beforeUnmount() {
    if (this.pickerObserver) {
      this.pickerObserver.disconnect();
      this.pickerObserver = null;
    }
  },

  methods: {
    translateNumber,
    translate,
    changePage(page) {
      if (this.paginationUpdating) return;

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

      // This footer's own page buttons - the page can hold more than one
      // footer, and this one also has a div.el-pagination round its
      // page-size select, so ask for the pager list itself.
      const elements = this.$el && this.$el.querySelectorAll
          ? this.$el.querySelectorAll('.el-pager li.number')
          : [];
      elements.forEach(elem => {
        const original = elem.textContent.trim();
        // Stamped once per number, not once per element: the pager reuses a
        // button when the page count changes, and a button stamped "2" that
        // now holds "4" kept painting "2" over it.
        if (original && /\d/.test(original) && elem.dataset.translatedFrom !== original) {
          const translated = translateNumber(original);
          elem.style.setProperty('--translated-number', `"${translated}"`);
          elem.dataset.translatedFrom = original;
        }
      });
    }
  },

};
</script>

