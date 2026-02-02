<?php

namespace FluentCartPro\App\Modules\Promotional\Http\Controllers;

use FluentCart\App\Services\Filter\OrderBumpFilter;
use FluentCart\Framework\Http\Controller;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Http\Requests\OrderBumpRequest;
use FluentCartPro\App\Modules\Promotional\Models\OrderPromotion;

class OrderBumpController extends Controller
{

    public function index(Request $request)
    {

        $orderBy = $request->get('sort_by', 'id');
        $order = $request->get('sort_type', 'desc');

        $orderBumps = OrderPromotion::query()->where('type', 'order_bump')
            ->orderBy($orderBy, $order)
            ->with(['product_variant.product'])
            ->when($activeView = $request->get('active_view'), function ($query) use ($activeView) {
                if ($activeView === 'active') {
                    $query->where('status', 'active');
                } elseif ($activeView === 'draft') {
                    $query->where('status', 'draft');
                }
            })
            ->when($search = $request->get('search'), function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            })
            ->paginate();

        return [
            'order_bumps' => $orderBumps
        ];
    }

    public function store(Request $request)
    {
        $title = sanitize_text_field(Arr::get($request->all(), 'title', ''));
        $srcObjectId = intval(Arr::get($request->all(), 'src_object_id', 0));

        if (empty($title) || empty($srcObjectId)) {
            return $this->sendError([
                'message' => __('Title and source object id are required', 'fluent-cart-pro')
            ], 400);
        }

        $data = [
            'title'         => $title,
            'type'          => 'order_bump',
            'src_object_id' => $srcObjectId
        ];

        $isCreated = OrderPromotion::create($data);

        if (!$isCreated) {
            return $this->sendError([
                'message' => __('Failed to create order bump', 'fluent-cart-pro')
            ], 400);
        }

        return [
            'message' => __('Order bump created successfully', 'fluent-cart-pro'),
            'id'      => $isCreated->id
        ];
    }

    public function show(Request $request, $id)
    {
        $orderBump = OrderPromotion::query()
            ->where('id', $id)
            ->where('type', 'order_bump')
            ->first();

        if (!$orderBump) {
            return $this->sendError([
                'message' => __('Order bump not found', 'fluent-cart-pro')
            ], 404);
        }

        // set default config
        if (empty($orderBump->config)) {
            $orderBump->config = [
                'discount'              => [
                    'discount_type'   => 'percentage',
                    'discount_amount' => 0,
                ],
                'display_conditions_if' => '',
                'call_to_action'        => ''
            ];
        }

        $variant = $orderBump->product_variant;

        if ($variant) {
            $variant->load('product');
        }


        return [
            'order_bump' => $orderBump,
            'variant'    => $variant
        ];
    }

    public function update(OrderBumpRequest $request, $id)
    {
        $data = $request->getSafe($request->sanitize());

        $data['type'] = 'order_bump';

        $isUpdated = OrderPromotion::query()->where('id', $id)->update($data);

        if (!$isUpdated) {
            return $this->sendError([
                'message' => __('Failed to update order bump', 'fluent-cart-pro')
            ], 400);
        }

        return [
            'message' => __('Order bump updated successfully', 'fluent-cart-pro')
        ];
    }

    public function delete(Request $request, $id)
    {
        $isDeleted = OrderPromotion::query()->where('id', $id)->delete();

        if (!$isDeleted) {
            return $this->sendError([
                'message' => __('Failed to delete order bump', 'fluent-cart-pro')
            ], 400);
        }

        return [
            'message' => __('Order bump deleted successfully', 'fluent-cart-pro')
        ];
    }

}
