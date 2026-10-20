<?php

declare(strict_types=1);

namespace App\Actions;

use App\Http\PostInput;
use App\Publishing;
use Crab\Http\HttpException;
use Psr\Http\Message\ServerRequestInterface;

final readonly class Api
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function status(): array
    {
        return $this->publishing->status();
    }

    public function show(string $requestId): array
    {
        return $this->publishing->find($requestId);
    }

    public function publish(ServerRequestInterface $request): array
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            throw new HttpException(422, 'Send a JSON post object.');
        }
        return $this->publishing->publish(PostInput::from($data));
    }
}
