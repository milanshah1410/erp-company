<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Physical attendance devices (fingerprint / face) that push punch logs.
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('ip_address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Raw in/out punches ingested from devices; rolled up into attendance.
        Schema::create('biometric_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('biometric_device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            // Device-side identifier used to map a punch to an employee.
            $table->string('device_user_id')->nullable();
            $table->timestamp('punched_at');
            $table->enum('direction', ['in', 'out'])->default('in');
            $table->string('source')->default('device'); // device, import, manual
            $table->boolean('processed')->default(false); // rolled into attendance yet?
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'punched_at']);
        });

        // Map a device-side user id to an employee for punch resolution.
        Schema::table('employees', function (Blueprint $table) {
            $table->string('biometric_user_id')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('biometric_user_id');
        });
        Schema::dropIfExists('biometric_punches');
        Schema::dropIfExists('biometric_devices');
    }
};
