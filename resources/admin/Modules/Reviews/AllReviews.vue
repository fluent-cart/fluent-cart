<template>
  <UserCan permission="reviews/manage">
    <div class="fct-all-reviews-page fct-layout-width">
      <PageHeading :title="translate('Reviews')"/>

      <div v-if="productFilter" class="fct-product-filter-banner">
        <span>{{ translate('Showing reviews for:') }} <strong>{{ productFilter }}</strong></span>
        <el-button size="small" text :disabled="reviewTable.isLoading()" @click="clearProductFilter">{{ translate('Clear filter') }}</el-button>
      </div>

      <div class="fct-all-reviews-wrap">
        <TableWrapper :table="reviewTable" :classicTabStyle="true" :has-mobile-slot="true">
          <transition name="slide-fade">
            <div v-if="selectedReviews.length > 0" class="bulk-actions-bar">
              <div class="bulk-left flex items-center gap-2">
                <el-select
                    size="small"
                    class="bulk-select min-w-[180px]"
                    :placeholder="translate('Select Bulk Action')"
                    :disabled="reviewTable.isLoading()"
                    v-model="selectedBulkAction"
                >
                  <el-option :label="translate('Approve')" value="approve"/>
                  <el-option :label="translate('Spam')" value="spam"/>
                  <el-option :label="translate('Trash')" value="trash"/>
                  <el-option :label="translate('Delete Permanently')" value="delete"/>
                  <el-option :label="translate('Reply')" value="reply"/>
                </el-select>

                <el-button
                    size="small"
                    :type="selectedBulkAction === 'delete' ? 'danger' : 'primary'"
                    :disabled="!selectedBulkAction || bulkActionLoading || reviewTable.isLoading()"
                    :loading="bulkActionLoading"
                    @click="handleBulkConfirm"
                >
                  {{ translate('Confirm') }}
                </el-button>
              </div>

              <div class="bulk-right">
                {{ selectedReviews.length }} {{ translate('item(s) selected') }}
              </div>
            </div>
          </transition>

          <ReviewsLoader
              v-if="reviewTable.isLoading()"
              :reviewTable="reviewTable"
              :next-page-count="reviewTable.nextPageCount"
          />

          <template v-else>
            <el-table
                :data="reviewTable.getTableData()"
                class="w-full compact-table full-compact"
                @selection-change="handleSelectionChange"
            >
              <el-table-column type="selection" :width="45"/>

              <el-table-column :label="translate('ID')" :width="80">
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                        name: 'view_review',
                        params: { review_id: scope.row.id }
                      }"
                  >
                    #{{ translateNumber(scope.row.id) }}
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="reviewTable.isColumnVisible('product')"
                  :label="translate('Product')"
                  :min-width="180"
              >
                <template #default="scope">
                  <div class="flex items-center gap-2" v-if="scope.row.product">
                    <router-link
                        :to="{ name: 'product_edit', params: { product_id: scope.row.post_id } }"
                        class="text-primary hover:underline truncate"
                    >
                      {{ scope.row.product.post_title }}
                    </router-link>
                  </div>
                  <span v-else class="text-gray-400">-</span>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Reviewer')" :min-width="160">
                <template #default="scope">
                  <div>
                    <div class="font-medium">{{ scope.row.reviewer_name }}</div>
                    <div class="text-xs text-gray-500">{{ scope.row.reviewer_email }}</div>
                  </div>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Rating')" :width="120">
                <template #default="scope">
                  <StarRating :rating="scope.row.rating" size="small"/>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="reviewTable.isColumnVisible('content')"
                  :label="translate('Review')"
                  :min-width="200"
              >
                <template #default="scope">
                  <div class="truncate max-w-[250px]">
                    <span v-if="scope.row.title" class="font-medium">{{ scope.row.title }} — </span>
                    {{ scope.row.content }}
                  </div>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Status')" :width="110">
                <template #default="scope">
                  <Badge :status="scope.row.status" size="small"/>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="reviewTable.isColumnVisible('date')"
                  :label="translate('Date')"
                  :width="120"
              >
                <template #default="scope">
                  <ConvertedTime :date-time="scope.row.created_at"/>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="reviewTable.isColumnVisible('actions')"
                  :label="translate('Actions')"
                  :width="100"
                  align="right"
                  fixed="right"
              >
                <template #default="scope">
                  <el-dropdown
                      trigger="click"
                      class="fct-more-option-wrap"
                      popper-class="fct-dropdown"
                      @command="handleCommand"
                      placement="bottom-end"
                  >
                    <span class="more-btn mr-2" :aria-label="translate('Review actions')">
                      <DynamicIcon name="More"/>
                    </span>
                    <template #dropdown>
                      <el-dropdown-menu>
                        <el-dropdown-item class="item-link" v-if="scope.row.id">
                          <router-link
                              :to="{
                                name: 'view_review',
                                params: { review_id: scope.row.id }
                              }"
                          >
                            <DynamicIcon name="Eye"/>
                            {{ translate("View") }}
                          </router-link>
                        </el-dropdown-item>
                        <el-dropdown-item
                            v-if="scope.row.status !== 'approved'"
                            :command="{ action: 'approve', review: scope.row }"
                        >
                          {{ translate("Approve") }}
                        </el-dropdown-item>
                        <el-dropdown-item
                            v-if="scope.row.status !== 'spam'"
                            :command="{ action: 'spam', review: scope.row }"
                        >
                          {{ translate("Spam") }}
                        </el-dropdown-item>
                        <el-dropdown-item
                            v-if="scope.row.status !== 'trash'"
                            :command="{ action: 'trash', review: scope.row }"
                        >
                          {{ translate("Trash") }}
                        </el-dropdown-item>
                        <el-dropdown-item
                            :command="{ action: 'delete', review: scope.row }"
                            class="item-destructive"
                        >
                          <DynamicIcon name="Delete"/>
                          {{ translate("Delete") }}
                        </el-dropdown-item>
                      </el-dropdown-menu>
                    </template>
                  </el-dropdown>
                </template>
              </el-table-column>

              <template #empty>
                <Empty
                    icon="Empty/ListView"
                    has-dark
                    :text="translate('No reviews found.')"
                />
              </template>
            </el-table>
          </template>

          <template #mobile>
            <ReviewsLoaderMobile v-if="reviewTable.isLoading()"/>
            <ReviewsTableMobile v-if="!reviewTable.isLoading()" :table="reviewTable"/>
          </template>
        </TableWrapper>
      </div>

      <el-dialog v-model="showBulkReplyDialog" :title="translate('Reply to %s reviews', selectedReviews.length)" width="500px" :aria-label="translate('Bulk reply to reviews')">
        <el-input
            v-model="bulkReplyContent"
            type="textarea"
            :rows="4"
            :placeholder="translate('Write your reply...')"
        />
        <template #footer>
          <el-button @click="showBulkReplyDialog = false">{{ translate('Cancel') }}</el-button>
          <el-button type="primary" :loading="bulkReplyLoading" :disabled="bulkReplyLoading" @click="submitBulkReply">{{ translate('Send Reply') }}</el-button>
        </template>
      </el-dialog>
    </div>
  </UserCan>
