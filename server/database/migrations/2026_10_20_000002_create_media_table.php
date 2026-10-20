<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->create('media', static function (Table $table): void {
            $table->string('id', 36);
            $table->primary(['id']);
            $table->string('post_id', 36)->references('posts', onDelete: 'cascade');
            $table->integer('position');
            $table->string('mime_type', 32);
            $table->integer('width');
            $table->integer('height');
            $table->text('alt');
            $table->string('sha256', 64);
            $table->index(['post_id', 'position']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('media');
    }
};
