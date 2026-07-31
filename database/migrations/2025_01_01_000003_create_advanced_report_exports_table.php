<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores generated export files (sync or queued).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advanced_report_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('report_id')
                ->constrained('advanced_reports')
                ->cascadeOnDelete();

            $table->foreignId('report_run_id')
                ->nullable()
                ->constrained('advanced_report_runs')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unsignedBigInteger('tenant_id')->nullable();

            $table->string('format') // html, pdf, xlsx, csv, json
                ->default('pdf');
            $table->string('status') // pending, processing, completed, failed
                ->default('pending');

            $table->string('file_path')->nullable();
            $table->string('disk')->nullable();
            $table->json('options')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->longText('error_message')->nullable();

            $table->timestamps();

            $table->index(['report_id', 'status']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advanced_report_exports');
    }
};
