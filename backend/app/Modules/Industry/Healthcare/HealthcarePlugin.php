<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare;

use App\Modules\Industry\Contracts\IndustryPlugin;

/**
 * Healthcare — medical stores, diagnostic labs, clinics, hospitals, pharma
 * distributors and surgical dealers.
 *
 * What ties these six trades together is that almost everything they hold is
 * *dated and regulated*: a strip of medicine has a batch and an expiry, a
 * Schedule H drug may not leave the counter without a prescription, and a lab
 * result is a clinical record before it is a line on a bill. The spine already
 * knew how to hold batches and expiry; this vertical adds the two pieces of work
 * that had no home at all.
 *
 *  - **Diagnostic Lab.** A pathology order is not a sale with extra fields. It
 *    is registered against a patient, a sample is collected hours later, results
 *    are entered against reference ranges, a pathologist verifies them, and only
 *    then is a report released. Billing is one moment inside that, not the
 *    document itself.
 *
 *  - **Hospital Store.** A ward does not buy from the pharmacy, it *indents*.
 *    Stock leaves the central store against a department, with no customer and
 *    no invoice anywhere in it — so the sales spine is exactly the wrong tool,
 *    and until now the only way to record it was a stock adjustment that told
 *    nobody which ward consumed what.
 *
 * Prescriptions, drug schedules, the Schedule H1/X register, batches and expiry
 * are all pre-existing and are declared 'available' below because they genuinely
 * are — this plugin reaches them through the `pharmacy` and `inventory` modules
 * rather than restating them.
 */
class HealthcarePlugin implements IndustryPlugin
{
    public function key(): string
    {
        return 'healthcare';
    }

    public function label(): string
    {
        return 'Healthcare';
    }

    public function icon(): string
    {
        return 'pi pi-heart-fill';
    }

    public function description(): string
    {
        return 'Medical stores, diagnostic labs, clinics, hospitals, pharma distributors and surgical dealers.';
    }

    /**
     * What every healthcare trade runs on. `inventory` is not optional here even
     * for a clinic: batch and expiry are the whole reason this vertical is
     * different from ordinary retail, and they live in advanced inventory.
     *
     * @return list<string>
     */
    private function base(): array
    {
        return ['sales', 'purchase', 'inventory', 'accounts', 'gst', 'crm', 'ai'];
    }

    public function trades(): array
    {
        return [
            /*
             * Moved here from ShopProfiles::PROFILES, which used to own it. The
             * preset is unchanged — what it gains is a vertical, sibling trades
             * and an honest statement of which of its features exist.
             */
            'medical' => [
                'label' => 'Medical Store / Pharmacy',
                'icon' => 'pi pi-heart-fill',
                'description' => 'Batch & expiry, drug schedules, prescriptions and the Schedule H1/X register',
                'modules' => [...$this->base(), 'pharmacy', 'pos', 'devices', 'loyalty'],
                'features' => [
                    'pos' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    'prescription' => 'available',
                    'drug_register' => 'available',
                    // Suggesting a same-composition alternative when a brand is
                    // out of stock needs a composition index nothing builds yet.
                    'substitute' => 'planned',
                ],
            ],

            'diagnostic_lab' => [
                'label' => 'Diagnostic Lab / Pathology',
                'icon' => 'pi pi-filter',
                'description' => 'Test master, sample collection, result entry against reference ranges and verified reports',
                // No POS: a lab registers a patient and reports days later; the
                // counter-billing screen is the wrong shape for that.
                'modules' => [...$this->base(), 'diagnostics', 'devices'],
                'features' => [
                    'lab_test_master' => 'available',
                    'lab_order' => 'available',
                    'sample_collection' => 'available',
                    'result_entry' => 'available',
                    'report_verification' => 'available',
                    'test_panel' => 'available',
                    // Reading results straight off an analyser is a device
                    // integration (HL7/ASTM), not a screen.
                    'analyser_interface' => 'planned',
                    'home_collection' => 'planned',
                ],
            ],

            'clinic' => [
                'label' => 'Clinic / Doctor’s Chamber',
                'icon' => 'pi pi-user-plus',
                'description' => 'Consultation billing, prescriptions and lab test referrals',
                'modules' => [...$this->base(), 'pharmacy', 'diagnostics', 'hrms'],
                'features' => [
                    'prescription' => 'available',
                    // A consultation bills as an is_service product through the
                    // ordinary sales spine — nothing clinic-specific needed.
                    'consultation_billing' => 'available',
                    'lab_order' => 'available',
                    // A slot-booked diary with reminders is its own module; the
                    // CRM follow-up list is not a substitute and saying so would
                    // sell a calendar that does not exist.
                    'appointment' => 'planned',
                    'patient_history' => 'planned',
                ],
            ],

            'hospital' => [
                'label' => 'Hospital / Nursing Home',
                'icon' => 'pi pi-building',
                'description' => 'Central store indents to wards, in-house pharmacy and lab, consumable consumption by department',
                'modules' => [
                    ...$this->base(), 'hospital', 'pharmacy', 'diagnostics',
                    'hrms', 'assets', 'projects',
                ],
                'features' => [
                    'hospital_store' => 'available',
                    'department_indent' => 'available',
                    'department_consumption' => 'available',
                    'prescription' => 'available',
                    'lab_order' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    // Unused stock going back to the store is real and common,
                    // but it is a second document with its own authority.
                    'ward_return' => 'planned',
                    // Admissions, bed occupancy and a discharge summary are a
                    // hospital information system, not an ERP module.
                    'ipd' => 'planned',
                    'opd' => 'planned',
                ],
            ],

            'pharma_distributor' => [
                'label' => 'Pharma Distribution',
                'icon' => 'pi pi-truck',
                'description' => 'Stockist and C&F distribution: batch-wise selling, expiry returns, credit and beat routes',
                'modules' => [
                    ...$this->base(), 'pharmacy', 'field_sales', 'logistics', 'broker', 'devices',
                ],
                'features' => [
                    'batch' => 'available',
                    'expiry' => 'available',
                    // Near-expiry stock going back up the chain is an ordinary
                    // purchase/stock return, which exists.
                    'expiry_return' => 'available',
                    'route' => 'available',
                    'credit_limit' => 'available',
                    // A distributor holds the slip on file but does not dispense
                    // against it; the screen is reachable, the workflow is the
                    // retailer's.
                    'prescription' => 'available',
                    // Company-funded margin schemes (2+1, quantity slabs) price
                    // differently from a discount and are not built.
                    'scheme' => 'planned',
                ],
            ],

            'surgical' => [
                'label' => 'Surgical & Medical Devices',
                'icon' => 'pi pi-wrench',
                'description' => 'Instruments and equipment with serial tracking, warranty and service contracts',
                'modules' => [...$this->base(), 'pos', 'service', 'devices', 'loyalty'],
                'features' => [
                    'pos' => 'available',
                    'batch' => 'available',
                    'expiry' => 'available',
                    // Serialised equipment and the repair book both exist.
                    'serial_tracking' => 'available',
                    'amc' => 'available',
                    'service_job' => 'available',
                    // A warranty claim back to the principal is a document of
                    // its own; today a job card carries the note.
                    'warranty_claim' => 'planned',
                ],
            ],
        ];
    }
}
