<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Row-level permission grants on a report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advanced_report_permissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_id')
                ->constrained('advanced_reports')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('role_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            // view, edit, run, export, delete
            $table->string('permission')->default('view');

            $table->timestamps();

            $table->index(['report_id', 'permission']);
            // Ensure a user/role can't have a duplicate permission on the same report
            $table->unique(
                ['report_id', 'user_id', 'role_id', 'permission'],
                'advanced_report_perm_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advanced_report_permissions');
    }
};
