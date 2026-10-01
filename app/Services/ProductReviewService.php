<?php

namespace FluentCart\App\Services;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

class ProductReviewService
{
    /**
     * Per-request memo for resolveOrderGrant(), keyed "<hash>:<postId>".
     *
     * @var array<string, \FluentCart\App\Models\Order|null>
     */
    protected static $grantCache = [];

    /**
     * Per-request memo of the order an order-hash names, with the product and
     * variation of each of its lines, keyed by hash. Loaded once however many
     * products the page asks about; the order-review page seeds it from the
     * order it already loaded via primeOrderGrant(), so rendering N rows costs
     * no grant queries at all.
     *
     * `products` is the same information keyed by product — post id to the
     * set of variation ids bought — so asking whether a hash covers a product
     * is a lookup, not a scan of every line for every row on the page.
     *
     * @var array<string, array{order: \FluentCart\App\Models\Order|null, lines: array<int, array{post_id: int, object_id: int}>, products: array<int, array<int, true>>}>
     */
    protected static $orderCache = [];

    /**
     * Per-request memo of isReviewEnabledForProduct(), keyed by post id. The
     * order-review page asks it once per row and the block it renders asks
     * again for its CTA and its form; primeReviewsEnabled() fills it for a
     * whole page in one query.
     *
     * @var array<int, bool>
     */
    protected static $reviewsEnabledCache = [];

    /**
     * Per-request memo of the customer row behind a signed-in visitor, keyed
     * by user id. grantOwnsSubmission() and effectiveReviewerIdentity() both
     * ask, and the order-review page asks for every row it renders.
     *
     * @var array<int, \FluentCart\App\Models\Customer|null>
     */
    protected static $visitorCustomerCache = [];

    /**
     * How long a notice lease ("sending") stands before it is treated as
     * abandoned and may be taken over. A send takes seconds; a worker killed
     * mid-send leaves a lease nobody settles.
     */
    const NOTICE_LEASE_SECONDS = 15 * MINUTE_IN_SECONDS;

    /**
     * Drop the per-request memos: the grant cache above and the settings
     * cache getReviewSettings() keeps.
     *
     * Both are correct for one request and wrong across two. Tests run many
     * "requests" in one process — changing the store's permission mode or an
     * order's items between cases — so they need a way to clear them. Same
     * seam as TaxCalculator::resetCache(); production never calls it.
     *
     * @return void
     */
    public static function resetRuntimeCache(): void
    {
        static::$grantCache = [];
        static::$orderCache = [];
        static::$reviewsEnabledCache = [];
        static::$visitorCustomerCache = [];
        static::resetReviewSettingsCache();
    }

    /**
     * Free ships exactly one store reply per review and no customer
     * replies — a hard contract: without Pro active the switch is dead,
     * so no hook can lift the single-reply rule. With Pro, its
     * ReviewService bridges the public
     * fluent_cart/review/allow_threaded_replies filter onto this
     * internal switch; free itself ships no threaded-reply code.
     */
    public static function isMultipleRepliesAllowed(): bool
    {
        if (!\FluentCart\App\App::isProActive()) {
            return false;
        }

        return (bool) apply_filters('fluent_cart/review/allow_multiple_replies', false);
    }

    /**
     * The arrangement the list may actually be drawn in.
     *
     * Free draws one review under the last. Grid, slider and masonry are Pro,
     * and the check lives here rather than at each of the four places a view
     * mode is read — the renderer, the composed row, the list block and the
     * shortcode — because a gate repeated four times is a gate that will
     * disagree with itself. The editor asks the same question through its own
     * localized flag; this is what actually decides, since a block attribute
     * is hand-editable in the code editor and a shortcode is typed by hand.
     *
     * Anything unrecognised also lands on 'list', which is the existing
     * behaviour and keeps a typo from becoming a layout.
     */
    public static function resolveViewMode($viewMode): string
    {
        $viewMode = strtolower(trim((string) $viewMode));

        if (!in_array($viewMode, ['grid', 'slider', 'masonry'], true)) {
            return 'list';
        }

        return \FluentCart\App\App::isProActive() ? $viewMode : 'list';
    }

    /**
     * Whether a photograph may be styled as the card rather than sit inside it.
     *
     * Flush and backdrop are Pro. They are the two settings that change what a
     * review card looks like rather than how many fit a row, so they are the
     * one part of the photograph handling that follows the layout modes behind
     * the licence; how many photographs show, how wide and how tall they are,
     * and filtering to reviews that carry one all stay free.
     *
     * Asked here rather than at each emitter for the same reason as
     * resolveViewMode(): the rendered row and the composed row each write the
     * modifier themselves, and a gate written twice is a gate that will
     * disagree with itself.
     */
    public static function isPhotoStylingAllowed(): bool
    {
        return \FluentCart\App\App::isProActive();
    }

    public static function isVerifiedPurchase($postId, $customerIdOrEmail): bool
    {
        if (!is_numeric($customerIdOrEmail)) {
            $customer = Customer::query()->where('email', $customerIdOrEmail)->first();
            if (!$customer) {
                return false;
            }
            $customerId = $customer->id;
        } else {
            $customerId = $customerIdOrEmail;
        }

        return OrderItem::query()
            ->where('post_id', $postId)
            ->whereHas('order', function ($q) use ($customerId) {
                $q->whereIn('status', Status::getOrderSuccessStatuses())
                  ->where('customer_id', $customerId);
            })
            ->exists();
    }

    /**
     * Whether the customer has ANY order containing this product, in any
     * status. Review eligibility in verified_buyers mode uses this — placing
     * an order at all unlocks the review form. The stricter
     * isVerifiedPurchase() (successful orders only) keeps governing the
     * Verified Purchase badge.
     */
    public static function hasOrderedProduct($postId, $customerId): bool
    {
        return OrderItem::query()
            ->where('post_id', $postId)
            ->whereHas('order', function ($q) use ($customerId) {
                $q->where('customer_id', $customerId);
            })
            ->exists();
    }

    public static function isReviewEnabledForProduct($postId): bool
    {
        $globalSettings = static::getReviewSettings();
        if ($globalSettings['reviews_enabled'] !== 'yes') {
            return false;
        }

        $postId = (int) $postId;

        if (!array_key_exists($postId, static::$reviewsEnabledCache)) {
            static::primeReviewsEnabled([$postId]);
        }

        return static::$reviewsEnabledCache[$postId];
    }

    /**
     * Load the per-product reviews toggle for a set of products in one query,
     * so a page that asks about each of them — and the review block each row
     * renders, which asks again — never repeats the lookup.
     *
     * @param int[] $postIds
     * @return void
     */
    public static function primeReviewsEnabled(array $postIds): void
    {
        $wanted = [];
        foreach ($postIds as $postId) {
            $postId = (int) $postId;
            if ($postId && !array_key_exists($postId, static::$reviewsEnabledCache)) {
                $wanted[$postId] = true;
            }
        }

        if (!$wanted) {
            return;
        }

        // A product with no detail row has no toggle, so it stays enabled.
        foreach (array_keys($wanted) as $postId) {
            static::$reviewsEnabledCache[$postId] = true;
        }

        $details = ProductDetail::query()
            ->select(['post_id', 'other_info'])
            ->whereIn('post_id', array_keys($wanted))
            ->get();

        foreach ($details as $detail) {
            $otherInfo = $detail->other_info;
            if (is_array($otherInfo) && isset($otherInfo['reviews_enabled']) && $otherInfo['reviews_enabled'] === 'no') {
                static::$reviewsEnabledCache[(int) $detail->post_id] = false;
            }
        }
    }

    /**
     * The order an order-hash names, but only if that order actually contains
     * the product being reviewed.
     *
     * This is the trust boundary for the public order-review page. The hash is
     * an unguessable per-order uuid that the store emails to the buyer, so
     * holding one is evidence of the purchase in the same way that being
     * logged in as the customer is — which is what lets a guest, who by
     * definition has no user account to check, review what they bought.
     *
     * Gated on the order having actually succeeded, which the logged-in path
     * does not need: hasOrderedProduct() accepts any status because it also
     * requires the visitor to be authenticated AS that customer. A hash has no
     * such anchor — it is bearer-transferable and anonymous — and an order row
     * and its uuid both exist BEFORE payment. Without this check anyone could
     * start a checkout, abandon it, and hold a working grant for everything in
     * the basket, which is the exact bypass verified_buyers mode forbids.
     *
     * With an item, the grant narrows further: the order must hold a line
     * for that exact variation (fct_order_items.object_id). A buyer of Red
     * holds no grant for Blue. Item 0 keeps the product-level rule, so every
     * caller that never learned about items behaves exactly as before.
     *
     * @param int    $postId
     * @param string $orderHash
     * @param int    $itemId 0 for the product as a whole
     * @return Order|null
     */
    public static function resolveOrderGrant($postId, $orderHash, $itemId = 0)
    {
        $postId = (int) $postId;
        $itemId = max(0, (int) $itemId);
        $orderHash = sanitize_text_field((string) $orderHash);

        if (!$postId || $orderHash === '') {
            return null;
        }

        // One page renders a CTA and a form per line item, each asking the
        // same question about the same hash, so the lookup is memoized per
        // request. Keyed on all three, because one hash grants some products
        // (and some items) and not others. A class-static rather than a
        // function-static so resetRuntimeCache() can clear it between tests.
        $cacheKey = $orderHash . ':' . $postId . ':' . $itemId;
        if (array_key_exists($cacheKey, static::$grantCache)) {
            return static::$grantCache[$cacheKey];
        }

        $loaded = static::loadOrderForGrant($orderHash);
        $order = $loaded['order'];

        // Same statuses that govern the Verified Purchase badge, so a link can
        // never authorise a review the badge would call unverified.
        if ($order && !in_array($order->status, Status::getOrderSuccessStatuses(), true)) {
            $order = null;
        }

        if ($order) {
            $bought = $loaded['products'][$postId] ?? null;
            if ($bought === null || ($itemId && !isset($bought[$itemId]))) {
                $order = null;
            }
        }

        static::$grantCache[$cacheKey] = $order;

        return $order;
    }

