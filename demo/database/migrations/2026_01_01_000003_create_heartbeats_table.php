<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('heartbeats', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('ticks')->default(0);
            $table->unsignedInteger('worker_pid')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};
