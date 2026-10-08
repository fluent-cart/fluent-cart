<?php

namespace FluentCart\App\Models;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Concerns\CanSearch;

/**
 * ProductReview Model - Backed by the fct_product_reviews custom table.
 *
 * Reviews and admin replies live in one table: a review row has
 * parent_id = NULL; a reply has parent_id = the parent review id.
 *
 * Media files stay in the WP Media Library (wp_posts); their references
 * live in other_info['media'] with the denormalized media_count column
 * maintained on every attach/detach (same update, never separately).
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $type
 * @property int $post_id
 * @property int|null $item_id
 * @property int|null $order_id
 * @property int|null $customer_id
 * @property int|null $user_id
 * @property string $reviewer_name
 * @property string $reviewer_email
 * @property string|null $title
 * @property string $review
 * @property int|null $rating
 * @property string $status
 * @property int $is_verified
 * @property int $is_admin_reply
 * @property int $media_count
 * @property string|null $ip_address
 * @property array|null $other_info
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property-read string $content
 * @property-read array|null $meta
 * @property-read array $media
 *
 * @package FluentCart\App\Models
 *
 * @version 1.1.0
 */
class ProductReview extends Model
{
    use CanSearch;

    protected $table = 'fct_product_reviews';

    // Statuses live in Status::REVIEW_* (single source of truth).

    /**
     * Trust fields (user_id, customer_id, order_id, status, is_verified) and
     * the system-maintained media_count are NOT mass-assignable — the write
     * path (ProductReviewResource) sets them by explicit property assignment
     * after deriving them server-side. Everything else is request-shaped data
     * that the controllers whitelist and sanitize.
     */
    protected $fillable = [
        'parent_id',
        'post_id',
        // The variation the review is about, or NULL for the product as a
        // whole. Request-shaped, but the submit path validates it against
        // the product and the order grant before it reaches create().
        'item_id',
        'reviewer_name',
        'reviewer_email',
        'title',
        'review',
        'rating',
        'ip_address',
        'other_info',
    ];

    protected $guarded = ['id', 'user_id', 'customer_id', 'order_id', 'status', 'is_verified', 'is_admin_reply', 'media_count'];

    protected $casts = [
        'parent_id'   => 'integer',
        'post_id'     => 'integer',
        'item_id'     => 'integer',
        'order_id'    => 'integer',
        'customer_id' => 'integer',
        'user_id'     => 'integer',
        'rating'         => 'integer',
        'is_verified'    => 'integer',
        'is_admin_reply' => 'integer',
        'media_count'    => 'integer',
        'other_info'  => 'array',
    ];

    protected $hidden = ['review', 'ip_address', 'other_info'];

    protected $appends = ['content', 'photo'];

    /**
     * The row's photo, appended under the same name Customer appends and
     * resolved by the same service, on the review's own columns: the
     * review's user when one is set, otherwise the reviewer email.
     */
    public function getPhotoAttribute(): string
    {
        return Helper::getUserAvatarUrl($this->user_id, $this->reviewer_email);
    }

    /**
     * Public attribute `content` maps to the `review` column so the API
     * response shape stays identical to the wp_comments-backed model.
     */
    public function getContentAttribute()
    {
        return $this->attributes['review'] ?? '';
    }

    public function setContentAttribute($value)
    {
        $this->attributes['review'] = $value;
    }

    /**
     * Extra review data (kept under other_info, media refs excluded).
     */
    public function getMetaAttribute()
    {
        $info = $this->other_info;
        if (!is_array($info)) {
            return null;
        }
        unset($info['media']);
        return $info ?: null;
    }

    /**
     * Media references: [{id, type, order}, ...] — files are wp_posts attachments.
     */
    public function getMediaAttribute()
    {
        $info = $this->other_info;
        return (is_array($info) && !empty($info['media']) && is_array($info['media'])) ? $info['media'] : [];
    }


    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOfStatus($query, $status)
    {
        if (!$status || $status === 'all') {
            return $query;
        }
        return $query->where('status', $status);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', Status::REVIEW_APPROVED);
    }

    public function scopeOfProduct($query, $postId)
    {
        return $postId ? $query->where('post_id', (int) $postId) : $query;
    }

    /**
     * Reviews with exactly this star rating, or any of a list of them (the
     * storefront's star chips can be combined).
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @param int|int[]|null $rating
     */
    public function scopeOfRating($query, $rating)
    {
        if (is_array($rating)) {
            $ratings = array_values(array_filter(array_map('intval', array_slice($rating, 0, 5)), function ($value) {
                return $value >= 1 && $value <= 5;
            }));

            return $ratings ? $query->whereIn('rating', $ratings) : $query;
        }

        if (!$rating || !is_numeric($rating)) {
            return $query;
        }
        return $query->where('rating', (int) $rating);
    }

    /**
     * Reviews at or above a rating.
     *
     * The floor a list is willing to show, which is a different question from
     * ofRating()'s "this star and no other" — that one answers the star chips,
     * where picking 4 means four. A list set to a minimum of 4 shows fours and
     * fives both.
     *
     * Anything outside 1-5 is not a floor, so it is ignored rather than
     * narrowed to nothing: a stored 0 means "no minimum", and a stray 9 would
     * otherwise empty the list with no way to see why.
     */
    public function scopeMinRating($query, $rating)
    {
        $rating = (int) $rating;

        if ($rating < 1 || $rating > 5) {
            return $query;
        }

        return $query->where('rating', '>=', $rating);
    }

    public function scopeWithMedia($query)
    {
        return $query->where('media_count', '>', 0);
    }

    /**
     * Reviews that have at least one reply (admin or customer follow-up).
     */
    public function scopeHasReplies($query)
    {
        return $query->has('replies');
    }

    /**
     * Reviews nobody has replied to yet.
     */
    public function scopeWithoutReplies($query)
    {
        return $query->doesntHave('replies');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'post_id', 'ID');
    }

    public function replies()
    {
        return $this->hasMany(static::class, 'parent_id', 'id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * Helpful/not-helpful votes — the related model ships in fluent-cart-pro
     * (same free→pro pattern as Order::licenses()), so only Pro code paths
     * (helpful sort, vote counts) invoke this relation. The interactions
     * table also holds review reports, so the relation is scoped to the
     * vote type.
     */
    public function votes()
    {
        return $this->hasMany(\FluentCartPro\App\Modules\Reviews\Models\ReviewInteraction::class, 'review_id', 'id')
            ->where('type', 'vote');
    }

}
