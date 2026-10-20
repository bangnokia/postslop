<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->create('deliveries', static function (Table $table): void {
            $table->id();
            $table->string('post_id', 36)->references('posts', onDelete: 'cascade');
            $table->integer('position');
            $table->string('platform', 16);
            $table->string('status', 16)->index();
            // The publisher's complete result, so nothing it reported is lost.
            $table->json('result');
            $table->string('updated_at', 20);
            $table->unique(['post_id', 'platform']);
        });
        $this->importReceipts($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->drop('deliveries');
    }

    // Receipts written before the database keep their request IDs, so Check Result still finds them.
    private function importReceipts(Schema $schema): void
    {
        $directory = (string) config('postslop.posts_path');
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $id = basename($path, '.json');
            $record = json_decode((string) file_get_contents($path), true);
            if (
                preg_match('/^[a-f0-9-]{36}$/D', $id) !== 1
                || !is_array($record)
                || !is_string($record['fingerprint'] ?? null)
                || !is_array($record['results'] ?? null)
            ) {
                continue;
            }
            $time = gmdate('Y-m-d\TH:i:s\Z', (int) filemtime($path));
            $results = array_map(
                // A dev-null.sh post saved for moderation counts as published.
                static fn(array $result): array => ($result['pendingReview'] ?? false) === true
                    ? [...array_diff_key($result, ['pendingReview' => true, 'error' => true]), 'status' => 'published']
                    : $result,
                array_values(array_filter($record['results'], 'is_array')),
            );
            $platforms = array_column($results, 'platform');
            $states = array_column($results, 'status');
            $published = count(array_keys($states, 'published', true));
            $status = match (true) {
                $published > 0 && $published === count($states) => 'published',
                $published > 0 => 'partial',
                $states === [] || in_array('unknown', $states, true) => 'unknown',
                default => 'failed',
            };
            $schema->connection()->table('posts')->insert([
                'id' => $id,
                'text' => '',
                'platforms' => json_encode($platforms, JSON_THROW_ON_ERROR),
                'fingerprint' => $record['fingerprint'],
                'status' => $status,
                'published_at' => $published > 0 ? $time : null,
                'created_at' => $time,
                'updated_at' => $time,
            ]);
            foreach ($results as $position => $result) {
                $schema->connection()->table('deliveries')->insert([
                    'post_id' => $id,
                    'position' => $position,
                    'platform' => (string) ($result['platform'] ?? ''),
                    'status' => (string) ($result['status'] ?? 'unknown'),
                    'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $time,
                ]);
            }
        }
    }
};
