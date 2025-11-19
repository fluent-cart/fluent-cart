<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\LabelResource;
use FluentCart\App\Http\Requests\LabelRequest;
use FluentCart\App\Models\Label;
use FluentCart\App\Models\Model;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Product;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Support\Str;

class LabelController extends Controller
{
    public function index(Request $request)
    {
        return LabelResource::get();
    }

    public function create(LabelRequest $request)
    {
        $data = $request->getSafe($request->sanitize());

        // $data = [
        //     'value' => sanitize_text_field($request->get('label')),
        // ];

        $isCreated = LabelResource::createAndAttach($data);


//        LabelResource::addLabelToLabelRelationships($order, [
//            'labelable_id'       => $orderId,
//            'labelable_type'     => Order::class,
//            'new_label_ids'      => $newLabelIds,
//            'existing_label_ids' => $existingLabelIds
//        ]);

        if (is_wp_error($isCreated)) {
            return $isCreated;
        }
        return $this->response->sendSuccess($isCreated);
    }

    public function updateSelections(Request $request)
    {
        $data = $request->getSafe(
            [
                'bind_to_type'     => 'sanitize_text_field',
                'bind_to_id'       => 'sanitize_text_field',
                'selectedLabels.*' => 'sanitize_text_field'
            ]
        );


        if (!empty($data['bind_to_type']) && !empty($data['bind_to_id'])) {

            if (!Str::contains($data['bind_to_type'], '\\')) {
                $modelClass = 'FluentCart\App\Models\\' . $data['bind_to_type'];
            } else {
                $modelClass = $data['bind_to_type'];
            }

            //Check If the model class exist
            if (class_exists($modelClass)) {
                /**
                 * @var $modelInstance Model
                 */
                $modelInstance = new $modelClass();
                $newLabelIds = Arr::get($data, 'selectedLabels', []);


                $model = $modelInstance->newQuery()->with('labels')->find($data['bind_to_id']);
                // Pluck and convert $existingLabelIds to a collection of strings
                $existingLabelIds = Collection::make($model['labels'])->pluck('label_id')->map(function ($value) {
                    return (string)$value;
                });

                $modelId = $model->{$modelInstance->getKeyName()};
                $isAttached = LabelResource::addLabelToLabelRelationships($model, [
                    'labelable_id'       => $modelId,
                    'labelable_type'     => $modelClass,
                    'new_label_ids'      => $newLabelIds,
                    'existing_label_ids' => $existingLabelIds
                ]);
                if ($isAttached) {
                    return $this->sendSuccess([
                        'message' => __('Labels Updated Successfully', 'fluent-cart')
                    ]);
                }

                return $this->sendError([
                    'message' => __('Failed To Update Labels', 'fluent-cart')
                ]);

            }
        }
    }
}
