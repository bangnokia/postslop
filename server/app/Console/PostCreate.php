<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Failure;
use App\Core\Platforms;
use App\Core\PostRequest;
use App\Core\Publishing;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

// Reading a post from flags, a file or standard input is a set of explicit guards.
// @mago-expect lint:cyclomatic-complexity
final readonly class PostCreate extends CliCommand
{
    public function __construct(
        private Publishing $publishing,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Write a post: saved as a draft unless --now or --at says otherwise
            Usage: postslop post "Text" [--to=x,bluesky,dev-null] [--image=a.png,b.png] [--now | --at=TIME]
            Options:
              --to=LIST          Destinations, comma separated (default: all three)
              --image=LIST       JPEG, PNG or WebP files of at most 1 MiB, comma separated
              --now              Publish immediately
              --at=TIME          Schedule: "2026-10-21 09:00", "tomorrow 9am" or "+2 hours", local time
              --request-id=UUID  Make the command safe to repeat: the same ID never posts twice
              --input=FILE       Read the whole post as JSON from FILE, or - for standard input:
                                 {"text", "platforms", "images": [{"path", "alt"}], "scheduledAt"}
              --dry-run          Check the post and the accounts without saving or sending anything
              --json             Print the post as JSON
            Text "-" reads the text from standard input.
            TEXT;
    }

    protected function run(Input $input, Output $output): int
    {
        $data = $this->data($input);
        $request = PostRequest::fromCli($data);
        if ($input->flag('dry-run')) {
            return $this->dryRun($input, $output, $request);
        }
        return self::show($input, $output, $this->publishing->create($request));
    }

    private function data(Input $input): array
    {
        $source = self::value($input, 'input');
        $data = $source === null ? self::fromFlags($input) : self::fromJson($source);
        $requestId = self::value($input, 'request-id');
        if ($requestId !== null) {
            $data['requestId'] = $requestId;
        }
        $at = self::value($input, 'at') ?? (is_string($data['scheduledAt'] ?? null) ? $data['scheduledAt'] : null);
        if ($input->flag('now') && $at !== null) {
            throw Failure::invalid('Choose --now or a scheduled time, not both.');
        }
        // Only a flag publishes: a post is a draft unless --now or a time says otherwise.
        $data['scheduledAt'] = $at === null ? null : self::time($at)->format(\DATE_ATOM);
        $data['mode'] = match (true) {
            $input->flag('now') => 'now',
            $at !== null => 'schedule',
            default => 'draft',
        };
        return $data;
    }

    private static function fromJson(string $source): array
    {
        $json = match (true) {
            $source === '-' => stream_get_contents(STDIN),
            is_file($source) && is_readable($source) => file_get_contents($source),
            default => false,
        };
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            throw Failure::invalid('--input must name a JSON object, or - for standard input.');
        }
        return $data;
    }

    private static function fromFlags(Input $input): array
    {
        $text = implode(' ', $input->arguments());
        $to = self::value($input, 'to');
        $images = self::value($input, 'image');
        return [
            'text' => $text === '-' ? rtrim((string) stream_get_contents(STDIN), "\n") : $text,
            'platforms' => $to === null
                ? Platforms::IDS
                : array_values(array_filter(array_map('trim', explode(',', $to)))),
            'images' => $images === null
                ? []
                : array_map(static fn(string $path): array => ['path' => trim($path)], explode(',', $images)),
        ];
    }

    private function dryRun(Input $input, Output $output, PostRequest $request): int
    {
        $accounts = array_column($this->publishing->accounts()['platforms'], null, 'id');
        $problems = [];
        foreach ($request->platforms as $platform) {
            $account = $accounts[$platform] ?? null;
            if (!($account['available'] ?? false)) {
                $problems[] = $platform . ': ' . ($account['reason'] ?? 'Session unavailable.');
            }
            $limit = Platforms::TEXT_LIMITS[$platform];
            if (preg_match_all('/\X/u', $request->text) > $limit) {
                $problems[] = $platform . ': longer than ' . $limit . ' characters.';
            }
        }
        $report = [
            'ok' => $problems === [],
            'mode' => $request->mode,
            'platforms' => $request->platforms,
            'images' => count($request->images),
            'scheduledAt' => $request->scheduledAt?->format('Y-m-d\TH:i:s\Z'),
            'problems' => $problems,
        ];
        $code = $problems === [] ? self::OK : self::INVALID;
        if ($input->flag('json')) {
            $output->line(self::json($report));
            return $code;
        }
        $output->line(
            $problems === []
                ? 'Ready to ' . $request->mode . ' to ' . implode(', ', $request->platforms) . '.'
                : 'Not ready:',
        );
        foreach ($problems as $problem) {
            $output->line('  ' . $problem);
        }
        return $code;
    }
}