</template>

<script setup>
import {ref, watch} from "vue";
import {useRoute, useRouter} from "vue-router";
import {$confirm} from "@/Bits/common";
import useReviewTable from "@/utils/table-new/ReviewTable";
import TableWrapper from "@/Bits/Components/TableNew/TableWrapper.vue";
import PageHeading from "@/Bits/Components/Layout/PageHeading.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Badge from "@/Bits/Components/Badge.vue";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import translate, {translateNumber} from "@/utils/translator/Translator";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import StarRating from "@/Bits/Components/StarRating.vue";
import ReviewsLoader from "@/Modules/Reviews/Components/ReviewsLoader.vue";
import ReviewsTableMobile from "@/Modules/Reviews/Components/ReviewsTableMobile.vue";
import ReviewsLoaderMobile from "@/Modules/Reviews/Components/ReviewsLoaderMobile.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";

const route = useRoute();
const router = useRouter();
const initialPostId = route.query.post_id ? parseInt(route.query.post_id) : null;
const reviewTable = useReviewTable({postId: initialPostId});
const productFilter = ref(null);
const selectedReviews = ref([]);
const selectedBulkAction = ref('');
const showBulkReplyDialog = ref(false);
const bulkReplyContent = ref('');
const bulkReplyLoading = ref(false);
const bulkActionLoading = ref(false);

const watchProductFilterResolution = () => {
    let stopWatcher;
    stopWatcher = watch(
        () => reviewTable.getTableData(),
        (data) => {
            // No isLoading() gate here — Table.fetch() assigns tableData in
            // .then() but only clears loading in .finally() (which runs
            // after), so this watcher would always see loading still true
            // and bail out, permanently.
            if (data.length > 0 && data[0].product && data[0].post_id === reviewTable.getPostId()) {
                productFilter.value = data[0].product.post_title;
                stopWatcher?.();
            } else if (data.length === 0) {
                /* translators: %1$s: product ID */
                productFilter.value = translate('Product #%1$s', reviewTable.getPostId());
                stopWatcher?.();
            }
        }
        // No `immediate: true` — on the initial call reviewTable's own fetch()
        // hasn't run yet (getTableData() is still its default []), so an
        // immediate invocation would resolve the "no results" branch before
        // the real request even starts and self-stop, leaving the banner
        // stuck on "Product #<id>" forever. Waiting for the first genuine
        // tableData mutation (always a new array reference — see fetch() in
        // Table.js) covers both the empty-page-load and route-change callers.
    );
};

