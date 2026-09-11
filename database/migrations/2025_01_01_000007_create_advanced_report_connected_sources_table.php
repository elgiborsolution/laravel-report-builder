<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the persisted connection between a DataSource (from laravel-data-sources)
 * and the report engine's source registry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advanced_report_connected_sources', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('data_source_id');
            $table->string('source_key')->unique();

            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('created_by')->nullable();

            $table->timestamps();

            $table->index('data_source_id');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advanced_report_connected_sources');
    }
};
