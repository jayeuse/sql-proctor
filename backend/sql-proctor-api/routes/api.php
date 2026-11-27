<?php

use App\Http\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return ApiResponse::resource(new JsonResource($request->user()));
})->middleware('auth:sanctum');
