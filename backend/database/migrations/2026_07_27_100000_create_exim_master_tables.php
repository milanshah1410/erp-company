<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import & Export Logistics module — Masters.
 *
 * The reference data every import/export shipment is built from: the country
 * and port catalogues, the carriers that move the cargo (shipping lines by sea,
 * airlines by air), and the service providers that handle it on the ground
 * (freight forwarders, customs house agents, inland transporters).
 *
 * All tenant-scoped like the rest of the masters — each tenant curates its own
 * trade lanes rather than sharing a global list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 2);              // ISO 3166-1 alpha-2
            $table->string('iso3', 3)->nullable();  // ISO 3166-1 alpha-3
            $table->string('name');
            $table->string('currency_code', 3)->nullable();
            $table->string('dial_code', 10)->nullable();
            $table->string('region')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        // Sea ports, airports, inland container depots and container freight
        // stations share one master — they play the same role on a shipment.
        Schema::create('ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);             // UN/LOCODE, e.g. INNSA
            $table->string('name');
            $table->string('type', 10)->default('sea'); // sea | air | icd | cfs
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('shipping_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('scac_code', 10)->nullable(); // carrier code on the B/L
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('airlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);                 // IATA, e.g. AI
            $table->string('icao_code', 10)->nullable();
            $table->string('name');
            $table->string('awb_prefix', 5)->nullable(); // 3-digit air-waybill prefix
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('freight_forwarders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('gst_number', 20)->nullable();
            $table->string('pan_number', 20)->nullable();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Customs House Agents — licensed to file Bills of Entry / Shipping
        // Bills on the tenant's behalf, usually at one home port.
        Schema::create('cha_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('license_no', 60)->nullable();
            $table->date('license_expiry')->nullable();
            $table->foreignId('port_id')->nullable()->constrained('ports')->nullOnDelete();
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('gst_number', 20)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Inland haulage contractors (port ↔ factory / CFS). Distinct from the
        // `vehicles`/`drivers` masters, which are the tenant's own fleet.
        Schema::create('transporters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('gst_number', 20)->nullable();
            $table->string('pan_number', 20)->nullable();
            $table->string('transport_mode', 20)->default('road'); // road | rail | multimodal
            $table->string('vehicle_types')->nullable();            // free text: trailer, container, tempo…
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transporters');
        Schema::dropIfExists('cha_agents');
        Schema::dropIfExists('freight_forwarders');
        Schema::dropIfExists('airlines');
        Schema::dropIfExists('shipping_lines');
        Schema::dropIfExists('ports');
        Schema::dropIfExists('countries');
    }
};
