<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Helpers\CouponHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Api\Resource\CouponResource;

/**
 * Coupon tools (read).
 *
 * Parameter design:
 *  - list-coupons filters on status, free-text (code/title), and the common
 *    "which coupons are usable right now?" question via active_now, which
 *    accounts for status + the start/end window in one flag.
 *  - amount is returned raw alongside type, because a percentage coupon's amount
 *    is a percent (10 = 10%) while a fixed coupon's is a currency value — the
 *    agent must read `type` to interpret `amount`. We don't force a money shape
 *    onto a value that may not be money.
 *
 * manage-coupon (create/update/deactivate) is a write tool and ships in the
 * write stage.
 */
class CouponTools
{
    public static function definitions()
    {
        return [
            'fluent-cart/list-coupons' => [
                'label'       => 'List Coupons',
                'description' => 'Find and filter coupons with usage counts (times_used) and validity windows. Filter by status, by code substring, or by type; paginate with page/per_page. Interpret amount via type: percentage means a percent (10 = 10 percent), fixed means a currency value. Use active_now to get only coupons usable today (status active, or scheduled with a start date that has passed, and within the start/end window — i.e. not expired). valid_now on each row answers the same question. status disabled means turned off.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'     => ['type' => 'string', 'description' => 'Matches coupon code or title.'],
                        'code'       => ['type' => 'string', 'description' => 'Matches the coupon code only (substring). Narrower than search.'],
                        'status'     => ['type' => 'string', 'enum' => ['active', 'scheduled', 'expired', 'disabled', 'inactive'], 'description' => 'Stored status. scheduled = start date was in the future when last saved; expired = end date passed; disabled = turned off (inactive is the old name of disabled and lists the same coupons). The stored value can lag the dates, so read valid_now for "usable right now".'],
                        'type'       => ['type' => 'string', 'enum' => ['fixed', 'percentage']],
                        'active_now' => ['type' => 'boolean', 'description' => 'Only coupons checkout accepts right now: active, or scheduled with a passed start date, and within the start/end window.'],
                        'sort_by'    => ['type' => 'string', 'enum' => ['id', 'use_count', 'priority', 'end_date'], 'default' => 'id'],
                        'sort_type'  => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
                        'page'       => ['type' => 'integer', 'default' => 1],
                        'per_page'   => ['type' => 'integer', 'default' => 25, 'description' => 'Max 100.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'listCoupons'],
                'permission_callback' => function () {
                    return PermissionGate::can('coupons/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/manage-coupon' => [
                'label'       => 'Manage Coupon',
                'description' => 'Create, update, or deactivate a coupon. action=create needs code, type, and amount. action=update and action=deactivate need coupon_id. Deactivate sets status to disabled rather than deleting. Interpret amount by type: percentage is a percent, fixed is a currency value.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'action'     => ['type' => 'string', 'enum' => ['create', 'update', 'deactivate']],
                        'coupon_id'  => ['type' => 'integer', 'description' => 'Required for update and deactivate.'],
                        'code'       => ['type' => 'string'],
                        'title'      => ['type' => 'string'],
                        'type'       => ['type' => 'string', 'enum' => ['fixed', 'percentage']],
                        'amount'     => ['type' => 'number', 'description' => 'A percent for percentage, major units for fixed.'],
                        'status'     => ['type' => 'string', 'enum' => ['active', 'disabled', 'inactive'], 'description' => 'inactive is the old name of disabled and is stored as disabled.'],
                        'stackable'  => ['type' => 'string', 'enum' => ['yes', 'no']],
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC.'],
                        'end_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD or ISO 8601, UTC.'],
                    ],
                    'required' => ['action'],
                ],
                'execute_callback'    => [self::class, 'manageCoupon'],
                'permission_callback' => function () {
                    return PermissionGate::can('coupons/manage');
                },
                // Mutating; deactivate sets status disabled rather than deleting,
                // so not destructive. create is not idempotent (a repeat makes a
                // second coupon), so no idempotent hint on the dispatcher.
                'annotations' => ['readonly' => false, 'destructive' => false],
            ],
        ];
    }

    public static function manageCoupon($params = [])
    {
        $action = isset($params['action']) ? sanitize_text_field($params['action']) : '';
        if (!in_array($action, ['create', 'update', 'deactivate'], true)) {
            return MCPHelper::error('invalid_param', 'action must be one of: create, update, deactivate.');
        }

        $fields = ['code', 'title', 'type', 'amount', 'status', 'stackable', 'start_date', 'end_date'];
        $data   = [];
        foreach ($fields as $f) {
            if (isset($params[$f])) {
                $data[$f] = is_string($params[$f]) ? sanitize_text_field($params[$f]) : $params[$f];
            }
        }

        // "next monday" would be stored as a zero date: a coupon meant for later, live at once.
        $badDate = MCPHelper::unreadableDateError($data, ['start_date', 'end_date']);
        if ($badDate) {
            return $badDate;
        }
        foreach (['start_date', 'end_date'] as $f) {
            if (!empty($data[$f])) {
                $data[$f] = gmdate('Y-m-d H:i:s', strtotime($data[$f]));
            }
        }
        // Compared as stored, in UTC: the list filters' store-day reading would differ
        if (!empty($data['start_date']) && !empty($data['end_date']) && $data['start_date'] > $data['end_date']) {
            return MCPHelper::error(
                'invalid_param',
                'start_date is later than end_date. Swap the two dates and retry.',
                ['fields' => ['start_date', 'end_date']]
            );
        }

        // The admin turns a coupon off with "disabled" and does not know "inactive":
        // its coupon list would turn an inactive coupon with a past start date back on.
        if (isset($data['status']) && $data['status'] === 'inactive') {
            $data['status'] = 'disabled';
        }

        // Enforce the advertised enums server-side (create + update). Sanitize
        // alone would persist an out-of-enum value like status:'banana', which
        // breaks admin list filters and active_now logic. Reject, don't drop.
        $enums = [
            'type'      => ['fixed', 'percentage'],
            'status'    => ['active', 'disabled'],
            'stackable' => ['yes', 'no'],
        ];
        foreach ($enums as $field => $allowed) {
            if (isset($data[$field]) && !in_array($data[$field], $allowed, true)) {
                return MCPHelper::error(
                    'invalid_param',
                    sprintf(
                        'Invalid value for %1$s. Allowed: %2$s.',
                        $field,
                        implode(', ', $allowed)
                    ),
                    ['fields' => [$field], 'allowed' => $allowed]
                );
            }
        }

        if ($action === 'create') {
            $missing = [];
            foreach (['code', 'type', 'amount'] as $req) {
                if (!isset($data[$req])) {
                    $missing[] = $req;
                }
            }
            if ($missing) {
                return MCPHelper::error('invalid_param', 'create requires code, type, and amount.', ['fields' => $missing]);
            }

            $amountCheck = self::validateAmount($data['type'], $data['amount']);
            if (is_wp_error($amountCheck)) {
                return $amountCheck;
            }

            if (Coupon::query()->where('code', $data['code'])->exists()) {
                return MCPHelper::error(
                    'duplicate_code',
                    sprintf(
                        'A coupon with code "%1$s" already exists.',
                        $data['code']
                    ),
                    ['fields' => ['code']]
                );
            }

            // Never persist a null status — default a new coupon to active.
            if (!isset($data['status'])) {
                $data['status'] = 'active';
            }

            $created = CouponResource::create($data);
            if (is_wp_error($created)) {
                return $created;
            }
            $coupon = Coupon::query()->where('code', $data['code'])->first();
            // A future start date makes it scheduled, as the admin does.
            CouponHelper::updateCouponStatus($coupon);

            return self::couponResult($coupon, 'Coupon created.');
        }

        if (empty($params['coupon_id'])) {
            return MCPHelper::error('invalid_param', 'coupon_id is required for update and deactivate.', ['fields' => ['coupon_id'], 'hint' => 'Use list-coupons to find a coupon_id.']);
        }
        $coupon = Coupon::query()->find((int) $params['coupon_id']);
        if (!$coupon) {
            return MCPHelper::error('coupon_not_found', 'No coupon found for the given coupon_id.');
        }

        if ($action === 'deactivate') {
            $coupon->update(['status' => 'disabled']);
            return self::couponResult($coupon, 'Coupon deactivated.');
        }

        if (!$data) {
            return MCPHelper::error('invalid_param', 'Provide at least one field to update.');
        }

        // Write only the supplied fields directly on the model. We intentionally
        // bypass CouponResource::update here: its formatAmount() step assumes a
        // full coupon payload and corrupts partial updates — a percentage amount
        // gets converted to cents (9 -> 900), and an omitted amount/conditions is
        // reset to zero. amount is interpreted against the effective type:
        // percentage stays a raw percent; fixed converts to cents like the admin.
        $type   = isset($data['type']) ? $data['type'] : $coupon->type;
        $update = [];
        // A coupon turned off under the old name stays off: updateCouponStatus() below
        // knows "disabled" only and would turn an "inactive" one back on.
        if ($coupon->status === 'inactive' && !isset($data['status'])) {
            $update['status'] = 'disabled';
        }
        foreach (['code', 'title', 'type', 'status', 'stackable', 'start_date', 'end_date'] as $f) {
            if (isset($data[$f])) {
                $update[$f] = $data[$f];
            }
        }
        if (isset($data['amount'])) {
            $amountCheck = self::validateAmount($type, $data['amount']);
            if (is_wp_error($amountCheck)) {
                return $amountCheck;
            }
            $update['amount'] = ($type === 'percentage') ? (0 + $data['amount']) : Helper::toCent($data['amount']);
        }

        $coupon->update($update);
        CouponHelper::updateCouponStatus($coupon);

        return self::couponResult($coupon, 'Coupon updated.');
    }

    private static function couponResult($result, $summary)
    {
        if (is_wp_error($result)) {
            return $result;
        }
        $coupon = (is_array($result) && isset($result['data'])) ? $result['data'] : $result;
        $out    = ['coupon_id' => (is_object($coupon) && isset($coupon->id)) ? (int) $coupon->id : null];
        if (is_object($coupon)) {
            // Echo the full resulting record so the caller needn't re-read.
            $out['code']   = isset($coupon->code) ? $coupon->code : null;
            $out['title']  = isset($coupon->title) ? $coupon->title : null;
            $out['type']   = isset($coupon->type) ? $coupon->type : null;
            $out['amount'] = self::couponAmount($coupon);
            $out['status'] = isset($coupon->status) ? $coupon->status : null;
            $out['start_date'] = MCPHelper::toIso8601($coupon->start_date);
            $out['end_date']   = MCPHelper::toIso8601($coupon->end_date);
        }
        return MCPHelper::envelope($summary, $out);
    }

    /**
     * Coupon amount in the units the tool contract promises: a percentage value
     * for percentage coupons (stored as-is), a store-currency value for fixed
     * coupons (stored in cents, so divided back). Always numeric.
     */
    private static function couponAmount($coupon)
    {
        if ($coupon->type === 'fixed') {
            return 0 + Helper::toDecimalWithoutComma((int) $coupon->amount);
        }
        return is_numeric($coupon->amount) ? 0 + $coupon->amount : $coupon->amount;
    }

    /** Validate a coupon amount against its type. Returns true or a WP_Error. */
    private static function validateAmount($type, $amount)
    {
        if (!is_numeric($amount)) {
            return MCPHelper::error('invalid_param', 'amount must be a number.', ['fields' => ['amount']]);
        }
        $amount = 0 + $amount;
        if ($type === 'percentage') {
            if ($amount <= 0 || $amount > 100) {
                return MCPHelper::error('invalid_param', 'A percentage coupon amount must be greater than 0 and at most 100.', ['fields' => ['amount']]);
            }
        } elseif ($amount < 0) {
            return MCPHelper::error('invalid_param', 'A fixed coupon amount cannot be negative.', ['fields' => ['amount']]);
        }
        return true;
    }

    public static function listCoupons($params = [])
    {
        $paging = MCPHelper::pagination($params, 25);
        $query  = Coupon::query();

        if (!empty($params['search'])) {
            $like = '%' . sanitize_text_field($params['search']) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('code', 'LIKE', $like)->orWhere('title', 'LIKE', $like);
            });
        }
        if (!empty($params['code'])) {
            $query->where('code', 'LIKE', '%' . sanitize_text_field($params['code']) . '%');
        }
        if (!empty($params['status'])) {
            $status = sanitize_text_field($params['status']);
            // Coupons that manage-coupon turned off before it wrote "disabled" are stored as "inactive".
            $query->whereIn('status', in_array($status, ['disabled', 'inactive'], true) ? ['disabled', 'inactive'] : [$status]);
        }
        if (!empty($params['type'])) {
            $query->where('type', sanitize_text_field($params['type']));
        }

