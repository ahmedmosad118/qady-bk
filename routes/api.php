<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactAddressController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| The Vue SPA authenticates with Sanctum personal access tokens, so every
| protected route is stateless and expects a Bearer token.
|
*/

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Config-driven CRUD endpoint (App\Core\CRUD\CRUDController).
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{ids}', [UserController::class, 'destroy']);

    // Books (products) and their categories.
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    Route::get('/product-categories', [ProductCategoryController::class, 'index']);
    Route::post('/product-categories', [ProductCategoryController::class, 'store']);
    Route::put('/product-categories/{category}', [ProductCategoryController::class, 'update']);
    Route::delete('/product-categories/{category}', [ProductCategoryController::class, 'destroy']);

    // Contacts (Customers & Suppliers) and Contact Addresses.
    Route::get('/contacts', [ContactController::class, 'index']);
    Route::post('/contacts', [ContactController::class, 'store']);
    Route::get('/contacts/{contact}', [ContactController::class, 'show']);
    Route::put('/contacts/{contact}', [ContactController::class, 'update']);
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy']);

    Route::get('/contacts/{contact}/addresses', [ContactAddressController::class, 'index']);
    Route::post('/contacts/{contact}/addresses', [ContactAddressController::class, 'store']);
    Route::put('/contacts/{contact}/addresses/{address}', [ContactAddressController::class, 'update']);
    Route::delete('/contacts/{contact}/addresses/{address}', [ContactAddressController::class, 'destroy']);
    Route::patch('/contacts/{contact}/addresses/{address}/default', [ContactAddressController::class, 'setDefault']);
});
