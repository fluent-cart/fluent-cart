<template>
  <UserCan permission="reviews/manage">
    <div class="fct-all-reviews-page fct-layout-width">
      <PageHeading :title="translate('Reviews')">
        <template #action>
          <el-button
              type="primary"
              :loading="addingReview"
              :disabled="addingReview"
              @click="showAddReviewModal = true"
          >
            {{ translate('Add Review') }}
          </el-button>
        </template>
      </PageHeading>

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
                    :disabled="reviewTable.isLoading() || bulkActionLoading"
                    v-model="selectedBulkAction"
                >
                  <el-option :label="translate('Approve')" value="approve"/>
                  <el-option :label="translate('Pending')" value="pending"/>
                  <el-option :label="translate('Mark as Spam')" value="spam"/>
                  <el-option :label="translate('Move to Trash')" value="trash"/>
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
                  {{ bulkProgress ? translate('Processing %1$s of %2$s', bulkProgress.current, bulkProgress.total) : translate('Confirm') }}
                </el-button>
              </div>

              <div class="bulk-right">
                {{ selectedReviews.length }} {{ translate('item(s) selected') }}
              </div>
            </div>
          </transition>

          <div v-if="bulkProgress" class="px-4 pb-3">
            <div class="mb-1 text-xs text-gray-500">
              {{ translate('Processing batch %1$s of %2$s', bulkProgress.current, bulkProgress.total) }}
            </div>
            <el-progress
                :percentage="Math.round((bulkProgress.current / bulkProgress.total) * 100)"
                :show-text="false"
                :stroke-width="6"
            />
          </div>

          <ReviewsLoader
              v-if="reviewTable.isLoading()"
              :reviewTable="reviewTable"
              :next-page-count="reviewTable.nextPageCount"
          />

          <template v-else>
            <el-table
                ref="reviewTableEl"
                :data="reviewTable.getTableData()"
                class="w-full compact-table"
                @selection-change="handleSelectionChange"
            >
              <el-table-column type="selection" :width="45"/>

              <el-table-column :label="translate('ID')" width="300">
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                        name: 'view_review',
                        params: { review_id: scope.row.id }
                      }"
                  >
                    <div>
                      <div class="font-medium">#{{ translateNumber(scope.row.id) }}</div>
                      <template v-if="scope.row.product">
                        <div class="text-sm text-system-dark dark:text-gray-300 truncate max-w-[220px]">
                          {{ scope.row.product.post_title }}
                        </div>
                        <div v-if="scope.row.item_label" class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-[220px]">
                          {{ scope.row.item_label }}
                        </div>
                      </template>
                      <span v-else class="text-sm text-gray-400">-</span>
                    </div>
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="reviewTable.isColumnVisible('reviewer')"
                  :label="translate('Reviewer')"
                  :width="220"
              >
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                        name: 'view_review',
                        params: { review_id: scope.row.id }
                      }"
                  >
                    <div>
                      <div class="font-medium">{{ scope.row.reviewer_name }}</div>
                      <div class="text-xs text-gray-500">{{ scope.row.reviewer_email }}</div>
                    </div>
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="reviewTable.isColumnVisible('rating')"
                  :label="translate('Rating')"
                  :width="120"
              >
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                        name: 'view_review',
                        params: { review_id: scope.row.id }
                      }"
                  >
                    <StarRating :rating="scope.row.rating" size="small"/>
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="reviewTable.isColumnVisible('content')"
                  :label="translate('Review')"
                  :min-width="200"
              >
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                        name: 'view_review',
                        params: { review_id: scope.row.id }
                      }"
                  >
                    <div class="max-w-[250px]">
                      <div v-if="scope.row.title" class="font-medium truncate">{{ scope.row.title }}</div>
                      <div class="text-xs text-gray-500 truncate">{{ scope.row.content }}</div>
                    </div>
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="reviewTable.isColumnVisible('status')"
                  :label="translate('Status')"
                  :width="120"
              >
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                        name: 'view_review',
                        params: { review_id: scope.row.id }
                      }"
                  >
                    <Badge :status="scope.row.status" size="small"/>
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="reviewTable.isColumnVisible('date')"
                  :label="translate('Date')"
                  :width="140"
              >
                <template #default="scope">
                  <RouteCell
                      class="hover:no-underline"
                      :to="{
                        name: 'view_review',
                        params: { review_id: scope.row.id }
                      }"
                  >
                    <ConvertedTime :date-time="scope.row.created_at"/>
                  </RouteCell>
                </template>
              </el-table-column>

              <el-table-column
                  v-if="reviewTable.isColumnVisible('actions')"
                  :label="translate('Actions')"
                  :width="90"
                  align="right"
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
                            v-if="scope.row.status !== 'pending'"
                            :command="{ action: 'pending', review: scope.row }"
                        >
                          {{ translate("Pending") }}
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

      <AddReviewModal
          v-model="showAddReviewModal"
          v-model:saving="addingReview"
          :product-id="reviewTable.getPostId() || null"
          @created="reviewTable.fetch()"
      />

      <el-dialog v-model="showBulkReplyDialog" :title="translate('Reply to %s reviews', selectedReviews.length)" width="500px" :aria-label="translate('Bulk reply to reviews')">
        <el-input
            v-model="bulkReplyContent"
            type="textarea"
            :rows="4"
            :placeholder="translate('Write your reply...')"
        />
        <template #footer>
          <el-button :disabled="bulkReplyLoading" @click="showBulkReplyDialog = false">{{ translate('Cancel') }}</el-button>
          <el-button type="primary" :loading="bulkReplyLoading" :disabled="bulkReplyLoading" @click="submitBulkReply">
            {{ bulkProgress ? translate('Processing %1$s of %2$s', bulkProgress.current, bulkProgress.total) : translate('Send Reply') }}
          </el-button>
        </template>
      </el-dialog>
    </div>
  </UserCan>