if (initialPostId > 0) {
    watchProductFilterResolution();
}

// ReviewsRoute.vue keys its <router-view> on route.query.post_id, so any
// change to this filter (including browser back/forward or a direct URL
// edit) fully remounts this component instead of reusing the instance —
// initialPostId above always reflects the current URL on every mount, so no
// watcher is needed here to keep the two in sync.

const clearProductFilter = () => {
    router.replace({ query: { ...route.query, post_id: undefined } });
};

const handleSelectionChange = (selection) => {
  selectedReviews.value = selection;
};

const handleCommand = (command) => {
  if (command.action === 'delete') {
    $confirm(
        translate('Are you sure you want to permanently delete this review? This action cannot be undone.'),
        translate('Confirm Delete'),
        {
          confirmButtonText: translate('Yes, Delete!'),
          cancelButtonText: translate('Cancel'),
          type: 'warning',
        }
    ).then(() => {
      Rest.delete('reviews/' + command.review.id)
          .then((response) => {
            Notify.success(response.message);
            reviewTable.fetch();
          })
          .catch((error) => {
            Notify.error(error.data?.message);
          });
    }).catch(() => {});
  } else if (['approve', 'spam', 'trash'].includes(command.action)) {
    const statusMap = {approve: 'approved', spam: 'spam', trash: 'trash'};
    Rest.put('reviews/' + command.review.id, {status: statusMap[command.action]})
        .then((response) => {
          Notify.success(response.message);
          reviewTable.fetch();
        })
        .catch((error) => {
          Notify.error(error.data?.message);
        });
  }
};

const handleBulkConfirm = () => {
  if (!selectedBulkAction.value) {
    Notify.error(translate('Please select a bulk action'));
    return;
  }
  bulkAction(selectedBulkAction.value);
};

const bulkAction = (action) => {
  const ids = selectedReviews.value.map(r => r.id);

  if (action === 'reply') {
    bulkReplyContent.value = '';
    showBulkReplyDialog.value = true;
    return;
  }

  if (action === 'delete') {
    $confirm(
        translate('Are you sure you want to permanently delete %s reviews?', ids.length),
        translate('Confirm Delete'),
        {
          confirmButtonText: translate('Yes, Delete!'),
          cancelButtonText: translate('Cancel'),
          type: 'warning',
        }
    ).then(() => {
      performBulkAction(action, ids);
    }).catch(() => {});
  } else {
    performBulkAction(action, ids);
  }
};

const submitBulkReply = () => {
  if (!bulkReplyContent.value.trim()) {
    Notify.error(translate('Please write a reply message before sending.'));
    return;
  }
  const ids = selectedReviews.value.map(r => r.id);
  if (!ids.length) {
    Notify.error(translate('No reviews selected'));
    return;
  }
  bulkReplyLoading.value = true;
  Rest.post('reviews/bulk-reply', {
    content: bulkReplyContent.value,
    review_ids: ids,
  })
      .then((response) => {
        Notify.success(response.message);
        showBulkReplyDialog.value = false;
        selectedReviews.value = [];
        selectedBulkAction.value = '';
        reviewTable.fetch();
      })
      .catch((error) => {
        Notify.error(error.data?.message);
      })
      .finally(() => {
        bulkReplyLoading.value = false;
      });
};

const performBulkAction = (action, ids) => {
  if (bulkActionLoading.value) return;
  bulkActionLoading.value = true;
  Rest.post('reviews/bulk-action', {
    action_type: action,
    review_ids: ids,
  })
      .then((response) => {
        Notify.success(response.message);
        selectedReviews.value = [];
        selectedBulkAction.value = '';
        reviewTable.fetch();
      })
      .catch((error) => {
        Notify.error(error.data?.message);
      })
      .finally(() => {
        bulkActionLoading.value = false;
      });
};
</script>

<style scoped>
.bulk-actions-bar {
  padding: 0 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.bulk-right {
  font-size: 13px;
  color: #6b7280;
}

.fct-product-filter-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 16px;
  margin-bottom: 12px;
  background: #f0f9ff;
  border: 1px solid #bae6fd;
  border-radius: 6px;
  font-size: 13px;
  color: #0c4a6e;
}
</style>