    /**
     * The order a hash names and the (product, variation) of each of its
     * lines — one order query and one line query per hash per request,
     * however many products then ask about it.
     *
     * @param string $orderHash
     * @return array{order: Order|null, lines: array<int, array{post_id: int, object_id: int}>, products: array<int, array<int, true>>}
     */
    protected static function loadOrderForGrant($orderHash): array
    {
        if (array_key_exists($orderHash, static::$orderCache)) {
            return static::$orderCache[$orderHash];
        }

        $order = Order::query()->where('uuid', $orderHash)->first();
        $lines = [];

        if ($order) {
            $rows = OrderItem::query()
                ->select(['post_id', 'object_id'])
                ->where('order_id', $order->id)
                ->get();

            foreach ($rows as $row) {
                $lines[] = ['post_id' => (int) $row->post_id, 'object_id' => (int) $row->object_id];
            }
        }

        static::$orderCache[$orderHash] = static::orderMemoEntry($order ?: null, $lines);

        return static::$orderCache[$orderHash];
    }

    /**
     * One memo entry: the order, its lines, and the lines keyed by product.
     *
     * @param Order|null $order
     * @param array<int, array{post_id: int, object_id: int}> $lines
     * @return array{order: Order|null, lines: array<int, array{post_id: int, object_id: int}>, products: array<int, array<int, true>>}
     */
    protected static function orderMemoEntry($order, array $lines): array
    {
        $products = [];
        foreach ($lines as $line) {
            if ($line['post_id']) {
                $products[$line['post_id']][$line['object_id']] = true;
            }
        }

        return ['order' => $order, 'lines' => $lines, 'products' => $products];
    }

    /**
     * Seed the grant memo from an order a caller has already loaded, so the
     * per-product grant checks that follow issue no queries of their own.
     * The order-review page loads the order with its lines to render them;
     * without this every row would reload the same order to answer the same
     * question.
     *
     * @param Order|null $order loaded with its order_items
     * @return void
     */
    public static function primeOrderGrant($order): void
    {
        if (!$order || !$order->uuid) {
            return;
        }

        $lines = [];
        foreach ($order->order_items as $line) {
            $lines[] = ['post_id' => (int) $line->post_id, 'object_id' => (int) $line->object_id];
        }

        static::$orderCache[(string) $order->uuid] = static::orderMemoEntry($order, $lines);
    }

    /**
     * The variation a submission may be filed under, checked against the
     * product it claims to belong to.
     *
     * One place for the rule, so the public submit path and any later admin
     * path cannot drift: the id must name a variation of THIS product, and a
     * simple product has no items to speak of — its lone default variation is
     * the product, so the review is filed at product level (item 0) whatever
     * the client sent.
     *
     * Only the id is resolved. The item's name is never copied onto the
     * review; it is read from the variation row wherever the review is shown,
     * the way the product's name is read from the post.
     *
     * @param int $postId
     * @param int $itemId
     * @return int|null the item to file under (0 for the product as a whole),
     *                  or null when the id does not belong to the product
     */
    public static function resolveReviewItem($postId, $itemId)
    {
        $postId = (int) $postId;
        $itemId = max(0, (int) $itemId);

        if (!$itemId) {
            return 0;
        }

        $belongsToProduct = ProductVariation::query()
            ->where('id', $itemId)
            ->where('post_id', $postId)
            ->exists();

        if (!$belongsToProduct) {
            return null;
        }

        return static::productHasItems($postId) ? $itemId : 0;
    }

    /**
     * Narrow a review query to one (product, item) slot.
     *
     * Item 0 means the product-level slot, which is item_id NULL — never
     * `= 0`, which no row carries. Every duplicate check and reviewed-state
     * lookup must go through this so the two cannot disagree about which
     * rows occupy a slot.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @param int $itemId
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    public static function scopeToItem($query, $itemId)
    {
        $itemId = max(0, (int) $itemId);

        return $itemId
            ? $query->where('item_id', $itemId)
            : $query->whereNull('item_id');
    }

    /**
     * Whether a product's variations are items worth telling apart.
     *
     * Positive signal only: an empty variation_type (a drifted or incomplete
     * detail row) reads as simple, so a lone default variation named
     * "Simple" never surfaces as an item.
     *
     * @param int $postId
     * @return bool
     */
    public static function productHasItems($postId): bool
    {
        return (bool) static::itemTypedProductIds([$postId]);
    }

