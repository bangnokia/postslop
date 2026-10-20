<?php

declare(strict_types=1);

namespace App\Actions;

use App\LocalToken;
use Crab\Http\Response;

final readonly class Home
{
    public function __construct(
        #[\SensitiveParameter]
        private LocalToken $token,
    ) {}

    public function handle(): Response
    {
        $path = dirname(__DIR__, 2) . '/public/index.html';
        if (!is_file($path)) {
            return Response::html(
                '<!doctype html><title>Postslop</title><p>Build the web client first: <code>cd clients/web && npm ci && npm run build</code></p>',
                503,
            );
        }
        $html = (string) file_get_contents($path);
        return Response::html(str_replace('__POSTSLOP_TOKEN__', $this->token->read(), $html))->withHeader(
            'Content-Security-Policy',
            "default-src 'self'; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'",
        );
    }
}
