<?php

declare(strict_types=1);

use App\Actions\Home;
use App\Actions\McpApi;
use App\Actions\PostsApi;
use App\Actions\WebAsset;
use Crab\Route;

// The web client, built by clients/web into public/.
Route::get('/', Home::class);
Route::get('/assets/{file}', WebAsset::class);

Route::post('/api/v1/mcp', McpApi::class);
Route::get('/api/v1/mcp', McpApi::class);
Route::delete('/api/v1/mcp', McpApi::class);

Route::get('/api/v1/accounts', [PostsApi::class, 'accounts']);
Route::post('/api/v1/accounts/{platform}/check', [PostsApi::class, 'checkAccount']);
Route::put('/api/v1/accounts/{platform}', [PostsApi::class, 'importAccount']);
Route::patch('/api/v1/accounts/{platform}', [PostsApi::class, 'changeAccount']);
Route::delete('/api/v1/accounts/{platform}', [PostsApi::class, 'removeAccount']);
Route::get('/api/v1/posts', [PostsApi::class, 'index']);
Route::post('/api/v1/posts', [PostsApi::class, 'store']);
Route::get('/api/v1/posts/{id}', [PostsApi::class, 'show']);
Route::put('/api/v1/posts/{id}', [PostsApi::class, 'update']);
Route::delete('/api/v1/posts/{id}', [PostsApi::class, 'destroy']);
Route::post('/api/v1/posts/{id}/publish', [PostsApi::class, 'publish']);
Route::post('/api/v1/posts/{id}/retry', [PostsApi::class, 'retry']);
Route::post('/api/v1/posts/{id}/schedule', [PostsApi::class, 'schedule']);
Route::get('/api/v1/media/{id}', [PostsApi::class, 'media']);
