<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report scheduling foundation (cron-driven).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advanced_report_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('report_id')
                ->constrained('advanced_reports')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unsignedBigInteger('tenant_id')->nullable();

            $table->string('name');
            $table->string('cron_expression');
            $table->json('parameters')->nullable();

            $table->string('format')->default('pdf');
            $table->json('recipients')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advanced_report_schedules');
    }
};
