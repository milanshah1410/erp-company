<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // e.g. Pieces, Kilogram, Litre
            $table->string('short_name', 20);       // e.g. pcs, kg, ltr
            $table->unsignedBigInteger('base_unit_id')->nullable();
            $table->decimal('conversion_factor', 15, 6)->default(1); // multiples of base unit
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('base_unit_id')->references('id')->on('units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
