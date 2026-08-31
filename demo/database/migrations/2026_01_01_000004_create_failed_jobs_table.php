<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The consumer pool writes here through the JobFailed listener ConsumerRunner installs
 * — the one queue:work would otherwise have installed. Without the table a job that
 * exhausts its tries takes the handler down instead of being recorded.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
