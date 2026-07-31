<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores report definitions (JSON metadata).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advanced_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->string('data_source');

            // Full metadata-driven definition (columns, filters, groups, etc.)
            $table->json('definition')->nullable();

            // Multi-tenant scoping (optional)
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_public')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advanced_reports');
    }
};
