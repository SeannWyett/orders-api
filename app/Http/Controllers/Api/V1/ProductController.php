<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Http\Resources\ProductResource;

class ProductController extends Controller
{
    public function index() {
        $products = Product::paginate(15);
        return ProductResource::collection($products);
    }
}
