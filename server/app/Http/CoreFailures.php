<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\Failure;
use Crab\Contracts\Http\Middleware;
use Crab\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers a core refusal with its HTTP status, so actions stay free of HTTP rules.
 */
final readonly class CoreFailures implements Middleware
{
    private const array STATUSES = [
        Failure::INVALID => 422,
        Failure::NOT_FOUND => 404,
        Failure::CONFLICT => 409,
        Failure::BUSY => 409,
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Failure $failure) {
            return Response::json(['error' => [
                'code' => $failure->kind,
                'message' => $failure->getMessage(),
            ]], self::STATUSES[$failure->kind]);
        }
    }
}