</template>

<script setup>
import {nextTick, ref, watch} from "vue";
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
import AddReviewModal from "@/Modules/Reviews/Components/AddReviewModal.vue";
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
const bulkProgress = ref(null);
const BULK_BATCH_SIZE = 20;
// The table element, for putting a selection back after a refetch.
const reviewTableEl = ref(null);
// Reviews a failed batch left unprocessed: selected again once the table
// is back, so the admin can run the action again on exactly those.
//
// The table is unmounted while it loads (ReviewsLoader takes its place),
// so the moment to select is when it mounts again with the new rows - the
// ref turning from null to the element - not when the data lands, when
// there is no table yet to select in.
let reselectAfterFetch = [];
watch(reviewTableEl, (table) => {
  if (!table || !reselectAfterFetch.length) return;
  const wanted = new Set(reselectAfterFetch);
  reselectAfterFetch = [];
  nextTick(() => {
    reviewTable.getTableData().forEach((row) => {
      if (wanted.has(row.id)) table.toggleRowSelection(row, true);
    });
  });
});
// Pre-fills the modal's product when the list is already filtered to one, so
// the picker is skipped in exactly the case where the answer is known.
const showAddReviewModal = ref(false);
// Kept in step with the modal's own save state. Closing the dialog mid-save
// does not cancel the request, so the trigger stays locked until it settles —
// otherwise a fresh form could be opened and then closed by the first response.
const addingReview = ref(false);

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
  } else if (['approve', 'pending', 'spam', 'trash'].includes(command.action)) {
    const statusMap = {approve: 'approved', pending: 'pending', spam: 'spam', trash: 'trash'};
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

/**
 * Run one request per batch of ids, one after another, and keep going past
 * a batch that fails. What comes back is counted in reviews, not batches:
 * how many the server processed (it says so in each response), how many
 * it skipped, and which ids never got through - those are handed back so
 * they can be selected again for a retry.
 */
const processBulkBatches = async (ids, processBatch) => {
  const batches = [];
  for (let index = 0; index < ids.length; index += BULK_BATCH_SIZE) {
    batches.push(ids.slice(index, index + BULK_BATCH_SIZE));
  }

  const result = {processed: 0, skipped: 0, failedIds: [], failedBatches: 0, totalBatches: batches.length};
  bulkProgress.value = {current: 0, total: batches.length};

  try {
    for (let index = 0; index < batches.length; index++) {
      bulkProgress.value.current = index + 1;

      try {
        const response = await processBatch(batches[index]);
        const data = response?.data || {};
        result.processed += Number(data.affected ?? data.replied ?? batches[index].length);
        result.skipped += Number(data.skipped ?? 0);
      } catch (error) {
        result.failedBatches++;
        result.failedIds.push(...batches[index]);
      }
    }
  } finally {
    bulkProgress.value = null;
  }

  return result;
};

const notifyBulkResult = ({processed, skipped, failedIds}, isReply = false) => {
  const done = isReply
      ? translate('Replied to %s review(s).', processed)
      : translate('%s review(s) updated.', processed);
  const skippedNote = isReply && skipped > 0
      ? ' ' + translate('%s skipped: already replied.', skipped)
      : '';

  if (!failedIds.length) {
    Notify.success(done + skippedNote);
    return;
  }

  Notify.error(
      done + skippedNote + ' ' + translate('%s review(s) were not processed and are still selected. Try again.', failedIds.length)
  );
};

const submitBulkReply = async () => {
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
  const result = await processBulkBatches(ids, (batch) => Rest.post('reviews/bulk-reply', {
    content: bulkReplyContent.value,
    review_ids: batch,
  }));

  notifyBulkResult(result, true);
  bulkReplyLoading.value = false;
  // A failed batch keeps the dialog and its text: the reply can be sent
  // again to the reviews it never reached, which stay selected.
  if (!result.failedIds.length) {
    showBulkReplyDialog.value = false;
    selectedBulkAction.value = '';
  }
  reselectAfterFetch = result.failedIds;
  reviewTable.fetch();
};

const performBulkAction = async (action, ids) => {
  if (bulkActionLoading.value) return;
  bulkActionLoading.value = true;
  const result = await processBulkBatches(ids, (batch) => Rest.post('reviews/bulk-action', {
    action_type: action,
    review_ids: batch,
  }));

  notifyBulkResult(result);
  bulkActionLoading.value = false;
  if (!result.failedIds.length) {
    selectedBulkAction.value = '';
  }
  reselectAfterFetch = result.failedIds;
  reviewTable.fetch();
};
</script>
