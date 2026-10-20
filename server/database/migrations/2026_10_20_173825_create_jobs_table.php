<?php

declare(strict_types=1);

use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->create('jobs', function (Table $table) {
            $table->id();
            $table->string('queue');
            // The job's class, and the arguments of its handle() as JSON.
            $table->string('job');
            $table->text('payload');
            $table->integer('attempts')->default(0);
            // Unix timestamps: when it may run, when a worker took it, when it was given up on.
            $table->bigInteger('available_at');
            $table->bigInteger('reserved_at')->nullable();
            $table->bigInteger('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->bigInteger('created_at');
            $table->index(['queue', 'failed_at', 'available_at']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('jobs');
    }
};
