<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();      // zone > rack > shelf > bin
            $table->string('code', 50);                               // e.g. A-01-03
            $table->string('name');
            $table->enum('type', ['zone', 'rack', 'shelf', 'bin'])->default('bin');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('warehouse_locations')->nullOnDelete();
            $table->unique(['warehouse_id', 'code']);
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_locations');
    }
};
