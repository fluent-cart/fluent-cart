<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Http\Request\Request;

class VariantController extends Controller
{
    public function index(Request $request): array
    {
        $parameters = $request->get('params');
        return ProductVariation::search([])->get()->toArray();
    }
}