        $now = DateTime::gmtNow()->format('Y-m-d H:i:s');
        if (!empty($params['active_now'])) {
            $query->where(function ($q) use ($now) {
                    $q->where('status', 'active')->orWhere(function ($q) use ($now) {
                        $q->where('status', 'scheduled')->where('start_date', '>', '0000-00-00 00:00:00')->where('start_date', '<=', $now);
                    });
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('start_date')->orWhere('start_date', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    // A zero date is no end date, as validNow() and checkout read it
                    $q->whereNull('end_date')->orWhere('end_date', '0000-00-00 00:00:00')->orWhere('end_date', '>=', $now);
                });
        }

        $sortBy   = self::allowed($params, 'sort_by', ['id', 'use_count', 'priority', 'end_date'], 'id');
        $sortType = strtoupper(isset($params['sort_type']) ? $params['sort_type'] : 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $query->orderBy($sortBy, $sortType);
        if ($sortBy !== 'id') {
            $query->orderBy('id', 'DESC');
        }

        $paginator = $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']);
        $total     = self::total($paginator);

        $rows = [];
        foreach (MCPHelper::paginatorItems($paginator) as $coupon) {
            $rows[] = self::formatRow($coupon, $now);
        }

        return MCPHelper::envelope(
            sprintf(
                $total === 1 ? '%d coupon found.' : '%d coupons found.',
                $total
            ),
            ['coupons' => $rows],
            MCPHelper::pagingMeta($paginator)
        );
    }

    private static function formatRow($coupon, $now)
    {
        return [
            'coupon_id'  => (int) $coupon->id,
            'code'       => $coupon->code,
            'title'      => $coupon->title,
            'type'       => $coupon->type,
            'amount'     => self::couponAmount($coupon),
            'status'     => $coupon->status,
            'use_count'  => (int) $coupon->use_count,
            // times_used is the doc-facing alias of use_count — same value, kept so
            // agents can read either name.
            'times_used' => (int) $coupon->use_count,
            'stackable'  => $coupon->stackable,
            'start_date' => MCPHelper::toIso8601($coupon->start_date),
            'end_date'   => MCPHelper::toIso8601($coupon->end_date),
            'valid_now'  => self::validNow($coupon, $now),
        ];
    }

    private static function validNow($coupon, $now)
    {
        // Checkout accepts a scheduled coupon once its start date has passed.
        $started = $coupon->status === 'scheduled' && $coupon->start_date && $coupon->start_date !== '0000-00-00 00:00:00';
        if ($coupon->status !== 'active' && !$started) {
            return false;
        }
        if ($coupon->start_date && $coupon->start_date > $now) {
            return false;
        }
        if ($coupon->end_date && $coupon->end_date !== '0000-00-00 00:00:00' && $coupon->end_date < $now) {
            return false;
        }
        return true;
    }

    private static function allowed($params, $key, array $allowed, $default)
    {
        $val = isset($params[$key]) ? $params[$key] : $default;
        return in_array($val, $allowed, true) ? $val : $default;
    }

    private static function total($paginator)
    {
        return MCPHelper::paginatorTotal($paginator);
    }
}
