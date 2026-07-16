<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow Builder (project-stack.md §11): IF (conditions) → THEN / ELSE
 * (actions: approval, notification, set-field), fired when a triggering entity
 * event occurs. Tenant-scoped so each tenant builds its own automation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('trigger');                 // e.g. expense.created
            $table->json('conditions')->nullable();    // [{field, operator, value}] (AND)
            $table->json('then_actions')->nullable();  // [{type, ...params}]
            $table->json('else_actions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'trigger', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
