<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;

/**
 * Which records can carry custom fields.
 *
 * The list is closed on purpose. A tenant admin picks an entity from this
 * catalogue when defining a field, and the API refuses anything else — an open
 * "any model" design would let a definition point at a table with no
 * `custom_fields` column, or at one whose values nothing ever renders.
 *
 * Adding an entity is three steps: add the JSON column in a migration, add
 * `use HasCustomFields` to the model, and add a row here.
 *
 * @see App\Models\Concerns\HasCustomFields
 */
final class CustomFieldEntities
{
    /**
     * key => [label, model, description]
     *
     * @var array<string, array{label: string, model: class-string, description: string}>
     */
    public const CATALOGUE = [
        'product' => [
            'label' => 'Products',
            'model' => Product::class,
            'description' => 'ISBN, author, age group, wattage, shade code — whatever this trade sells on',
        ],
        'customer' => [
            'label' => 'Customers',
            'model' => Customer::class,
            'description' => 'Membership tier, anniversary, referral source, school or company name',
        ],
        'supplier' => [
            'label' => 'Suppliers',
            'model' => Supplier::class,
            'description' => 'Agent name, credit terms, licence numbers',
        ],
        'sales_order' => [
            'label' => 'Sales Orders',
            'model' => SalesOrder::class,
            'description' => 'Occasion, gift-wrap instruction, delivery slot',
        ],
        'invoice' => [
            'label' => 'Invoices',
            'model' => Invoice::class,
            'description' => 'Anything the bill has to carry that the standard header does not',
        ],
        'purchase_order' => [
            'label' => 'Purchase Orders',
            'model' => PurchaseOrder::class,
            'description' => 'Indent reference, buyer initials, expected lot',
        ],
        'employee' => [
            'label' => 'Employees',
            'model' => Employee::class,
            'description' => 'Licence numbers, shift preference, uniform size',
        ],
    ];

    /**
     * Every entity key.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function exists(string $entity): bool
    {
        return isset(self::CATALOGUE[$entity]);
    }

    /**
     * The catalogue as a flat list, for the field builder's entity picker.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $key => $meta) {
            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
            ];
        }

        return $out;
    }

    /**
     * The entity key for a model class, or null if it is not a custom-field
     * host. Used by HasCustomFields so a model does not have to name itself
     * twice.
     */
    public static function forModel(string $class): ?string
    {
        foreach (self::CATALOGUE as $key => $meta) {
            if ($meta['model'] === $class || is_subclass_of($class, $meta['model'])) {
                return $key;
            }
        }

        return null;
    }
}
