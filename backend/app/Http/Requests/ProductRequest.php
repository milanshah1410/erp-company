<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesCustomFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    use ValidatesCustomFields;

    public function authorize(): bool
    {
        return true; // route middleware handles permission checks
    }

    protected function customFieldEntity(): string
    {
        return 'product';
    }

    /**
     * A blank HUID is no HUID.
     *
     * Overrides the trait's hook rather than adding a second one, so the custom
     * field preparation it does still runs. Without this, two products saved
     * from a form that sends an empty string collide on the unique rule below
     * and the second one is refused a mark it never claimed to have.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareCustomFieldInput();

        if ($this->has('huid') && trim((string) $this->input('huid')) === '') {
            $this->merge(['huid' => null]);
        }
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        // The tenant's own fields are appended, so a book store's ISBN is
        // validated exactly like `sku` without this file knowing it exists.
        return $this->customFieldRules() + [
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($productId)],
            'barcode' => ['nullable', 'string', 'max:255'],
            'rfid_tag' => ['nullable', 'string', 'max:255', Rule::unique('products', 'rfid_tag')->ignore($productId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'hsn_code' => ['nullable', 'string', 'max:20'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_stock_level' => ['nullable', 'numeric', 'min:0'],
            'max_stock_level' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:255'],
            'attributes' => ['nullable', 'array'], // values for category attribute definitions
            'track_expiry' => ['boolean'],
            'track_batch' => ['boolean'],
            'track_serial' => ['boolean'],
            'has_variants' => ['boolean'],
            // Labour / visit charges: billable and taxable, but never stocked.
            'is_service' => ['boolean'],
            /**
             * The warranty policy. `min:0` rather than `min:1` on purpose — 0 is
             * a real answer meaning "sold without cover", and is not the same as
             * leaving it blank.
             */
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'warranty_terms' => ['nullable', 'string', 'max:2000'],
            // Pharmacy: h/h1/x may not be sold without a prescription; h1/x
            // also land in the statutory register.
            'drug_schedule' => ['nullable', Rule::in(['none', 'g', 'h', 'h1', 'x'])],
            'composition' => ['nullable', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            /**
             * Jewellery. A metal takes the item off `selling_price` altogether —
             * it is then worth its net weight at the day's rate plus making, and
             * JewelleryPricingService builds the number at the moment of sale.
             *
             * `net_weight` is the one that earns the rate, so it is bounded
             * against the gross: metal cannot outweigh the finished piece, and a
             * transposed pair of weights is otherwise invisible until the bill
             * comes out wrong.
             */
            'metal' => ['nullable', Rule::in(['gold', 'silver', 'platinum'])],
            'purity' => ['nullable', 'string', 'max:10', 'required_with:metal'],
            'gross_weight' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            // The bound is only applied when a gross weight was actually given —
            // `lte` against a missing field is a comparison Laravel refuses to
            // make, and plenty of shops enter net alone on an unstudded piece.
            'net_weight' => array_values(array_filter([
                'nullable', 'numeric', 'min:0', 'max:999999',
                $this->filled('gross_weight') ? 'lte:gross_weight' : null,
            ])),
            'stone_weight' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'stone_value' => ['nullable', 'numeric', 'min:0'],
            'making_charge_type' => ['nullable', Rule::in(['percent', 'per_gram', 'flat'])],
            'making_charge_value' => ['nullable', 'numeric', 'min:0'],
            'wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            /**
             * The BIS mark. A HUID is unique by construction — six characters
             * identifying one article — so the same one on two products is a
             * mis-key or a re-used tag, and either way the register is wrong.
             */
            'huid' => ['nullable', 'string', 'max:20', Rule::unique('products', 'huid')->ignore($productId)],
            'hallmark_centre' => ['nullable', 'string', 'max:120'],
            'hallmarked_on' => ['nullable', 'date'],
            'hallmark_charge' => ['nullable', 'numeric', 'min:0'],
            /**
             * Optical: the lens specification, and the range a blank can
             * actually be ground in. The range is not description — OpticalService
             * refuses an order outside it, because a lens that cannot be made is
             * a week of the customer's time and a wasted trip to the lab.
             *
             * `min_sph` is bounded against `max_sph` the same way `net_weight` is
             * bounded against `gross_weight` above, and for the same reason: a
             * transposed pair reads as a lens that can be made in nothing at all,
             * and stays invisible until an order is refused for no clear reason.
             */
            'lens_type' => ['nullable', Rule::in(['single_vision', 'bifocal', 'progressive', 'zero_power'])],
            'lens_material' => ['nullable', Rule::in(['cr39', 'polycarbonate', 'trivex', 'high_index', 'glass'])],
            // Refractive index. 1.50 (CR39) through 1.74 is the whole market;
            // anything outside it is a mis-key.
            'lens_index' => ['nullable', 'numeric', 'min:1.4', 'max:2'],
            'lens_coating' => ['nullable', Rule::in(['none', 'ar', 'blue_cut', 'photochromic', 'uv'])],
            'min_sph' => array_values(array_filter([
                'nullable', 'numeric', 'between:-30,30',
                $this->filled('max_sph') ? 'lte:max_sph' : null,
            ])),
            'max_sph' => ['nullable', 'numeric', 'between:-30,30'],
            'max_cyl' => ['nullable', 'numeric', 'between:-12,12'],
            'is_frame' => ['boolean'],
            /**
             * Optical plugin: the frame itself. The three millimetre figures are
             * the boxed marking stamped inside the temple (52▫18-140), and the
             * bounds are the physical ones — a 30mm lens width is a doll's frame
             * and a 200mm temple belongs to nothing with a face behind it.
             */
            'frame_shape' => ['nullable', Rule::in([
                'rectangle', 'square', 'round', 'oval', 'cat_eye', 'aviator', 'wayfarer', 'wrap', 'geometric',
            ])],
            'frame_rim' => ['nullable', Rule::in(['full_rim', 'half_rim', 'rimless'])],
            'frame_material' => ['nullable', Rule::in([
                'acetate', 'metal', 'titanium', 'tr90', 'stainless_steel', 'alloy', 'wood', 'combination',
            ])],
            'frame_gender' => ['nullable', Rule::in(['men', 'women', 'unisex', 'kids'])],
            'frame_colour' => ['nullable', 'string', 'max:60'],
            'frame_eye_size' => ['nullable', 'integer', 'min:30', 'max:80'],
            'frame_bridge_size' => ['nullable', 'integer', 'min:10', 'max:30'],
            'frame_temple_length' => ['nullable', 'integer', 'min:100', 'max:180'],
            'frame_tray' => ['nullable', 'string', 'max:40'],
            // Agriculture: what a farmer asks for. `agri_input_type` also decides
            // which licence the licence register expects to find behind this
            // item, so it is the one field here that is more than a description.
            'agri_input_type' => ['nullable', Rule::in(['seed', 'fertilizer', 'pesticide', 'feed', 'equipment', 'other'])],
            'crop' => ['nullable', 'string', 'max:100'],
            'variety' => ['nullable', 'string', 'max:100'],
            'technical_name' => ['nullable', 'string', 'max:255'],
            'nutrient_content' => ['nullable', 'string', 'max:100'],
            'germination_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Supermarket: alcohol, tobacco, blades. Read only by the
            // self-checkout lane, which parks a basket containing one until a
            // supervisor has looked at the customer.
            'is_age_restricted' => ['boolean'],
            /**
             * Toys: the age band and the safety class. Existence-checked rather
             * than left free, because both are read at the till to decide what a
             * customer is told and — for a class that blocks — whether the sale
             * happens at all.
             */
            'toy_age_grade_id' => ['nullable', 'exists:toy_age_grades,id'],
            'toy_safety_category_id' => ['nullable', 'exists:toy_safety_categories,id'],
            /**
             * Cosmetics: the shade, for a shop that keys one SKU per shade (a
             * range keyed as variants carries it on the variant instead), and the
             * period after opening.
             *
             * PAO is bounded at 120 months for the same reason the toy age bands
             * are bounded: the number is read off a symbol on the back of a pack
             * and typed by hand, and "36" meaning three years is the mistake that
             * would otherwise let a tester sit on a counter until 2029.
             */
            'cosmetic_shade_id' => ['nullable', 'exists:cosmetic_shades,id'],
            'pao_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            /**
             * Books: the title's own facts. Only the *shape* is checked here —
             * whether the number is a real ISBN is decided by its check digit,
             * and that arithmetic belongs to the Book Store plugin, which core
             * must not depend on. BookStorePlugin's observer normalises and
             * verifies both forms on every save (importer and channel sync
             * included) and throws the same 422 this file would have, so a bad
             * ISBN cannot reach the database by any route.
             *
             * The lengths are 17 and 13 rather than 13 and 10 because a shop
             * types the hyphens it can see on the cover; the observer strips them
             * before storing.
             */
            'book_isbn13' => ['nullable', 'string', 'max:17'],
            'book_isbn10' => ['nullable', 'string', 'max:13'],
            'book_publisher_id' => ['nullable', 'exists:book_publishers,id'],
            'book_language_id' => ['nullable', 'exists:book_languages,id'],
            // Restated rather than read from the plugin, exactly as the
            // agriculture list above is. Must stay in step with the enum in the
            // Book Store migration and with TitleController::BINDINGS.
            'book_binding' => ['nullable', Rule::in([
                'paperback', 'hardcover', 'board_book', 'spiral',
                'leather', 'magazine', 'ebook', 'audiobook', 'other',
            ])],
            'book_edition' => ['nullable', 'string', 'max:80'],
            'book_pages' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'book_published_on' => ['nullable', 'date'],
            'book_series' => ['nullable', 'string', 'max:160'],
            'book_volume' => ['nullable', 'string', 'max:20'],
            // The price on the cover, which is not the same question as
            // `selling_price` — see the note on the column.
            'book_printed_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