    /**
     * Which of these products have items, in one query.
     *
     * The batch form of productHasItems(), and the place the rule actually
     * lives — that method asks this one. A caller with a page of products (the
     * add-review picker) must not ask per row, and must not carry its own copy
     * of the predicate either: two copies drift, and a picker that disagrees
     * with resolveReviewItem() offers a variation the write path then refuses.
     *
     * A missing detail row, an empty variation_type and a NULL one all read as
     * simple — whereNotIn excludes NULL, the same answer the single-row form
     * gave by casting it to ''.
     *
     * @param array $postIds
     * @return array<int, int> the ids that have items
     */
    public static function itemTypedProductIds(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));

        if (!$postIds) {
            return [];
        }

        $ids = ProductDetail::query()
            ->whereIn('post_id', $postIds)
            ->whereNotIn('variation_type', ['', Helper::PRODUCT_TYPE_SIMPLE])
            ->pluck('post_id')
            ->all();

        return array_map('intval', $ids);
    }

    /**
     * Attach the name a review's item is shown under, in one query for the
     * whole page.
     *
     * Read from the variation row the way the product name is read from the
     * post: a rename shows the new name, a deleted variation shows nothing.
     * Rows with no item get an empty string so templates test one key.
     *
     * @param iterable<ProductReview> $reviews
     * @return void
     */
    public static function attachItemLabels($reviews): void
    {
        $itemIds = [];
        foreach ($reviews as $review) {
            if ((int) $review->item_id) {
                $itemIds[(int) $review->item_id] = true;
            }
        }

        $names = [];
        if ($itemIds) {
            $names = ProductVariation::query()
                ->whereIn('id', array_keys($itemIds))
                ->pluck('variation_title', 'id')
                ->all();
        }

        foreach ($reviews as $review) {
            $itemId = (int) $review->item_id;
            $review->setAttribute('item_label', $itemId ? trim((string) ($names[$itemId] ?? '')) : '');
        }
    }

    /**
     * The URL of the request being served, for links that come back to the
     * page the visitor is on. Built the way WordPress builds its own
     * canonical redirect target — host plus the request path — rather than
     * home_url() plus the path, which on a site installed in a subdirectory
     * would name that directory twice.
     *
     * @return string empty when the request carries no path
     */
    public static function currentRequestUrl(): string
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) wp_unslash($_SERVER['HTTP_HOST']) : '';

        if ($requestUri === '' || $host === '') {
            return '';
        }

        return esc_url_raw(set_url_scheme('http://' . $host . $requestUri));
    }

    /**
     * How many distinct products an order hash unlocks — the size of the
     * grant's own submission allowance. Answered from the order memo, so a
     * hash already resolved costs nothing; an unknown hash counts as none.
     *
     * @param string $orderHash
     * @return int
     */
    public static function grantProductCount($orderHash): int
    {
        $orderHash = trim((string) $orderHash);

        if ($orderHash === '') {
            return 0;
        }

        return count(static::loadOrderForGrant($orderHash)['products']);
    }

    /**
     * Whether a grant, rather than the visitor's own account, owns this
     * submission.
     *
     * An order hash is a bearer credential: whoever holds the link may review
     * what the order contains, and the review belongs to the buyer. That is
     * settled. What must not happen is a forwarded link crediting the review
     * to whoever opened it — a logged-in visitor who never bought the product
     * would otherwise get a review published under their own account in
     * verified_buyers mode, which is exactly what that mode forbids.
     *
     * So a grant owns the submission unless the visitor is signed in AS the
     * order's customer, in which case their own account is the better identity
     * (they keep the ability to edit it) and they would have passed the
     * permission check without any hash at all.
     *
     * @param \FluentCart\App\Models\Order|null $grant
     * @return bool
     */
    public static function grantOwnsSubmission($grant): bool
    {
        if (!$grant) {
            return false;
        }

        $userId = get_current_user_id();

        if (!$userId) {
            return true;
        }

        $customer = static::visitorCustomer($userId);

        if (!$customer) {
            return true;
        }

        return (int) $customer->id !== (int) $grant->customer_id;
    }

    /**
     * The customer row a signed-in visitor's account is linked to, looked up
     * once per request.
     *
     * @param int $userId
     * @return Customer|null
     */
    public static function visitorCustomer($userId)
    {
        $userId = (int) $userId;

        if (!$userId) {
            return null;
        }

        if (!array_key_exists($userId, static::$visitorCustomerCache)) {
            static::$visitorCustomerCache[$userId] = Customer::query()->where('user_id', $userId)->first();
        }

        return static::$visitorCustomerCache[$userId];
    }

    /**
     * The identity an order grant posts under: the order's customer, never
     * anything the client typed.
     *
     * @param Order $order
     * @return array{name: string, email: string, customer_id: int|null}
     */
    public static function orderGrantIdentity($order): array
    {
        $customer = $order ? $order->customer : null;

        if ($customer) {
            $name = trim((string) $customer->full_name);
            $email = (string) $customer->email;
            $customerId = (int) $customer->id;
        } else {
            $name = '';
            $email = '';
            $customerId = null;
        }

        // A customer row with no name still needs a byline: the reviewer name
        // is required, and it is published on the product page. Not the
        // email's local part — that is half the address, shown to everyone —
        // but a neutral label that is still true of anyone holding a grant.
        if ($name === '' && $email !== '') {
            $name = __('Verified Buyer', 'fluent-cart');
        }

        return [
            'name'        => $name,
            'email'       => $email,
            'customer_id' => $customerId,
        ];
    }

    /**
     * Who a submission is filed under, resolved once so the permission check,
     * the duplicate guard, the slot lock and the order-review page's reviewed
     * state all agree on the same person.
     *
     * A grant that owns the submission (see grantOwnsSubmission()) files it
     * under the order's buyer, so the buyer is the identity here too — a
     * forwarded link opened by a signed-in stranger must find the buyer's
     * earlier review, not search for the stranger's own, or every reopen
     * would insert one more review under the buyer's name. Otherwise it is
     * the visitor: their account and its emails when signed in, the typed
     * email when not.
     *
     * @param Order|null $grant
     * @param string     $typedEmail what a guest typed, used only with no grant
     * The customer id rides along with the user id and the emails: a buyer
     * without a WordPress account is only ever their customer row, and that
     * row's email can change between two orders — a review filed under the
     * old address must still count as theirs.
     *
     * @return array{user_id: int, customer_id: int, emails: string[], order_id: int, order_keyed: bool, via_grant: bool}
     */
    public static function effectiveReviewerIdentity($grant, $typedEmail = ''): array
    {
        if (static::grantOwnsSubmission($grant)) {
            $grantEmail = trim((string) Arr::get(static::orderGrantIdentity($grant), 'email', ''));

            // A customer row that is gone yields no email. The submit path
            // then takes the typed name and email for the byline, but the
            // review slot cannot be keyed on what was typed — a fresh address
            // per submission would reopen it every time — so it is keyed on
            // the order the grant names instead: one review per product per
            // order, whichever address the visitor supplies.
            if ($grantEmail === '') {
                $typed = sanitize_email((string) $typedEmail);

                return [
                    'user_id'     => 0,
                    'customer_id' => 0,
                    'emails'      => $typed !== '' ? [$typed] : [],
                    'order_id'    => (int) $grant->id,
                    // The typed address is the byline, not the slot: the
                    // duplicate guard and the lock key on the order.
                    'order_keyed' => true,
                    'via_grant'   => true,
                ];
            }

            if ($grantEmail !== '') {
                $buyer = $grant->customer;
                $buyerUserId = ($buyer && $buyer->user_id) ? (int) $buyer->user_id : 0;

                $emails = [$grantEmail];
                if ($buyerUserId) {
                    $buyerUser = get_userdata($buyerUserId);
                    if ($buyerUser && $buyerUser->user_email) {
                        $emails[] = (string) $buyerUser->user_email;
                    }
                }

                return [
                    'user_id'     => $buyerUserId,
                    'customer_id' => $buyer ? (int) $buyer->id : 0,
                    'emails'      => array_values(array_unique(array_filter($emails))),
                    'order_id'    => (int) $grant->id,
                    'order_keyed' => false,
                    'via_grant'   => true,
                ];
            }
        }

        $userId = get_current_user_id();

        if ($userId) {
            // Same identity rule as the My Reviews dashboard queries: the
            // account matches by user id OR by its emails — a review left as
            // a guest with either address belongs to this customer. Both the
            // WP account email and the customer record's email count: the two
            // can diverge, and the dashboard matches on the customer one.
            $customer = static::visitorCustomer($userId);

            return [
                'user_id'     => (int) $userId,
                'customer_id' => $customer ? (int) $customer->id : 0,
                'emails'      => array_values(array_unique(array_filter([
                    (string) wp_get_current_user()->user_email,
                    $customer ? (string) $customer->email : '',
                ]))),
                'order_id'    => 0,
                'order_keyed' => false,
                'via_grant'   => false,
            ];
        }

        $typed = sanitize_email((string) $typedEmail);

        return [
            'user_id'     => 0,
            'customer_id' => 0,
            'emails'      => $typed !== '' ? [$typed] : [],
            'order_id'    => 0,
            'order_keyed' => false,
            'via_grant'   => false,
        ];
    }

    /**
     * Narrow a review query to the rows one identity owns: its user id, or
     * any of its emails. Plain equality on the email — the column's ci
     * collation already ignores case, and unlike LOWER() it keeps the
     * reviewer_email index usable.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @param array{user_id: int, customer_id?: int, emails: string[]} $identity
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    public static function scopeToIdentity($query, array $identity)
    {
        $userId = (int) Arr::get($identity, 'user_id', 0);
        $customerId = (int) Arr::get($identity, 'customer_id', 0);
        $emails = array_values(array_filter((array) Arr::get($identity, 'emails', [])));
        // Flagged only for a grant whose customer is gone: the slot is then
        // the order itself, so a review already filed from this order counts
        // whatever address was typed alongside it.
        $orderId = (int) Arr::get($identity, 'order_id', 0);
        $orderKeyed = $orderId && Arr::get($identity, 'order_keyed');

        return $query->where(function ($identityQuery) use ($userId, $customerId, $emails, $orderId, $orderKeyed) {
            $first = true;
            if ($userId) {
                $identityQuery->where('user_id', $userId);
                $first = false;
            }
            // The customer row outlives its email: a guest buyer's earlier
            // review, filed under the address they had then, is still theirs.
            if ($customerId) {
                $first ? $identityQuery->where('customer_id', $customerId) : $identityQuery->orWhere('customer_id', $customerId);
                $first = false;
            }
            if ($emails) {
                $first ? $identityQuery->whereIn('reviewer_email', $emails) : $identityQuery->orWhereIn('reviewer_email', $emails);
                $first = false;
            }
            if ($orderKeyed) {
                $first ? $identityQuery->where('order_id', $orderId) : $identityQuery->orWhere('order_id', $orderId);
            }
        });
    }

    /**
     * The email the slot lock is claimed under for an identity. Normally the
     * identity's own address. For a grant whose customer is gone there is no
     * stable address — the visitor types one — so the lock is claimed under a
     * synthetic, well-formed address derived from the order id: two
     * submissions from one order then contend on one lock whatever was typed.
     * The value only ever feeds the lock name; it is never stored.
     *
     * @param array{user_id: int, emails: string[], order_id: int, order_keyed: bool} $identity
     * @param string $fallbackEmail
     * @return string
     */
    public static function slotLockEmail(array $identity, $fallbackEmail = ''): string
    {
        $emails = array_values(array_filter((array) Arr::get($identity, 'emails', [])));
        $orderId = (int) Arr::get($identity, 'order_id', 0);
        $userId = (int) Arr::get($identity, 'user_id', 0);

        // Only when the grant supplies NO stable address — a customer row
        // that is gone. A guest buyer whose customer row still exists has an
        // email, and two of their orders for one product must contend on
        // that email, not on two different order keys.
        if ($orderId && !$userId && Arr::get($identity, 'order_keyed')) {
            return 'order-' . $orderId . '@review-slot.invalid';
        }

        return (string) ($emails[0] ?? $fallbackEmail);
    }

    /**
     * @param int   $postId
     * @param mixed $request the request the review came from
     * @param int   $itemId  the variation the review targets, 0 for the
     *                       product; only ever non-zero behind an order grant
     */
    public static function canSubmitReview($postId, $request, $itemId = 0): array
    {
        $itemId = max(0, (int) $itemId);

        if (!static::isReviewEnabledForProduct($postId)) {
            return [
                'can_submit' => false,
                'message'    => __('Reviews are currently disabled for this product', 'fluent-cart'),
            ];
        }

        $settings = static::getReviewSettings();

        $permissionMode = $settings['review_permission_mode'];
        $userId = get_current_user_id();

        // An order hash that names an order containing this product stands in
        // for the permission-mode check: the holder demonstrably bought the
        // thing, which is exactly what verified_buyers asks, and asking a
        // guest-checkout buyer to log in first would break the emailed link
        // that brought them here. See resolveOrderGrant() for why this is safe.
        $grant = static::resolveOrderGrant($postId, $request->get('order_hash'), $itemId);

        if (!$grant && $permissionMode === Status::REVIEW_PERMISSION_VERIFIED_BUYERS) {
            if (!$userId) {
                return [
                    'can_submit' => false,
                    'message'    => __('Please log in to leave a review', 'fluent-cart'),
                ];
            }

            $customer = Customer::query()->where('user_id', $userId)->first();
            if (!$customer || !static::hasOrderedProduct($postId, $customer->id)) {
                return [
                    'can_submit' => false,
                    'message'    => __('Only verified buyers can leave a review', 'fluent-cart'),
                ];
            }
        } elseif (!$grant && $permissionMode === Status::REVIEW_PERMISSION_LOGGED_IN) {
            if (!$userId) {
                return [
                    'can_submit' => false,
                    'message'    => __('Please log in to leave a review', 'fluent-cart'),
                ];
            }
        }
        // 'anyone' mode allows all

        // Check for duplicate review. Spam/trash are excluded (see
        // Status::getReviewDuplicateStatuses()): a rejected review does not
        // block a fresh submission.
        //
        // The slot is (product, item): a buyer who ordered Red and Blue may
        // review each once, and the product-level slot (item NULL) is its own.
        //
        // Keyed on the identity the review will actually be filed under —
        // the order's buyer when a grant owns the submission, else the
        // visitor. Reading the request here instead would let a guest walk
        // past the guard by typing a different address each time, and
        // searching for a signed-in stranger's own reviews would let a
        // forwarded link insert one more review under the buyer per reopen.
        $identity = static::effectiveReviewerIdentity($grant, (string) $request->get('reviewer_email'));

        // A guest with no grant and nothing typed has no identity to check
        // against; the submit path refuses that request for the missing
        // email before anything is written.
        if ($identity['user_id'] || $identity['emails'] || $identity['order_id']) {
            $existingReview = static::scopeToIdentity(static::scopeToItem(ProductReview::query(), $itemId), $identity)
                ->where('post_id', $postId)
                ->whereIn('status', Status::getReviewDuplicateStatuses())
                ->first();

            if ($existingReview) {
                return [
                    'can_submit' => false,
                    'message'    => $identity['user_id'] || $identity['via_grant']
                        ? __('You have already submitted a review for this product', 'fluent-cart')
                        : __('A review with this email already exists for this product', 'fluent-cart'),
                ];
            }
        }

        return [
            'can_submit' => true,
            'message'    => '',
        ];
    }

    // Generous upper bound on a review submission request (insert + meta save
    // + event dispatch + email). A lock older than this almost certainly
    // means the request that claimed it died before reaching its finally
    // block (fatal error, timeout, OOM kill) rather than genuine contention.
    const REVIEW_LOCK_TTL = 120;

    /**
     * Atomically claim the (product, identity) slot for the duration of a review
     * submission, closing the check-then-insert race that canSubmitReview()'s
     * duplicate lookup cannot close on its own — two concurrent requests for the
     * same product + identity can both pass that lookup before either insert
     * lands. Uses INSERT IGNORE against wp_options' UNIQUE(option_name), the
     * same primitive WP core's own locking helpers rely on, so only one caller
     * ever wins the row regardless of object-cache availability.
     *
     * The lock is short-lived and released via releaseReviewSlot() right after
     * the create attempt in the same request — it is a submission-time mutex,
     * not a permanent uniqueness record, so no cleanup on delete/trash/untrash
     * is needed. The real duplicate-prevention for subsequent requests stays in
     * canSubmitReview()'s query against the now-inserted row.
     *
     * If the slot is already held, its embedded timestamp is checked against
     * REVIEW_LOCK_TTL: a lock older than that is reclaimed via a
     * compare-and-swap UPDATE (only succeeds if option_value still equals the
     * stale value just read), so a lock a concurrent request is legitimately
     * still holding, or has already renewed/reclaimed itself, can never be
     * stolen out from under it — only a truly abandoned lock is ever reused.
     *
     * The stored value is "$timestamp:$ownerToken", not just a timestamp —
     * the random token is what makes an acquisition individually
     * identifiable, so releaseReviewSlot() can delete-if-still-mine instead
     * of delete-by-name. Without it, a request that outlives the TTL (slow,
     * not dead) would delete a successor's freshly-reclaimed lock the moment
     * it finally reaches its finally block, reopening the duplicate-review
     * race this exists to close.
     *
     * @param int    $postId
     * @param int    $userId     the account the row is filed under, 0 for none
     * @param string $email      the address the row is filed under, or the
     *                           synthetic order address from slotLockEmail()
     * @param int    $itemId     the variation slot, 0 for the product — a
     *                           separate lock per item, matching the duplicate
     *                           guard's (product, item) slot
     * @param int    $customerId the customer row, when the identity has one
     *                           and no account — the stable key for a guest buyer
     * @return array{name: string, value: string}|false lock claim on success,
     *         false if genuinely held
     */
    public static function claimReviewSlot($postId, $userId, $email, $itemId = 0, $customerId = 0)
    {
        global $wpdb;

        $itemId = max(0, (int) $itemId);

        // Every alias of one reviewer must contend on ONE lock name, or two
        // aliases race past the duplicate check together and both insert.
        // Canonical identity: the user account when any path resolves one —
        // directly, or from the submitted email via the WP account or a
        // customer record — failing that the customer row itself, which a
        // guest buyer keeps across a change of address — otherwise the
        // normalized email. A guest submitting with a customer's address and
        // that customer submitting logged in therefore claim the same lock.
        $normalizedEmail = strtolower(sanitize_email((string) $email));
        $resolvedUserId = (int) $userId;
        $resolvedCustomerId = (int) $customerId;

        if (!$resolvedUserId && $normalizedEmail) {
            $accountOwner = get_user_by('email', $normalizedEmail);
            $resolvedUserId = $accountOwner ? (int) $accountOwner->ID : 0;

            if (!$resolvedUserId) {
                $customerRow = Customer::query()
                    ->where('email', $normalizedEmail)
                    ->first(['id', 'user_id']);
                if ($customerRow) {
                    $resolvedUserId = (int) $customerRow->user_id;
                    $resolvedCustomerId = $resolvedCustomerId ?: (int) $customerRow->id;
                }
            }
        }

        if ($resolvedUserId) {
            $identity = 'u' . $resolvedUserId;
        } elseif ($resolvedCustomerId) {
            $identity = 'c' . $resolvedCustomerId;
        } else {
            $identity = 'e' . md5($normalizedEmail);
        }

        // The product-level name is unchanged so a lock claimed before items
        // existed still contends with one claimed after; an item slot gets
        // its own name, as it gets its own duplicate slot.
        $lockName = 'fct_review_lock_' . (int) $postId . ($itemId ? '_i' . $itemId : '') . '_' . $identity;
        $now = time();
        $ownerValue = $now . ':' . wp_generate_uuid4();

        $claimed = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $lockName,
            $ownerValue
        ));

        if ($claimed) {
            return ['name' => $lockName, 'value' => $ownerValue];
        }

        // Slot already held (or the INSERT hit a real DB error — either way
        // $claimed is falsy here). Read the current holder's value and
        // reclaim only if its embedded timestamp proves stale. A null read
        // here (the row was deleted between our INSERT and this SELECT —
        // e.g. the original holder's releaseReviewSlot() landed in that
        // exact window, or a genuine query error) is treated the same as
        // live contention: we deny this attempt rather than retry the
        // INSERT. The narrow race this misses — the slot was actually free
        // by the time we checked — self-heals on the caller's next submit
        // attempt; we never reclaim without positive proof the existing
        // lock is old.
        $existingValue = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $lockName
        ));

        if ($existingValue === null || (int) strtok($existingValue, ':') > $now - self::REVIEW_LOCK_TTL) {
            return false;
        }

        $reclaimed = $wpdb->update(
            $wpdb->options,
            ['option_value' => $ownerValue],
            ['option_name' => $lockName, 'option_value' => $existingValue]
        );

        return $reclaimed ? ['name' => $lockName, 'value' => $ownerValue] : false;
    }

    public static function releaseReviewSlot($lock): void
    {
        if (!$lock) {
            return;
        }

        global $wpdb;

        // Compare-and-delete on the exact owner value this call claimed —
        // never delete by option_name alone. If a later request's TTL
        // reclaim has since overwritten the value, this release no longer
        // matches and is a safe no-op, so a request that outlives the TTL
        // (slow, not dead) can never tear down a successor's live lock.
        $wpdb->delete($wpdb->options, [
            'option_name'  => $lock['name'],
            'option_value' => $lock['value'],
        ]);
    }

    /**
     * Build the payload for an admin reply, so the single-reply and bulk-reply
     * paths cannot drift apart.
     *
     * Trust fields (status, is_admin_reply, the replying user) are set here
     * rather than taken from the request — an admin reply is always authored
     * by the current user and always published.
     *
     * @param string $content Reply body
     * @param ProductReview|null $parentReview Parent review; omitted for bulk,
     *                                         where the resource fills it per row
     * @return array
     */
    public static function buildAdminReplyData($content, $parentReview = null): array
    {
        $user = wp_get_current_user();

        $data = [
            'content'        => $content,
            'title'          => '',
            'status'         => Status::REVIEW_APPROVED,
            'is_verified'    => 0,
            'is_admin_reply' => 1,
            'user_id'        => $user->ID,
            'reviewer_name'  => $user->display_name,
            'reviewer_email' => $user->user_email,
        ];

        if ($parentReview) {
            $data['parent_id'] = $parentReview->id;
            $data['post_id'] = $parentReview->post_id;
        }

        return $data;
    }

    /**
     * Clamp a submitted rating into the range the column accepts.
     *
     * The rating is a TINYINT capped at 5 and every write path has to bound
     * it, so the bound lives here rather than being re-typed at each one.
     */
    public static function clampRating($value): int
    {
        return max(0, min(5, (int) $value));
    }

    /**
     * Whether the store collects star ratings at all.
     *
     * Defaults to on, so a store that has never touched the setting shows the
     * rating control.
     */
    public static function isStarRatingEnabled(): bool
    {
        $settings = static::getReviewSettings();

        return !isset($settings['enable_star_rating']) || $settings['enable_star_rating'] === 'yes';
    }

    /**
     * Whether a review must carry a star rating on this store.
     *
     * Two settings decide it and BOTH have to be consulted: ratings can be
     * switched off entirely, or left on but optional. Both default to on, so
     * a store that has never touched them requires a rating.
     *
     * One place, because the call sites had drifted once already — an earlier
     * version of the admin path checked only the first of the two and was
     * therefore stricter than the customer form on the same store, and the
     * storefront renderer treated an absent `star_rating_required` as "not
     * required" while every server path treated it as "required".
     */
    public static function isStarRatingRequired(): bool
    {
        if (!static::isStarRatingEnabled()) {
            return false;
        }

        $settings = static::getReviewSettings();

        return !isset($settings['star_rating_required']) || $settings['star_rating_required'] === 'yes';
    }

    /**
     * Extensions may hold an approved edit for moderation, never publish a
     * pending, rejected or trashed review. Runs before the row is saved.
     */
    public static function applyUpdateStatusFilter($status, array $reviewData, $request, $review): string
    {
        if ($status !== Status::REVIEW_APPROVED) {
            return (string) $status;
        }

        $filtered = apply_filters('fluent_cart/review/update_status', $status, [
            'data' => $reviewData,
            'request' => $request,
            'review' => $review,
        ]);

        return $filtered === Status::REVIEW_PENDING ? Status::REVIEW_PENDING : $status;
    }

    /**
     * The status a submission is stored with, after anything that owns part
     * of the submission has had its say.
     *
     * Runs BEFORE applySubmitDataFilter(), which is the whole point: that one
     * captures status as a trust field and re-imposes it afterwards, so a
     * listener there cannot change it. Moderation is a decision the store
     * makes, not one an add-on may quietly take — but an add-on does own facts
     * the store's rule depends on. PRO knows a submission carries photos; free
     * does not, and the store's "auto approve photo reviews" switch cannot be
     * honoured without that.
     *
     * One direction only: a listener may hold a review back, never publish one
     * the store would have moderated. So a store that moderates everything
     * still moderates everything, whatever is installed, and the worst a buggy
     * or hostile listener can do is ask for more moderation.
     *
     * @param string $status     what the store's own rule decided
     * @param array  $reviewData the payload as assembled so far
     * @param mixed  $request    the request the review came from
     * @return string
     */
    public static function applySubmissionStatusFilter($status, array $reviewData, $request): string
    {
        $status = (string) $status;

        $filtered = apply_filters('fluent_cart/review/submission_status', $status, $reviewData, $request);
        $filtered = is_string($filtered) ? $filtered : '';

        // A status this install does not have means nothing; keep the store's.
        if (!in_array($filtered, Status::getReviewStatuses(), true)) {
            return $status;
        }

        // Already held back: nothing may release it.
        if ($status !== Status::REVIEW_APPROVED) {
            return $status;
        }

        // Approved may only become pending — never spam or trash, which are a
        // moderator's judgement and not a submission's starting point.
        return $filtered === Status::REVIEW_PENDING ? Status::REVIEW_PENDING : $status;
    }

    /**
     * Let extensions shape a review payload, then re-impose the fields the
     * server decided.
     *
     * The ordering is the whole point: the filter runs on the full payload so
     * a listener can add its own keys (the Pro media pipeline stashes pending
     * attachment ids in `meta` here), but ownership, moderation state and the
     * product cannot be rewritten by it — those are merged back on top
     * afterwards from values captured before the filter ran.
     *
     * @param array $reviewData ProductReviewResource::create() payload
     * @param mixed $request    the request the review came from
     */
    public static function applySubmitDataFilter(array $reviewData, $request, int $postId): array
    {
        $trustFields = [
            'post_id'        => $postId,
            // The item is settled by the order grant before this runs; a
            // filter cannot move the review to another variation.
            'item_id'        => max(0, (int) Arr::get($reviewData, 'item_id', 0)),
            'status'         => $reviewData['status'],
            'is_verified'    => $reviewData['is_verified'],
            // A submitted review is never a store reply, on either path.
            'is_admin_reply' => 0,
            'user_id'        => $reviewData['user_id'],
            'customer_id'    => $reviewData['customer_id'],
            'order_id'       => $reviewData['order_id'],
        ];

        $reviewData = apply_filters('fluent_cart/review/submit_data', $reviewData, $request, $postId);

        return array_merge($reviewData, $trustFields);
    }

    /**
     * Assemble the row an admin-authored review writes, the counterpart of
     * buildAdminReplyData() for top-level reviews.
     *
     * The storefront's submission gates deliberately do NOT run on this path:
     * no permission mode, no one-review-per-identity duplicate check, no rate
     * limit and no slot lock. Those rules exist to police anonymous visitors;
     * a store owner transcribing a review by hand (a phone call, an email, a
     * migration from another platform) is an authorization decision already
     * made by the reviews/manage capability. A moderator entering a second
     * review from the same buyer must not be refused by the guest rules.
     *
     * The row is guest-shaped — user_id stays null even when the email belongs
     * to a WP account — so an admin-written review never grants that account
     * storefront edit rights over words it did not write. A matching customer
     * IS linked, so the review shows its customer in the admin sidebar; the
     * customer's own My Reviews page already matches on email either way.
     *
     * Trust fields the caller must have decided server-side (status,
     * is_verified) are passed in rather than derived here, because the admin
     * chooses both explicitly in the add-review modal.
     *
     * @param array $input post_id, item_id, rating, title, content, status,
     *                     is_verified, reviewer_name, reviewer_email
     * @return array ProductReviewResource::create() payload
     */
    public static function buildAdminReviewData(array $input): array
    {
        $email = trim((string) Arr::get($input, 'reviewer_email', ''));

        $customerId = null;
        if ($email) {
            // Plain equality: the column's ci collation already ignores case,
            // and unlike LOWER() it keeps the email index usable — the same
            // rule canSubmitReview()'s logged-in branch follows.
            $customerId = Customer::query()->where('email', $email)->value('id');
        }

        return [
            'post_id'        => (int) Arr::get($input, 'post_id'),
            // Already resolved against the product by the caller; 0 is the
            // product-level slot, which the resource stores as NULL.
            'item_id'        => max(0, (int) Arr::get($input, 'item_id', 0)),
            'rating'         => (int) Arr::get($input, 'rating', 0),
            'title'          => (string) Arr::get($input, 'title', ''),
            'content'        => (string) Arr::get($input, 'content', ''),
            'status'         => Arr::get($input, 'status', Status::REVIEW_APPROVED),
            'is_verified'    => !empty($input['is_verified']) ? 1 : 0,
            'is_admin_reply' => 0,
            'user_id'        => 0,
            'customer_id'    => $customerId ? (int) $customerId : null,
            'order_id'       => null,
            'reviewer_name'  => trim((string) Arr::get($input, 'reviewer_name', '')),
            'reviewer_email' => $email,
        ];
    }

    /**
     * One page of public reviews, shaped for the storefront: fetched,
     * decorated with ownership and reply counts, serialized, and passed
     * through the public_response filter so PRO attaches its fields. The
     * REST endpoint and the server-rendered first page both read from here,
     * so the two cannot drift.
     *
     * @param array $params ProductReviewResource::get params.
     * @param int $postId
     * @return array ['reviews' => paginator array, ...filter additions]
     */
    public static function getPublicReviewsPayload(array $params, $postId): array
    {
        $reviews = ProductReviewResource::get($params);

        // Replies are NOT eager-loaded — fetched on demand via the replies
        // endpoint. photo is appended by the model itself.
        if ($reviews && method_exists($reviews, 'items')) {
            $currentUserId = get_current_user_id();
            $items = $reviews->items();

            // Batch reply counts in a single query, keyed by review id
            $reviewIds = array_map(fn($r) => (int) $r->id, $items);
            $replyCounts = [];
            if (!empty($reviewIds)) {
                $reviewsWithReplyCounts = ProductReview::query()
                    ->whereIn('id', $reviewIds)
                    ->withCount([
                        'replies as approved_reply_count' => function ($replyQuery) {
                            $replyQuery->where('status', Status::REVIEW_APPROVED);
                        },
                    ])
                    ->get();
                foreach ($reviewsWithReplyCounts as $countedReview) {
                    $replyCounts[(int) $countedReview->id] = (int) $countedReview->approved_reply_count;
                }
            }

            // One query for the page's item labels, before the rows are
            // serialised for the list renderer.
            static::attachItemLabels($items);

            foreach ($items as $review) {
                $review->is_owner = $currentUserId && (int) $review->user_id === $currentUserId;
                $review->reply_count = $replyCounts[(int) $review->id] ?? 0;
                $review->makeHidden(['reviewer_email', 'user_id', 'customer_id', 'order_id', 'meta']);
            }
        }

        $responseData = [
            'reviews' => $reviews->toArray(),
        ];

        return apply_filters('fluent_cart/review/public_response', $responseData, $postId);
    }

    /**
     * Per-request settings cache, shared by every caller of
     * getReviewSettings(). Held on the class rather than as a function static
     * so it can be cleared: a long-lived process (the test runner, WP-CLI)
     * outlives the single request the cache assumes, and a settings write
     * there would otherwise never be seen.
     *
     * @var array|null
     */
    protected static $reviewSettingsCache = null;

    /**
     * Drop the cached settings so the next read hits the option again.
     *
     * A no-op in a normal web request, which builds the cache once and dies.
     * It exists for processes that outlive one request and change the setting
     * mid-flight — the test suite above all.
     */
    public static function resetReviewSettingsCache(): void
    {
        static::$reviewSettingsCache = null;

        // ModuleSettings keeps its own per-request static one layer down, so
        // clearing only ours would still serve the stale module payload. The
        // uncached read reassigns that static as a side effect.
        ModuleSettings::getAllSettings(false);
    }

    public static function getReviewSettings(): array
    {
        if (static::$reviewSettingsCache !== null) {
            return static::$reviewSettingsCache;
        }

        $moduleSettings = ModuleSettings::getSettings('reviews');

        if (!$moduleSettings || !\is_array($moduleSettings)) {
            $moduleSettings = [];
        }

        $settings = [
            'reviews_enabled'        => Arr::get($moduleSettings, 'active', 'yes'),
            'review_permission_mode' => Arr::get($moduleSettings, 'review_permission_mode', Status::REVIEW_PERMISSION_VERIFIED_BUYERS),
            'auto_approve_reviews'   => Arr::get($moduleSettings, 'auto_approve_reviews', 'no'),
            'reviews_per_page'       => (int) Arr::get($moduleSettings, 'reviews_per_page', 10),
            'enable_star_rating'     => Arr::get($moduleSettings, 'enable_star_rating', 'yes'),
            'star_rating_required'   => Arr::get($moduleSettings, 'star_rating_required', 'yes'),
            'show_verified_badge'    => Arr::get($moduleSettings, 'show_verified_badge', 'yes'),
        ];

        // Merge any additional keys (e.g. PRO settings) from module settings
        unset($moduleSettings['active']);
        static::$reviewSettingsCache = array_merge($moduleSettings, $settings);

        return static::$reviewSettingsCache;
    }

    public static function getProductRatingSummary($postId): array
    {
        // Single source of truth: detail.other_info, maintained by recalculateProductRatings().
        // All consumers (product cards, admin list, reviews page) read from the same cache.
        $productDetail = ProductDetail::query()->where('post_id', $postId)->first();
        $productOtherInfo = ($productDetail && $productDetail->other_info) ? $productDetail->other_info : [];

        // If rating_breakdown is missing (product cached before this field was added),
        // backfill it once, then re-read.
        //
        // Guarded on $productDetail: a product with no fct_product_details row reaches
        // here with $productOtherInfo = [], which passes the array_key_exists check
        // below — calling refresh() on the null would fatal on a public request.
        //
        // The backfill is a write inside a read path, so it is throttled with a short
        // lock: without it every concurrent visitor to an unbackfilled product runs the
        // same two aggregate queries and the same save.
        if ($productDetail && !array_key_exists('rating_breakdown', $productOtherInfo)) {
            $lockKey = 'fct_rating_backfill_' . (int) $postId;

            if (!get_transient($lockKey)) {
                set_transient($lockKey, 1, MINUTE_IN_SECONDS);
                ProductReviewResource::recalculateProductRatings($postId);
                $productDetail->refresh();
                $productOtherInfo = $productDetail->other_info ?: [];
            }
        }

        $defaultBreakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        $starBreakdown = $defaultBreakdown;
        if (array_key_exists('rating_breakdown', $productOtherInfo) && is_array($productOtherInfo['rating_breakdown'])) {
            foreach ($productOtherInfo['rating_breakdown'] as $starValue => $reviewCount) {
                $starValue = (int) $starValue;
                if ($starValue >= 1 && $starValue <= 5) {
                    $starBreakdown[$starValue] = (int) $reviewCount;
                }
            }
        }

        $totalReviews = array_key_exists('review_count', $productOtherInfo) ? (int) $productOtherInfo['review_count'] : 0;
        $averageRating = array_key_exists('average_rating', $productOtherInfo) ? round((float) $productOtherInfo['average_rating'], 2) : 0;

        return [
            'breakdown' => $starBreakdown,
            'total'     => $totalReviews,
            'average'   => $averageRating,
        ];
    }

    /**
     * A product title fit for a JSON payload: the one stored on the product.
     *
     * Not get_the_title(), which runs the_title — wptexturize curls its
     * quotes and dashes and hands them back as HTML entities. That is right
     * for markup and wrong for JSON, where the dashboard renders titles as
     * text and a product came out reading Men&#8217;s Hoodie.
     *
     * Reading the column instead of decoding the filtered version is what
     * the rest of the plugin already does: the products table and the admin
     * reviews table both print post_title straight off the model, so a title
     * now reads the same wherever it appears.
     */
    protected static function productTitle($postId): string
    {
        return (string) get_post_field('post_title', $postId, 'raw');
    }

    /**
     * A review row belongs to this customer when its user id matches, or —
     * for reviews left before the account existed, or as a guest — when the
     * reviewer email matches. Every "my reviews" query and its inverse (the
     * to-be-reviewed exclusion) must share this predicate or the two lists
     * drift: a product could show as reviewed in one and pending in the other.
     */
    protected static function scopeReviewsOfCustomer($query, Customer $customer)
    {
        $identityEmails = static::customerIdentityEmails($customer);

        return $query->where(function ($identityQuery) use ($customer, $identityEmails) {
            $identityQuery->whereIn('reviewer_email', $identityEmails);
            if ($customer->user_id) {
                $identityQuery->orWhere('user_id', $customer->user_id);
            }
        });
    }

    /**
     * Every email that identifies this customer: the customer record's own
     * address plus the linked WP account's, which can diverge. One set,
     * used by the dashboard queries and mirrored by canSubmitReview()'s
     * duplicate guard — if the two ever disagree, a product can sit in
     * To Be Reviewed while submission reports a duplicate, or the reverse.
     */
    protected static function customerIdentityEmails(Customer $customer): array
    {
        $emails = [(string) $customer->email];

        if ($customer->user_id) {
            $user = get_userdata((int) $customer->user_id);
            if ($user && $user->user_email) {
                $emails[] = (string) $user->user_email;
            }
        }

        return array_values(array_unique(array_filter($emails)));
    }

    /**
     * The base query every My Reviews surface builds on: the customer's own
     * top-level reviews in the statuses that count as "written" — the same
     * set canSubmitReview() treats as blocking a duplicate. Spam and trash
     * are out on BOTH sides: the customer neither sees them in history nor
     * has them block the to-be-reviewed list, matching the write path where
     * a rejected review permits a fresh submission.
     */
    protected static function activeReviewsOfCustomerQuery(Customer $customer)
    {
        return static::scopeReviewsOfCustomer(
            ProductReview::query()
                ->whereNull('parent_id')
                // Everything the author may see — approved and pending alike,
                // never spam or trash. Not the duplicate set: see
                // Status::getReviewAuthorVisibleStatuses().
                ->whereIn('status', Status::getReviewAuthorVisibleStatuses()),
            $customer
        );
    }

    /**
     * How many reviews the customer has written — the History tab's count,
     * cheap enough to ride along with the pending payload so the tab label
     * is right before the tab is ever opened.
     */
    public static function countCustomerReviews(Customer $customer): int
    {
        return (int) static::activeReviewsOfCustomerQuery($customer)->count();
    }

    /**
     * The customer's own reviews (their whole history, pending included —
     * the customer may always see what they wrote), newest first, with the
     * reviewed product's title, link and thumbnail attached to each row.
     */
    public static function getCustomerReviewsPayload(Customer $customer, array $params): array
    {
        $perPage = min(50, max(1, (int) Arr::get($params, 'per_page', 10)));
        $page = max(1, (int) Arr::get($params, 'page', 1));

        $reviews = static::activeReviewsOfCustomerQuery($customer)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->paginate($perPage, ['*'], 'page', $page);

        $postIds = array_map('intval', $reviews->getCollection()->pluck('post_id')->all());
        if ($postIds) {
            _prime_post_caches($postIds, false, true);
        }

        // The item each review names, under the product title — one query
        // for the page, like every other surface that shows a review.
        static::attachItemLabels($reviews->getCollection()->all());

        // Who may edit, decided here rather than in the browser: user_id is
        // hidden from the payload precisely so the client cannot reason about
        // ownership, and updateReview() pins id + post_id + user_id. A row the
        // customer reached by email alone (a guest review, no account behind
        // it) is theirs to read and not theirs to change — offering an Edit
        // button there would only produce a 404 they cannot act on.
        $viewerUserId = get_current_user_id();

        $reviews->getCollection()->transform(function ($review) use ($viewerUserId) {
            $review->setAttribute(
                'can_edit',
                $viewerUserId && (int) $review->user_id === (int) $viewerUserId
            );
            $review->makeHidden(['reviewer_email', 'user_id', 'customer_id', 'order_id', 'meta']);
            $review->setAttribute('product_title', static::productTitle($review->post_id));
            $review->setAttribute('product_url', (string) get_permalink($review->post_id));
            $review->setAttribute('product_thumbnail', (string) get_the_post_thumbnail_url($review->post_id, 'thumbnail'));
            return $review;
        });

        $responseData = [
            'reviews' => $reviews->toArray(),
        ];

        // PRO attaches its extras (media, vote counts) here. Context travels
        // as a read-only array, the repository's hook contract.
        return apply_filters('fluent_cart/review/customer_reviews_response', $responseData, [
            'customer' => $customer,
        ]);
    }

    /**
     * Products the customer ordered and has not reviewed yet — the "to be
     * reviewed" list. Order eligibility matches hasOrderedProduct(), the
     * predicate canSubmitReview() uses: any order unlocks the review form,
     * so any order surfaces the product here.
     *
     * Every phase is bounded so cost cannot grow with the customer's whole
     * history: candidates come from their most recent order items only (one
     * indexed, LIMIT-ed scan — no grouping, no correlated subquery), then a
     * single whereIn query against the same identity-and-status predicate
     * the history list uses marks the reviewed ones, so the two tabs stay
     * complementary. Products older than the candidate window are
     * deliberately outside this nudge list.
     */
    public static function getPendingReviewProducts(Customer $customer, int $limit = 50): array
    {
        if (static::getReviewSettings()['reviews_enabled'] !== 'yes') {
            return [];
        }

        $limit = min(50, max(1, $limit));

        // Bounded candidate phases — bounded in rows SCANNED, not just rows
        // returned. Phase one reads the customer's most recent orders
        // straight off the customer_id index in reverse-id order (EXPLAIN:
        // Backward index scan, no filesort), so its cost is fixed by the
        // LIMIT however long the purchase history grows.
        $recentOrderIds = Order::query()
            ->where('customer_id', $customer->id)
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->pluck('id')
            ->all();

        if (!$recentOrderIds) {
            return [];
        }

        // Phase two: those orders' items under a hard examined-row budget.
        // Each chunk query carries a LIMIT and NO ORDER BY, so the range
        // scan over the order_id index stops at the limit — one enormous
        // order can contribute at most a chunk's budget instead of forcing
        // a filesort of its entire contents. Chunks run newest orders
        // first, and each chunk's rows are re-ranked by order recency in
        // PHP (a bounded set), so candidate priority stays purchase
        // recency; within one order the items share their creation moment,
        // so intra-order order carries no information. Worst case examined:
        // 5 chunks x 200 rows.
        $orderRecency = array_flip($recentOrderIds);
        $candidates = [];

        foreach (array_chunk($recentOrderIds, 10) as $orderIdChunk) {
            if (count($candidates) >= 200) {
                break;
            }

            $chunkItems = OrderItem::query()
                ->select(['id', 'post_id', 'title', 'created_at', 'order_id'])
                ->whereIn('order_id', $orderIdChunk)
                ->limit(200)
                ->get()
                ->all();

            usort($chunkItems, function ($a, $b) use ($orderRecency) {
                $aRank = isset($orderRecency[$a->order_id]) ? $orderRecency[$a->order_id] : PHP_INT_MAX;
                $bRank = isset($orderRecency[$b->order_id]) ? $orderRecency[$b->order_id] : PHP_INT_MAX;

                if ($aRank !== $bRank) {
                    return $aRank <=> $bRank;
                }

                // Within one order, latest line item first — the tie-break
                // the previous created_at/id sort applied.
                return $b->id <=> $a->id;
            });

            // Newest order's items land first, so the first sighting of a
            // product is its latest purchase — variation title included.
            foreach ($chunkItems as $item) {
                $itemPostId = (int) $item->post_id;
                if (!$itemPostId || isset($candidates[$itemPostId])) {
                    continue;
                }
                $candidates[$itemPostId] = [
                    'variation_title'   => (string) $item->title,
                    'last_purchased_at' => (string) $item->created_at,
                ];
            }
        }

        if (!$candidates) {
            return [];
        }

        $postIds = array_keys($candidates);

        // Which candidates the customer already reviewed — one bounded
        // whereIn query on the shared identity/status predicate.
        $reviewedPostIds = array_fill_keys(
            array_map('intval', static::activeReviewsOfCustomerQuery($customer)
                ->whereIn('post_id', $postIds)
                ->pluck('post_id')
                ->all()),
            true
        );

        // One query for every product's own reviews toggle and variation
        // type — never per row.
        $reviewsDisabledFor = [];
        $isVariableProduct = [];
        $details = ProductDetail::query()->whereIn('post_id', $postIds)->get();
        foreach ($details as $detail) {
            $otherInfo = $detail->other_info;
            if (isset($otherInfo['reviews_enabled']) && $otherInfo['reviews_enabled'] === 'no') {
                $reviewsDisabledFor[(int) $detail->post_id] = true;
            }
            // Positive signal only: an empty variation_type (drifted or
            // incomplete detail row) must read as simple, not variable —
            // otherwise a lone default variation named "Simple" leaks in.
            if ($detail->variation_type && $detail->variation_type !== 'simple') {
                $isVariableProduct[(int) $detail->post_id] = true;
            }
        }

        _prime_post_caches($postIds, false, true);

        $products = [];
        foreach ($candidates as $postId => $candidate) {
            if (count($products) >= $limit) {
                break;
            }
            if (
                isset($reviewedPostIds[$postId])
                || isset($reviewsDisabledFor[$postId])
                || get_post_status($postId) !== 'publish'
            ) {
                continue;
            }

            $products[] = [
                'post_id'           => $postId,
                'title'             => static::productTitle($postId),
                'variation_title'   => isset($isVariableProduct[$postId])
                    ? $candidate['variation_title']
                    : '',
                'url'               => (string) get_permalink($postId),
                'thumbnail'         => (string) get_the_post_thumbnail_url($postId, 'thumbnail'),
                'last_purchased_at' => $candidate['last_purchased_at'],
            ];
        }

        return $products;
    }

    /**
     * Where a notification about this review goes.
     *
     * The address the review was filed under, first: the storefront requires
     * it from a guest and copies it from the account or the order grant for
     * everyone else, so on that path it is always there. The fallbacks exist
     * for a review a moderator typed in by hand, which can be saved without
     * one — the account it names, then the customer it names. Never the
     * order: a review can be written before an order, after it, or from a
     * customer with several, and the order's address is a guess about a
     * different thing.
     *
     * Every step is checked, not just the first. A malformed address a
     * moderator typed fails as quietly as an empty one, and the fallback
     * behind it may be perfectly good.
     *
     * One resolver for every review notification, so no two emails about the
     * same review can be sent to different people. A reply notifies the
     * author of the review it answers, so callers pass the parent.
     *
     * @param ProductReview $review
     * @return string a valid address, or '' when there is none to send to
     */
    public static function resolveNotificationRecipient(ProductReview $review): string
    {
        $candidateEmails = [(string) $review->reviewer_email];

        if ($review->user_id) {
            $user = get_userdata((int) $review->user_id);
            $candidateEmails[] = $user ? (string) $user->user_email : '';
        }

        if ($review->customer_id) {
            $customer = Customer::query()->find((int) $review->customer_id);
            $candidateEmails[] = $customer ? (string) $customer->email : '';
        }

        foreach ($candidateEmails as $candidateEmail) {
            $candidateEmail = trim($candidateEmail);
            if ($candidateEmail !== '' && is_email($candidateEmail)) {
                return $candidateEmail;
            }
        }

        return '';
    }

    /**
     * Take the lease to send this review's approval notice.
     *
     * @param ProductReview $review
     * @return string|null the lease token when this caller holds the lease; null when it does not
     */
    public static function claimApprovalNotice(ProductReview $review)
    {
        return static::claimNoticeOnce((int) $review->id, 'approval_notice');
    }

    /**
     * The notifications already delivered for this review's approval under
     * an earlier, released lease — so a retry sends only what has not.
     *
     * @param int $reviewId
     * @return string[] notification names
     */
    public static function deliveredApprovalNotices(int $reviewId): array
    {
        return static::deliveredNotices($reviewId, 'approval_notice');
    }

    /**
     * Record, under the held lease, the approval notifications delivered so
     * far — see recordNoticeDeliveries().
     *
     * @param int $reviewId
     * @param string $token
     * @param string[] $deliveredNames
     */
    public static function recordApprovalNoticeDeliveries(int $reviewId, string $token, array $deliveredNames): void
    {
        static::recordNoticeDeliveries($reviewId, 'approval_notice', $token, $deliveredNames);
    }

    /**
     * Settle the approval notice's lease: delivered, or released. Only by
     * its holder — see settleNotice().
     *
     * @param int $reviewId
     * @param string $token
     * @param bool $delivered every notification went out
     * @param string[] $deliveredNames the notifications that did go out, delivered or not
     */
    public static function settleApprovalNotice(int $reviewId, string $token, bool $delivered, array $deliveredNames = []): void
    {
        static::settleNotice($reviewId, 'approval_notice', $token, $delivered, $deliveredNames);
    }

    /**
     * Take the lease to tell the reviewer about this store reply. The lease
     * sits on the reply row, so each reply is announced once and a second
     * reply to the same review is announced on its own.
     *
     * @param ProductReview $reply
     * @return string|null the lease token when this caller holds the lease; null when it does not
     */
    public static function claimReplyNotice(ProductReview $reply)
    {
        return static::claimNoticeOnce((int) $reply->id, 'reply_notice');
    }

    /**
     * The notifications already delivered for this reply under an earlier,
     * released lease.
     *
     * @param int $replyId
     * @return string[] notification names
     */
    public static function deliveredReplyNotices(int $replyId): array
    {
        return static::deliveredNotices($replyId, 'reply_notice');
    }

    /**
     * Record, under the held lease, the reply notifications delivered so far
     * — see recordNoticeDeliveries().
     *
     * @param int $replyId
     * @param string $token
     * @param string[] $deliveredNames
     */
    public static function recordReplyNoticeDeliveries(int $replyId, string $token, array $deliveredNames): void
    {
        static::recordNoticeDeliveries($replyId, 'reply_notice', $token, $deliveredNames);
    }

    /**
     * Settle the reply notice's lease: delivered, or released. Only by its
     * holder — see settleNotice().
     *
     * @param int $replyId
     * @param string $token
     * @param bool $delivered every notification went out
     * @param string[] $deliveredNames the notifications that did go out, delivered or not
     */
    public static function settleReplyNotice(int $replyId, string $token, bool $delivered, array $deliveredNames = []): void
    {
        static::settleNotice($replyId, 'reply_notice', $token, $delivered, $deliveredNames);
    }

    /**
     * Take a once-only lease on a notice for a review row.
     *
     * A notice must go out once, however many jobs run for the same row at
     * once. A flag read then written from PHP cannot promise that — two jobs
     * read "unsent" together and both send. The lease is one conditional
     * UPDATE, so the row is handed to exactly one caller and the other sees
     * zero affected rows.
     *
     * A lease, not a receipt: it is taken before the send and settled after,
     * by settleNotice(). The key in other_info reads
     *   (absent)  never sent — claimable
     *   sending   leased, delivery in progress — not claimable until the
     *             lease is older than NOTICE_LEASE_SECONDS
     *   sent      delivered — never claimable again
     * A send that fails or throws releases the lease (back to absent), so a
     * retry or a later event can send; a claim written as "sent" up front
     * would make every transport failure permanent. A lease nobody settled —
     * the process killed between the claim and its finally — would otherwise
     * hold the row forever, so a "sending" older than the timeout is treated
     * as abandoned and may be taken over.
     *
     * The lease names its holder: a token, returned to the caller and written
     * beside the state. Settling requires it, so a worker that stalled past
     * the timeout and wakes after another has taken the lease over cannot
     * close or release what is no longer its own.
     *
     * JSON_MERGE_PATCH rather than a PHP-side merge and save, for the same
     * reason recalculateProductRatings() uses it: other_info also carries the
     * review's photos, and a read-merge-write here would race the media
     * pipeline for the same blob. Only these keys are written.
     *
     * No double quotes anywhere in the statement — WPFluent rewrites every
     * double quote in a compiled query to a backtick. The key is one of two
     * literals from this class, never input, and is checked as such before it
     * is put into the JSON path.
     *
     * @param int $rowId
     * @param string $noticeKey 'approval_notice' or 'reply_notice'
     * @return string|null the lease token when this caller holds the lease; null when it does not
     */
    protected static function claimNoticeOnce(int $rowId, string $noticeKey)
    {
        global $wpdb;

        if (!static::isNoticeKey($noticeKey)) {
            return null;
        }

        $query = ProductReview::query();
        $token = wp_generate_password(16, false);

        $state = static::noticeField($noticeKey);
        $leasedAt = static::noticeField($noticeKey . '_at');

        // A lease older than this with nobody to settle it is abandoned.
        // Well past any send, which is seconds; short enough that a killed
        // worker does not silence a row for long.
        $abandonedBefore = DateTime::gmtNow()
            ->subSeconds(static::NOTICE_LEASE_SECONDS)
            ->format('Y-m-d H:i:s');

        $claimedRowCount = $query
            ->where('id', $rowId)
            ->whereRaw($wpdb->prepare(
                "(" . $state . " IS NULL OR (" . $state . " = 'sending' AND " . $leasedAt . " < %s))",
                $abandonedBefore
            ))
            ->update([
                'other_info' => $query->raw($wpdb->prepare(
                    "JSON_MERGE_PATCH(
                        IF(other_info IS NULL OR other_info = '', '{}', other_info),
                        JSON_OBJECT('" . $noticeKey . "', 'sending', '" . $noticeKey . "_at', %s, '" . $noticeKey . "_token', %s)
                    )",
                    DateTime::gmtNow()->format('Y-m-d H:i:s'),
                    $token
                )),
            ]);

        return (int) $claimedRowCount === 1 ? $token : null;
    }

    /**
     * The notifications a released lease left as delivered, so the retry
     * sends only the rest.
     *
     * @param int $rowId
     * @param string $noticeKey
     * @return string[]
     */
    protected static function deliveredNotices(int $rowId, string $noticeKey): array
    {
        if (!static::isNoticeKey($noticeKey)) {
            return [];
        }

        $row = ProductReview::query()->find($rowId);
        $names = $row && is_array($row->other_info)
            ? Arr::get($row->other_info, $noticeKey . '_delivered', [])
            : [];

        return array_values(array_filter(array_map('strval', (array) $names)));
    }

    /**
     * Record, under a held lease, the notifications delivered so far — before
     * the next one is attempted and before the lease is settled.
     *
     * Written the moment the transport accepts a message rather than at the
     * end of the loop, so the window in which a killed worker leaves a
     * delivery unrecorded — and a later holder repeats it — is the one UPDATE
     * after the send, not the whole send. Only by the holder, like settle.
     *
     * @param int $rowId
     * @param string $noticeKey
     * @param string $token
     * @param string[] $deliveredNames every notification delivered under this and earlier leases
     */
    protected static function recordNoticeDeliveries(int $rowId, string $noticeKey, string $token, array $deliveredNames): void
    {
        global $wpdb;

        if (!static::isNoticeKey($noticeKey)) {
            return;
        }

        $deliveredNames = array_values(array_unique(array_filter(array_map('strval', $deliveredNames))));
        if (!$deliveredNames) {
            return;
        }

        $query = ProductReview::query();
        $deliveredJson = $wpdb->prepare('JSON_ARRAY(' . implode(', ', array_fill(0, count($deliveredNames), '%s')) . ')', $deliveredNames);

        $query
            ->where('id', $rowId)
            ->whereRaw($wpdb->prepare(
                static::noticeField($noticeKey) . " = 'sending' AND " . static::noticeField($noticeKey . '_token') . " = %s",
                $token
            ))
            ->update([
                'other_info' => $query->raw(
                    "JSON_MERGE_PATCH(IF(other_info IS NULL OR other_info = '', '{}', other_info), JSON_OBJECT('" . $noticeKey . "_delivered', " . $deliveredJson . "))"
                ),
            ]);
    }

    /**
     * Settle a lease taken by claimNoticeOnce(): delivered, or not.
     *
     * Only by its holder: the row is touched only while it still reads
     * "sending" under this token. A lease taken over after the timeout
     * carries a new token, and the stalled worker's late settle finds
     * nothing to settle.
     *
     * Delivered closes the notice for good. Not delivered releases the lease —
     * the keys are removed, so the row reads as never sent and the next job or
     * event can try again — but keeps the names of the notifications that did
     * go out under it, so that retry does not send them a second time. One
     * lease covers every notification switched on for the event, and a
     * failure on the second must not repeat the first. (In a JSON merge patch,
     * null removes a key.)
     *
     * @param int $rowId
     * @param string $noticeKey
     * @param string $token the token claimNoticeOnce() handed this caller
     * @param bool $delivered every notification went out
     * @param string[] $deliveredNames the notifications that did go out, delivered or not
     */
    protected static function settleNotice(int $rowId, string $noticeKey, string $token, bool $delivered, array $deliveredNames = []): void
    {
        global $wpdb;

        if (!static::isNoticeKey($noticeKey)) {
            return;
        }

        $query = ProductReview::query();

        if ($delivered) {
            $patch = $wpdb->prepare(
                "JSON_OBJECT('" . $noticeKey . "', 'sent', '" . $noticeKey . "_at', %s, '" . $noticeKey . "_token', NULL, '" . $noticeKey . "_delivered', NULL)",
                DateTime::gmtNow()->format('Y-m-d H:i:s')
            );
        } else {
            $deliveredNames = array_values(array_unique(array_filter(array_map('strval', $deliveredNames))));
            // JSON_ARRAY() of bound strings, the way the rest of the patch is
            // built — names are notification registry keys, never typed by
            // a user, but they are bound rather than interpolated all the same.
            $deliveredJson = $deliveredNames
                ? $wpdb->prepare('JSON_ARRAY(' . implode(', ', array_fill(0, count($deliveredNames), '%s')) . ')', $deliveredNames)
                : 'NULL';
            $patch = "JSON_OBJECT('" . $noticeKey . "', NULL, '" . $noticeKey . "_at', NULL, '" . $noticeKey . "_token', NULL, '" . $noticeKey . "_delivered', " . $deliveredJson . ")";
        }

        $query
            ->where('id', $rowId)
            ->whereRaw($wpdb->prepare(
                static::noticeField($noticeKey) . " = 'sending' AND " . static::noticeField($noticeKey . '_token') . " = %s",
                $token
            ))
            ->update([
                'other_info' => $query->raw(
                    "JSON_MERGE_PATCH(IF(other_info IS NULL OR other_info = '', '{}', other_info), " . $patch . ")"
                ),
            ]);
    }

    /**
     * The SQL that reads one notice field out of other_info, empty or NULL
     * blobs included. The key is one of this class's own literals — see
     * isNoticeKey() — never input.
     */
    protected static function noticeField(string $key): string
    {
        return "JSON_UNQUOTE(JSON_EXTRACT(IF(other_info IS NULL OR other_info = '', '{}', other_info), '$." . $key . "'))";
    }

    /**
     * The two notice keys this class writes. Anything else never reaches a
     * JSON path.
     */
    protected static function isNoticeKey(string $noticeKey): bool
    {
        return in_array($noticeKey, ['approval_notice', 'reply_notice'], true);
    }
}
