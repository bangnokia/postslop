<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Core\Failure;
use App\Core\Platforms;
use App\Core\PostRequest;
use App\Core\Publishing;

/** MCP names and input shapes; persistence and publishing stay in the core. */
final readonly class Tools
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public static function definitions(): array
    {
        $id = [
            'type' => 'string',
            'pattern' => '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$',
            'description' => 'A lowercase UUID. For a new draft, generate it once and reuse it after a lost response.',
        ];
        $platform = ['type' => 'string', 'enum' => Platforms::IDS];
        $content = self::content();
        $definitions = [
            'postslop_accounts' => [
                'description' => 'List account handles, availability, text/image limits and failure details. Use available accounts only; ask the user to reconnect unavailable accounts. Never request browser cookies.',
                'inputSchema' => self::object([]),
            ],
            'postslop_check_account' => [
                'description' => 'Refresh the sign-in status of one account without checking the other sites. Returns metadata, never credentials.',
                'inputSchema' => self::object(['platform' => $platform], ['platform']),
            ],
            'postslop_list_posts' => [
                'description' => 'List saved posts and delivery results, newest first. Pass next from the previous response as before for another page.',
                'inputSchema' => self::object([
                    'statuses' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => [
                                'draft',
                                'scheduled',
                                'queued',
                                'publishing',
                                'published',
                                'partial',
                                'failed',
                                'unknown',
                            ],
                        ],
                        'maxItems' => 8,
                    ],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'before' => [
                        'type' => 'string',
                        'pattern' => '^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$',
                        'description' => 'Use next from the previous page.',
                    ],
                ]),
            ],
            'postslop_get_post' => [
                'description' => 'Read a post and each delivery result. Poll this after publishing until the post has finished. An unknown result may already be live: never retry it or create a replacement.',
                'inputSchema' => self::object(['id' => $id], ['id']),
            ],
            'postslop_create_draft' => [
                'description' => 'Save a draft; never publishes or schedules. Require an explicit list of destinations and a stable requestId. Repeating the same requestId/content returns the existing post, even if it has since been published.',
                'inputSchema' => self::object(['requestId' => $id, ...$content], ['requestId', 'text', 'platforms']),
            ],
            'postslop_update_draft' => [
                'description' => 'Replace all text, destinations and images of an editable post and return it to draft, clearing any schedule. Send all images to keep, or [] to remove them. Read the post first. Schedule again explicitly if wanted.',
                'inputSchema' => self::object(['id' => $id, ...$content], ['id', 'text', 'platforms', 'images']),
            ],
            'postslop_schedule_post' => [
                'description' => 'Schedule an existing draft or move its schedule, only when the user explicitly asks. Resolve their timezone and send an ISO 8601 timestamp with an offset. null cancels the schedule and returns it to draft. The running server sends it through its queues when due.',
                'inputSchema' => self::object([
                    'id' => $id,
                    'scheduledAt' => [
                        'type' => ['string', 'null'],
                        'pattern' => '^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$',
                        'description' => 'Date and time with a timezone, for example 2027-01-02T09:00:00+07:00, or null to unschedule.',
                    ],
                ], ['id', 'scheduledAt']),
            ],
            'postslop_publish_post' => [
                'description' => 'Publish an existing draft or scheduled post NOW, only when the user explicitly asks. Queues each destination and answers immediately; this is not confirmation of publication. Poll postslop_get_post and report each delivery URL/error. Repeating this ID never sends twice; it does not retry failed or unknown deliveries.',
                'inputSchema' => self::object(['id' => $id], ['id']),
            ],
        ];
        foreach ($definitions as $name => &$definition) {
            $readOnly = in_array(
                $name,
                ['postslop_accounts', 'postslop_check_account', 'postslop_list_posts', 'postslop_get_post'],
                true,
            );
            $definition = [
                'name' => $name,
                ...$definition,
                'annotations' => [
                    'readOnlyHint' => $readOnly,
                    'destructiveHint' => in_array(
                        $name,
                        ['postslop_update_draft', 'postslop_schedule_post', 'postslop_publish_post'],
                        true,
                    ),
                    'idempotentHint' => true,
                    'openWorldHint' => in_array(
                        $name,
                        [
                            'postslop_accounts',
                            'postslop_check_account',
                            'postslop_schedule_post',
                            'postslop_publish_post',
                        ],
                        true,
                    ),
                ],
            ];
        }
        return $definitions;
    }

    public function call(string $name, \stdClass $arguments): array
    {
        $input = Arguments::validate($arguments, self::definitions()[$name]['inputSchema']);
        return match ($name) {
            'postslop_accounts' => $this->publishing->accounts(),
            'postslop_check_account' => $this->publishing->refreshAccount($input['platform']),
            'postslop_list_posts' => $this->publishing->list(
                $input['statuses'] ?? [],
                $input['limit'] ?? 20,
                $input['before'] ?? null,
            ),
            'postslop_get_post' => $this->publishing->find($input['id']),
            'postslop_create_draft' => $this->publishing->createQueued(PostRequest::fromApi([
                ...$input,
                'mode' => 'draft',
            ])),
            'postslop_update_draft' => $this->publishing->updateQueued($input['id'], PostRequest::fromApi([
                ...$input,
                'requestId' => $input['id'],
                'mode' => 'draft',
            ])),
            'postslop_schedule_post' => $this->publishing->schedule($input['id'], self::time($input['scheduledAt'])),
            'postslop_publish_post' => $this->publishing->queue($input['id']),
        };
    }

    private static function content(): array
    {
        return [
            'text' => ['type' => 'string', 'maxLength' => 3000],
            'platforms' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => Platforms::IDS],
                'minItems' => 1,
                'maxItems' => count(Platforms::IDS),
            ],
            'images' => [
                'type' => 'array',
                'maxItems' => PostRequest::MAX_IMAGES,
                'description' => 'JPEG, PNG or WebP, at most 1 MiB each. Send base64 bytes, never paths or URLs. Include alt text.',
                'items' => self::object([
                    'data' => ['type' => 'string', 'maxLength' => 1_398_104],
                    'mimeType' => ['type' => 'string', 'enum' => ['image/jpeg', 'image/png', 'image/webp']],
                    'alt' => ['type' => 'string', 'maxLength' => 1000],
                ], ['data', 'alt']),
            ],
        ];
    }

    private static function object(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => (object) $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    private static function time(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($time === false || $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw Failure::invalid('scheduledAt must be a real date and time with a timezone.');
        }
        return $time;
    }
}
