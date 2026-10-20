<?php

declare(strict_types=1);

namespace App\Http;

use App\AccessToken;
use Crab\Contracts\Http\Middleware;
use Crab\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Postslop may run on another machine, so any host may reach it; the API answers only to its access token.
 */
final readonly class TokenAccess implements Middleware
{
    public function __construct(
        #[\SensitiveParameter]
        private AccessToken $token,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (str_starts_with($request->getUri()->getPath(), '/api/')) {
            if (!hash_equals('Bearer ' . $this->token->read(), $request->getHeaderLine('Authorization'))) {
                return Response::json(['error' => ['message' => 'Sign in with your Postslop access token.']], 401);
            }
            if (
                in_array($request->getMethod(), ['POST', 'PUT'], true)
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
