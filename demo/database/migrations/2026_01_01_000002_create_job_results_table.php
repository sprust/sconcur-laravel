<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_results', static function (Blueprint $table): void {
            $table->id();
            $table->string('payload');
            $table->unsignedInteger('worker_pid');
            $table->unsignedInteger('duration_ms');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_results');
    }
};
