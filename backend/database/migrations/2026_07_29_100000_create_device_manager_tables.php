<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device Manager: a plugin-based hardware integration layer.
 *
 * `devices` is one configured piece of hardware — a barcode scanner, a label /
 * thermal printer, a weighing scale, an RFID reader or a biometric terminal.
 * `type` picks the driver interface, `driver` picks the brand adapter (TVS,
 * Zebra, Honeywell, eSSL, ZKTeco…) from App\Modules\DeviceManager's registry,
 * and `mode` decides whether the adapter talks to real hardware ('live') or to
 * the built-in simulator ('simulator') — the latter lets a shop-floor flow be
 * developed and regression-tested with no hardware present at all.
 *
 * `device_events` is the append-only live event log every operation writes to:
 * connection tests, scans, print jobs, weight readings, tag reads and punches,
 * successful or failed. The Device Manager screen tails it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                 // barcode | printer | weight_scale | rfid | biometric
            $table->string('driver', 40);               // brand adapter key, e.g. zebra_zpl, essl_push
            $table->string('code', 50);                 // short handle used by POS/scan screens
            $table->string('name');
            $table->string('location')->nullable();     // counter 1, packing table, gate…
            $table->enum('mode', ['live', 'simulator'])->default('simulator');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // default device of its type
            $table->json('settings')->nullable();       // non-secret driver options (agent url, port, dpi…)
            $table->text('credentials')->nullable();    // encrypted JSON (api keys, device passwords)

            // Optional links so a device belongs to a physical place / feeds an
            // existing HR device row (biometric punches keep flowing to attendance).
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('biometric_device_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('status', ['unknown', 'online', 'offline', 'error'])->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_error')->nullable();
            $table->unsignedBigInteger('events_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type', 'is_active']);
        });

        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('device_type', 20)->nullable();  // denormalised so a deleted device still reads sensibly
            $table->string('driver', 40)->nullable();
            $table->string('event', 20);                    // connection | scan | print | weight | tag | punch | config
            $table->enum('direction', ['in', 'out'])->default('in');
            $table->enum('status', ['success', 'failed', 'info'])->default('success');
            $table->enum('source', ['live', 'simulator'])->default('live');
            $table->string('message', 500)->nullable();
            $table->json('payload')->nullable();            // normalised reading / job summary
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            // The live log tails by descending id within a tenant, optionally
            // narrowed to one device or event type.
            $table->index(['tenant_id', 'id']);
            $table->index(['device_id', 'event']);
            $table->index(['tenant_id', 'event', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
        Schema::dropIfExists('devices');
    }
};
