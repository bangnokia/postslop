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
    }

    public function down(Schema $schema): void
    {
        $schema->drop('deliveries');
    }
};
