<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::get('products', [ProductController::class, 'index']);

    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::post('orders', [OrderController::class, 'store'])
        ->middleware('throttle:10,1', 'idempotent');

    Route::middleware('auth:sanctum')->group(function(){
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
    });
});