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
        $html = file_get_contents(dirname(__DIR__, 2) . '/public/index.html');
        return Response::html(str_replace('__POSTSLOP_TOKEN__', $this->token->read(), $html))->withHeader(
            'Content-Security-Policy',
            "default-src 'self'; img-src 'self' blob: data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'",
        );
    }
}
