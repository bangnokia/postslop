<?php

declare(strict_types=1);

namespace App\Http;

use App\LocalToken;
use Crab\Contracts\Http\Middleware;
use Crab\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LocalAccess implements Middleware
{
    public function __construct(
        #[\SensitiveParameter]
        private LocalToken $token,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $base = (string) config('app.url');
        $port = parse_url($base, PHP_URL_PORT) ?? 80;
        $hosts = ['127.0.0.1:' . $port, 'localhost:' . $port, '[::1]:' . $port];
        $host = strtolower($request->getHeaderLine('Host'));
        $origin = $request->getHeaderLine('Origin');
        if (
            !in_array($host, $hosts, true)
            || $origin !== ''
            && !in_array($origin, array_map(static fn(string $allowed): string => 'http://' . $allowed, $hosts), true)
        ) {
            return Response::json(['error' => ['message' => 'Postslap accepts local requests only.']], 403);
        }
        $openingComposer =
            $request->getMethod() === 'GET'
            && $request->getUri()->getPath() === '/'
            && $request->getHeaderLine('Sec-Fetch-Mode') === 'navigate'
            && $request->getHeaderLine('Sec-Fetch-Dest') === 'document';
        if ($request->getHeaderLine('Sec-Fetch-Site') === 'cross-site' && !$openingComposer) {
            return Response::json(['error' => ['message' => 'Cross-site requests are not allowed.']], 403);
        }
        if (str_starts_with($request->getUri()->getPath(), '/api/')) {
            if (!hash_equals('Bearer ' . $this->token->read(), $request->getHeaderLine('Authorization'))) {
                return Response::json(['error' => ['message' => 'Postslap requires its local access token.']], 401);
            }
            if (
                $request->getMethod() === 'POST'
                && !str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json')
            ) {
                return Response::json(['error' => ['message' => 'Send an application/json request.']], 415);
            }
        }
        return $handler
            ->handle($request)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
