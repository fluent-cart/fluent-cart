<?php

namespace FluentCart\App\Models;

use FluentCart\Framework\Database\Orm\Builder;

/**
 *  ProductReview Model - Backed by WordPress comments (wp_comments + wp_commentmeta)
 *
 *  Database Model
 *
 * This model is intended to be used for relationships and DB query.
 * For insert/update we use WordPress's native comment functions.
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class ProductReview extends Model
{
    protected $table = 'comments';

    protected $primaryKey = 'comment_ID';

    const COMMENT_TYPE = 'fct_product_review';

    // Meta key constants (stored in wp_commentmeta)
    const META_RATING         = '_fct_rating';
    const META_TITLE          = '_fct_title';
    const META_IS_VERIFIED    = '_fct_is_verified';
    const META_IS_ADMIN_REPLY = '_fct_is_admin_reply';
    const META_CUSTOMER_ID    = '_fct_customer_id';
    const META_ORDER_ID       = '_fct_order_id';
    const META_DATA           = '_fct_review_data';
    const META_MEDIA          = '_fct_review_media';

    const CREATED_AT = null;
    const UPDATED_AT = null;
    public $timestamps = false;

    // Defense in depth only — every actual write to this model goes through
    // WordPress's native wp_insert_comment()/wp_update_comment()/
    // update_comment_meta() (see ProductReviewResource), never Eloquent's
    // fill()/create(), so the real trust-field protection lives in the
    // controllers' explicit field whitelist (see $trustFields in
    // ProductReviewFrontendController::submitReview()). This guard exists so
    // a future endpoint that DOES fill the model directly from request data
    // cannot mass-assign identity or approval state.
    protected $guarded = ['comment_ID', 'user_id', 'comment_approved'];

    protected $hidden = [
        'comment_ID', 'comment_post_ID', 'comment_parent',
        'comment_author', 'comment_author_email', 'comment_content',
        'comment_approved', 'comment_date', 'comment_date_gmt',
        'comment_type', 'comment_author_url', 'comment_author_IP',
        'comment_karma', 'comment_agent',
    ];

    protected $appends = [
        'id', 'post_id', 'parent_id', 'reviewer_name', 'reviewer_email',
        'content', 'status', 'created_at', 'rating', 'title',
        'is_verified', 'is_admin_reply', 'customer_id', 'order_id',
    ];

    protected static $statusToWp = [
        'approved'     => '1',
        'pending'      => '0',
        'spam'         => 'spam',
        'trash'        => 'trash',
        'post-trashed' => 'post-trashed',
    ];

    protected static $statusFromWp = [
        '1'            => 'approved',
        '0'            => 'pending',
        'spam'         => 'spam',
        'trash'        => 'trash',
        'post-trashed' => 'post-trashed',
    ];

    /**
     * Local meta cache to avoid repeated get_comment_meta calls.
     */
    protected $metaCache = [];

    public static function boot()
    {
        parent::boot();

        static::addGlobalScope('comment_type', function (Builder $builder) {
            $builder->where('comment_type', static::COMMENT_TYPE);
        });
    }

    public function getIdAttribute()
    {
        return (int) ($this->attributes['comment_ID'] ?? 0);
    }

    public function getPostIdAttribute()
    {
        return (int) ($this->attributes['comment_post_ID'] ?? 0);
    }

    public function getParentIdAttribute()
    {
        return (int) ($this->attributes['comment_parent'] ?? 0);
    }

    public function getReviewerNameAttribute()
    {
        return $this->attributes['comment_author'] ?? '';
    }

    public function getReviewerEmailAttribute()
    {
        return $this->attributes['comment_author_email'] ?? '';
    }

    public function getContentAttribute()
    {
        return $this->attributes['comment_content'] ?? '';
    }

    public function getCreatedAtAttribute()
    {
        return $this->attributes['comment_date_gmt'] ?? null;
    }

    public function getStatusAttribute()
    {
        $wpValue = (string) ($this->attributes['comment_approved'] ?? '0');
        return static::$statusFromWp[$wpValue] ?? 'pending';
    }

    public function setPostIdAttribute($value)
    {
        $this->attributes['comment_post_ID'] = (int) $value;
    }

    public function setParentIdAttribute($value)
    {
        $this->attributes['comment_parent'] = (int) $value;
    }

    public function setReviewerNameAttribute($value)
    {
        $this->attributes['comment_author'] = $value;
    }

    public function setReviewerEmailAttribute($value)
    {
        $this->attributes['comment_author_email'] = $value;
    }

    public function setContentAttribute($value)
    {
        $this->attributes['comment_content'] = $value;
    }

    public function setStatusAttribute($value)
    {
        $this->attributes['comment_approved'] = static::$statusToWp[$value] ?? $value;
    }

    public function getRatingAttribute()
    {
        return (int) $this->getMetaValue(static::META_RATING);
    }

    public function getTitleAttribute()
    {
        return $this->getMetaValue(static::META_TITLE) ?: '';
    }

    public function getIsVerifiedAttribute()
    {
        return (int) $this->getMetaValue(static::META_IS_VERIFIED);
    }

    public function getIsAdminReplyAttribute()
    {
        return (int) $this->getMetaValue(static::META_IS_ADMIN_REPLY);
    }

    public function getCustomerIdAttribute()
    {
        return (int) $this->getMetaValue(static::META_CUSTOMER_ID);
    }

    public function getOrderIdAttribute()
    {
        return (int) $this->getMetaValue(static::META_ORDER_ID);
    }

    public function getMetaAttribute()
    {
        $raw = $this->getMetaValue(static::META_DATA);
        if (is_array($raw)) {
            return $raw;
        }
        return !empty($raw) ? json_decode($raw, true) : null;
    }

    protected function getMetaValue($key)
    {
        // Check local cache
        if (array_key_exists($key, $this->metaCache)) {
            return $this->metaCache[$key];
        }

        // Load from WP (uses WP object cache if primed via newCollection)
        if ($this->exists && !empty($this->attributes['comment_ID'])) {
            $value = get_comment_meta($this->attributes['comment_ID'], $key, true);
            $this->metaCache[$key] = $value !== '' ? $value : null;
            return $this->metaCache[$key];
        }

        return null;
    }

    public static function translateStatusToWp($status)
    {
        if (is_array($status)) {
            return array_map([static::class, 'translateStatusToWp'], $status);
        }
        return static::$statusToWp[$status] ?? $status;
    }

    public static function translateStatusFromWp($value)
    {
        return static::$statusFromWp[(string) $value] ?? 'pending';
    }

    /**
     * Get the comment type constant.
     * Used by Pro plugin for subqueries.
     */
    public static function getCommentType()
    {
        return static::COMMENT_TYPE;
    }

    public function newCollection(array $models = [])
    {
        $collection = parent::newCollection($models);

        // Prime WP meta cache in one query for all fetched reviews
        $ids = [];
        foreach ($models as $model) {
            $id = $model->getAttribute('comment_ID');
            if ($id) {
                $ids[] = $id;
            }
        }
        if (!empty($ids)) {
            update_meta_cache('comment', $ids);
        }

        return $collection;
    }

    public function scopeTopLevel($query)
    {
        return $query->where('comment_parent', 0);
    }

    public function scopeOfStatus($query, $status)
    {
        if (!$status || $status === 'all') {
            return $query;
        }
        return $query->where('comment_approved', static::translateStatusToWp($status));
    }

    public function scopeApproved($query)
    {
        return $query->where('comment_approved', '1');
    }

    public function scopeOfProduct($query, $postId)
    {
        return $postId ? $query->where('comment_post_ID', (int) $postId) : $query;
    }

    public function scopeOfRating($query, $rating)
    {
        if (!$rating || !is_numeric($rating)) {
            return $query;
        }

        return $query->whereExists(function ($sub) use ($rating) {
            $sub->from('commentmeta')
                ->whereColumn('commentmeta.comment_id', 'comments.comment_ID')
                ->where('commentmeta.meta_key', static::META_RATING)
                ->where('commentmeta.meta_value', (string) (int) $rating);
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'comment_post_ID', 'ID');
    }

    public function replies()
    {
        return $this->hasMany(static::class, 'comment_parent', 'comment_ID');
    }

    /**
     * Batch-load customer and/or order relations onto a collection of reviews.
     *
     * @param \FluentCart\Framework\Database\Orm\Collection|\FluentCart\Framework\Pagination\LengthAwarePaginator $reviews
     * @param array $relations e.g. ['customer', 'order']
     */
    public static function loadMetaRelations($reviews, array $relations = [])
    {
        $items = method_exists($reviews, 'items') ? $reviews->items() : $reviews->all();
        if (empty($items)) {
            return;
        }

        if (in_array('customer', $relations)) {
            $customerIds = array_unique(array_filter(array_map(fn($r) => $r->customer_id, $items)));
            if (!empty($customerIds)) {
                $customers = Customer::query()->whereIn('id', $customerIds)->get()->keyBy('id');
                foreach ($items as $review) {
                    $review->setRelation('customer', $customers->get($review->customer_id));
                }
            }
        }

        if (in_array('order', $relations)) {
            $orderIds = array_unique(array_filter(array_map(fn($r) => $r->order_id, $items)));
            if (!empty($orderIds)) {
                $orders = Order::query()->whereIn('id', $orderIds)->get()->keyBy('id');
                foreach ($items as $review) {
                    $review->setRelation('order', $orders->get($review->order_id));
                }
            }
        }
    }
}
