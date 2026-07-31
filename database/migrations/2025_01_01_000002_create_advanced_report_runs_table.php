<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores per-execution run history of a report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advanced_report_runs', function (Blueprint $table) {
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
            $table->json('parameters')->nullable();

            $table->string('status') // pending, running, completed, failed
                ->default('pending');
            $table->unsignedInteger('row_count')->default(0);
            $table->json('summary')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->longText('error_message')->nullable();

            $table->timestamps();

            $table->index(['report_id', 'status']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advanced_report_runs');
    }
};
