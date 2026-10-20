<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', static function (Table $table): void {
            // The client's request ID is the post's identity, so a repeated submission finds it.
            $table->string('id', 36);
            $table->primary(['id']);
            $table->text('text');
            $table->json('platforms');
            $table->string('fingerprint', 64);
            $table->string('status', 16)->index();
            $table->string('scheduled_at', 20)->nullable()->index();
            $table->string('published_at', 20)->nullable();
            $table->string('created_at', 20)->index();
            $table->string('updated_at', 20);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('posts');
    }
};
