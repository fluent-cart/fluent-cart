<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\AttrGroupResource;
use FluentCart\Api\Resource\AttrTermResource;
use FluentCart\App\Http\Requests\AttrGroupRequest;
use FluentCart\App\Http\Requests\AttrTermRequest;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class AttributesController extends Controller
{

    /**
     *
     * @param Request $request
     * @param $group_id
     * @return array
     */
    public function getGroup(Request $request, $group_id): array
    {
        
        return ['group' => AttrGroupResource::find($group_id, $request->all())];
    }


    public function getGroups(Request $request)
    {
        
        return ['groups' => AttrGroupResource::get($request->all())];
    }

    public function createGroup(AttrGroupRequest $request)
    {
        
        $data = $request->getSafe($request->sanitize());
        $arg = Arr::only($data, ['title', 'slug', 'settings', 'description']);
        $isCreated = AttrGroupResource::create($arg);

        if (is_wp_error($isCreated)) {
            return $isCreated;   
        }
        return $this->response->sendSuccess($isCreated);
    }

    /**
     * Update attribute group info
     *
     * @param AttrGroupRequest $request
     * @param $group_id
     * @return mixed
     */
    public function updateGroup(AttrGroupRequest $request, $group_id)
    {
        
        $data = $request->getSafe($request->sanitize());
        $arg = Arr::only($data, ['title', 'slug', 'settings', 'description']);
        $isUpdated  = AttrGroupResource::update($arg, $group_id);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;   
        }
        return $this->response->sendSuccess($isUpdated);

    }

    /**
     * Delete a group only if the terms of the group is unused
     *
     * @param Request $request
     * @param $group_id
     * @return mixed
     */
    public function deleteGroup(Request $request, $group_id)
    {
        
        $isDeleted = AttrGroupResource::delete($group_id);

        if (is_wp_error($isDeleted)) {
            return $isDeleted;   
        }
        return $this->response->sendSuccess($isDeleted);
    }

    public function getTerms(Request $request, $group_id): array
    {
        
        return ['terms' => AttrTermResource::get($request->all())];
    }

    public function createTerm(AttrTermRequest $request, $group_id)
    {
        
        $data = $request->getSafe($request->sanitize());
        $isCreated = AttrTermResource::create($data, ['group_id' => $group_id]);

        if (is_wp_error($isCreated)) {
            return $isCreated;   
        }
        return $this->response->sendSuccess($isCreated);
    }

    /**
     *
     * @param AttrTermRequest $request
     * @param $group_id
     * @param $term_id
     * @return mixed
     */
    public function updateTerm(AttrTermRequest $request, $group_id, $term_id)
    {
        
        $data = $request->getSafe($request->sanitize());
        $arg = Arr::only($data, ['title', 'slug', 'settings', 'serial', 'description']);
        $isUpdated = AttrTermResource::update($arg, $term_id, ['group_id' => $group_id]);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;   
        }
        return $this->response->sendSuccess($isUpdated);
    }

    /**
     *
     * @param Request $request
     * @param $group_id
     * @param $term_id
     * @return mixed
     */
    public function deleteTerm(Request $request, $group_id, $term_id)
    {
        
        $isDeleted = AttrTermResource::delete($term_id, ['group_id' => $group_id]);

        if (is_wp_error($isDeleted)) {
            return $isDeleted;   
        }
        return $this->response->sendSuccess($isDeleted);
    }

    /**
     * Update the serial of a term only
     *
     * @param Request $request
     * @param $group_id
     * @param $term_id
     * @return mixed
     */
    public function changeTermSerial(Request $request, $group_id, $term_id)
    {
        
        $isUpdated = AttrTermResource::updateSerial([
            'term_id' => $term_id,
            'group_id' => $group_id,
            'move' => $request->get('direction', 'up'),
        ]);  

        if (is_wp_error($isUpdated)) {
            return $isUpdated;   
        }
        return $this->response->sendSuccess($isUpdated);
    }
}


