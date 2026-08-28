<?php

use App\Http\Controllers\NfeWebhookController;
use App\Http\Controllers\StoneWebhookController;
use App\Http\Middleware\VerifyNfeIoSignature;
use App\Http\Middleware\VerifyStoneWebhookAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/webhook/nfe-status', [NfeWebhookController::class, 'handle'])
    ->middleware(VerifyNfeIoSignature::class);

Route::post('/webhook/stone-connect', [StoneWebhookController::class, 'handle'])
    ->middleware(VerifyStoneWebhookAuth::class);
