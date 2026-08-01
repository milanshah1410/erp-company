<?php

declare(strict_types=1);

use App\Support\Subdomain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace addresses (§2 central schema, §5 subdomain provisioning).
 *
 * `tenants.subdomain` is the address the buyer picked at signup; `domains` is
 * the mapping table that makes it a real host — one primary row per tenant
 * today, room for tenant-supplied custom domains (CNAME) later.
 *
 * Existing tenants are back-filled from their company name so no workspace is
 * left address-less; collisions get a numeric tail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('subdomain', Subdomain::MAX)->nullable()->unique()->after('company_name');
        });

        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->string('subdomain', Subdomain::MAX)->nullable()->after('company_name');
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Fully-qualified host: `acme.yourapp.com`, or a custom domain later.
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_primary']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');

        Schema::table('pending_registrations', function (Blueprint $table) {
            $table->dropColumn('subdomain');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['subdomain']);
            $table->dropColumn('subdomain');
        });
    }

    /** Give every pre-existing tenant a unique address derived from its name. */
    private function backfill(): void
    {
        $used = [];

        foreach (DB::table('tenants')->select('id', 'company_name')->orderBy('id')->cursor() as $tenant) {
            $base = Subdomain::suggest((string) $tenant->company_name) ?: 'workspace';

            $candidate = $base;
            $n = 1;
            while (in_array($candidate, $used, true) || in_array($candidate, Subdomain::RESERVED, true)) {
                $candidate = substr($base, 0, Subdomain::MAX - 3).'-'.$n++;
            }
            $used[] = $candidate;

            DB::table('tenants')->where('id', $tenant->id)->update(['subdomain' => $candidate]);
            DB::table('domains')->insert([
                'tenant_id' => $tenant->id,
                'domain' => Subdomain::fqdn($candidate),
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
