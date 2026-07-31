<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point-in-time snapshot of a report's data (for audit / later comparison).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advanced_report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('report_id')
                ->constrained('advanced_reports')
                ->cascadeOnDelete();

            $table->foreignId('report_run_id')
                ->nullable()
                ->constrained('advanced_report_runs')
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unsignedBigInteger('tenant_id')->nullable();

            $table->json('parameters')->nullable();
            $table->json('data');
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['report_id', 'created_at']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advanced_report_snapshots');
    }
};
