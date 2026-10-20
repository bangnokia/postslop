<?php

declare(strict_types=1);

use App\Actions\Api;
use App\Actions\Home;
use Crab\Http\Response;
use Crab\Route;

Route::get('/', Home::class);
Route::get('/api/status', [Api::class, 'status']);
Route::post('/api/posts', [Api::class, 'publish']);
Route::get('/api/posts/{requestId}', [Api::class, 'show']);

Route::get(
    '/app.css',
    static fn(): Response => new Response(headers: [
        'Content-Type' => 'text/css; charset=utf-8',
    ], body: file_get_contents(dirname(__DIR__) . '/public/app.css')),
);

Route::get(
    '/app.js',
    static fn(): Response => new Response(headers: [
        'Content-Type' => 'text/javascript; charset=utf-8',
    ], body: file_get_contents(dirname(__DIR__) . '/public/app.js')),
);
