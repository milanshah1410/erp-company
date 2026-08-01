<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic Custom Fields — the layer that lets one Product table serve a book
 * store, a toy shop and an electrical dealer without a migration each.
 *
 * The trade-specific modules (Jewellery, Optical, Pharmacy) earn real columns
 * because the software reasons about them: a metal rate prices a bill, a drug
 * schedule refuses a sale. A book's ISBN, a toy's age band and a bulb's wattage
 * earn nothing of the sort — they are printed, filtered and searched, never
 * computed on. Giving each of those a column would mean a migration per trade
 * and a `products` table nobody can read; giving them a shapeless JSON blob
 * would mean no labels, no validation and no way to render a form.
 *
 * So the shape lives in data. A definition row says what a field is called,
 * what type it holds, whether it is required and which module it belongs to;
 * the value lands in the entity's own `custom_fields` JSON column. Definitions
 * are per-tenant on purpose — one shop's "Age Group" is another's "Voltage".
 *
 * {@see App\Support\CustomFieldEntities} for which entities can carry fields,
 * {@see App\Services\CustomFieldService} for how definitions become rules.
 */
return new class extends Migration
{
    /** Entities that can carry custom fields — mirrors CustomFieldEntities::CATALOGUE. */
    private const TABLES = [
        'products',
        'customers',
        'suppliers',
        'sales_orders',
        'invoices',
        'purchase_orders',
        'employees',
    ];

    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Which entity this field hangs off — a CustomFieldEntities key
            // ('product', 'customer', …), not a table name, so the registry can
            // rename a table without orphaning definitions.
            $table->string('entity', 40);
            // The JSON key the value is stored under. snake_case and immutable
            // once created: changing it would orphan every value already saved.
            $table->string('key', 60);

            $table->string('label');
            $table->string('help_text')->nullable();

            /**
             * How the field is stored, validated and rendered. Deliberately a
             * short list — every type here maps to exactly one input widget and
             * one validation shape, which is what keeps the renderer generic.
             */
            $table->enum('type', [
                'text', 'textarea', 'number', 'decimal', 'date',
                'select', 'multiselect', 'boolean', 'email', 'url',
            ])->default('text');

            // Choices for select / multiselect: a list of {value, label}.
            $table->json('options')->nullable();
            // Pre-filled on create. Stored as a string and coerced to the
            // field's type on the way in, so one column serves every type.
            $table->string('default_value')->nullable();

            $table->boolean('is_required')->default(false);
            // Numeric bounds for number/decimal; length bounds for text types.
            // One pair of columns rather than four, because a field is never
            // both at once.
            $table->decimal('min_value', 20, 4)->nullable();
            $table->decimal('max_value', 20, 4)->nullable();
            // Bare regex (no delimiters) for text fields — an ISBN or an IMEI
            // is worth refusing at entry rather than discovering in a report.
            $table->string('pattern')->nullable();

            // Render this field as a column on the entity's list screen.
            $table->boolean('show_in_list')->default(false);
            // Include it in the list screen's free-text search.
            $table->boolean('is_searchable')->default(false);

            // Optional Modules::CATALOGUE key. A field tagged with a module
            // disappears — from the form, the rules and the list — when the
            // tenant switches that module off, exactly like a menu row.
            $table->string('module', 40)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One key per entity per tenant: two definitions writing the same
            // JSON key would silently overwrite each other.
            $table->unique(['tenant_id', 'entity', 'key']);
            $table->index(['tenant_id', 'entity', 'is_active']);
        });

        // The value side. A JSON column per entity rather than one shared
        // key/value table: values are read on every list and form load, and an
        // EAV join per row would cost far more than it saves. `products` keeps
        // its existing `attributes` column for variant/category attributes —
        // these are a separate, user-defined space and must not collide.
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->json('custom_fields')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('custom_fields');
            });
        }

        Schema::dropIfExists('custom_field_definitions');
    }
};
