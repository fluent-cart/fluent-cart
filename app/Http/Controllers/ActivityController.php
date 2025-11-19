<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\ActivityResource;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\App\Services\Filter\LogFilter;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        return $this->sendSuccess([
            'activities' => LogFilter::fromRequest($request)
                ->paginate()
        ]);
    }
    public function delete($id)
    {
        $response = ActivityResource::delete($id);
       if (is_wp_error($response)) {
           return $this->sendError([
               'message'=> $response->get_error_message()
           ]);
       }
        return $this->sendSuccess([
            'message' => __('Activity Deleted Successfully', 'fluent-cart')
        ]);
    }

    public function markReadUnread($id, Request $request): \WP_REST_Response
    {
        $status = $request->getSafe('status', 'sanitize_text_field');

        $response = ActivityResource::update(['read_status' => $status], $id);
        if (is_wp_error($response)) {
            return $this->sendError([
                'message'=> $response->get_error_message()
            ]);
        }
        if ($status === 'read'){
            return $this->sendSuccess([
                'message' => __('Activity Marked as Read', 'fluent-cart')
            ]);
        }else{
            return $this->sendSuccess([
                'message' => __('Activity Marked as Unread', 'fluent-cart')
            ]);
        }

    }

}
