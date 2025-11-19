<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\Models\Label;
use FluentCart\App\Models\LabelRelationship;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

class LabelResource extends BaseResourceApi
{
    public static function getQuery(): Builder
    {
        return Label::query();
    }

    /**
     * Retrieve labels with additional data based on specified parameters.
     *
     * @param array $params Optional. Additional parameters for label retrieval.
     *       $params = [
     *
     *       ]
     *
     */
    public static function get(array $params = [])
    {
        $getLabels = static::getQuery()->get();

        return [
            'labels' => $getLabels
        ];
    }

    /**
     * Find an label by id
     *
     * @param string $id Required. The id of the label to find.
     * @param array $params Optional. Additional parameters for label retrieval.
     *        [
     *              // Include optional parameters, if any.
     *        ]
     *
     */
    public static function find($id, $params = [])
    {
        //
    }

    /**
     * Create a label with the provided data.
     *
     * @param array $data Required. Array containing the necessary parameters for label creation.
     *        $data = [
     *            'value'   => (string)    Required. The name of the label,
     *            // Include additional parameters, if any.
     *        ]
     * @param array $params Optional. Additional parameters for label creation.
     *        [
     *            // Include optional parameters, if any.
     *        ]
     *
     */
    public static function create($data, $params = [])
    {
        $isCreated = static::getQuery()->create($data);

        if ($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('Label created successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Label creation failed.', 'fluent-cart')]
        ]);
    }

    public static function createAndAttach($data, $params = [])
    {
        $isCreated = static::getQuery()->create(
            Arr::only($data, 'value')
        );


        if ($isCreated) {

            if (!empty($data['bind_to_type']) && !empty($data['bind_to_id'])) {
                if (class_exists('FluentCart\App\Models\\' . $data['bind_to_type'])) {
                    LabelRelationship::query()->create([
                        'labelable_id'   => $data['bind_to_id'],
                        'labelable_type' => 'FluentCart\App\Models\\' . $data['bind_to_type'],
                        'label_id'       => $isCreated->id
                    ]);
                }
            }

            return static::makeSuccessResponse(
                $isCreated,
                __('Label created successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Label creation failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Update a label with the provided data.
     *
     * @param object $data Required.
     * @param int $id Required. The ID of the label to update.
     * @param array $params Optional. Additional parameters for label update.
     *        [
     *            // Include optional parameters, if any.
     *        ]
     *
     */
    public static function update($data, $id, $params = [])
    {
        //
    }

    /**
     * Delete a label and associated data by id.
     *
     * @param int $id Required. The id of the label to delete.
     * @param array $params Optional. Additional parameters for label deletion.
     *        [
     *              // Include optional parameters, if any.
     *        ]
     *
     */
    public static function delete($id, $params = [])
    {
        //
    }

    /**
     * Add label to the label relationships for a specific labelable type with the provided data.
     *
     * @param object $model Required.
     * @param array $params Optional. Additional parameters to add label.
     *        [
     *            // Include optional parameters, if any.
     *        ]
     *
     */
    public static function addLabelToLabelRelationships($model, $params = [])
    {
        $labelableId = Arr::get($params, 'labelable_id', null);
        $labelableType = Arr::get($params, 'labelable_type');
        $newLabelIds = Arr::get($params, 'new_label_ids', []);
        $existingLabelIds = Arr::get($params, 'existing_label_ids', []);

        if (!empty($existingLabelIds)) {
            if (!empty($newLabelIds)) {
                $newLabelIds = Collection::make($newLabelIds);
                $deletedLabelIds = $existingLabelIds->diff($newLabelIds);
            } else {
                $deletedLabelIds = $existingLabelIds;
            }
            foreach ($deletedLabelIds as $labelId) {
                LabelRelationship::where('label_id', $labelId)
                    ->where('labelable_id', $labelableId)
                    ->where('labelable_type', $labelableType)
                    ->delete();
            }
        }
        if (!empty($newLabelIds)) {
            foreach ($newLabelIds as $labelId) {
                // Check if the label relationship already exists
                $isExist = LabelRelationship::where('label_id', $labelId)
                    ->where('labelable_id', $labelableId)
                    ->where('labelable_type', $labelableType)
                    ->exists();

                if (!$isExist) {
                    // Create the new label relationship
                    $data['label_id'] = $labelId;
                    $label = new LabelRelationship($data);
                    $model->labels()->save($label);
                }
            }
        }
        return true;
    }
}