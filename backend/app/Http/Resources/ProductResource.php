<?php

namespace App\Http\Resources;

use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'rfid_tag' => $this->rfid_tag,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'brand_id' => $this->brand_id,
            'brand' => $this->whenLoaded('brand', fn () => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ]),
            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'short_name' => $this->unit->short_name,
            ]),
            'hsn_code' => $this->hsn_code,
            'cost_price' => $this->cost_price,
            'selling_price' => $this->selling_price,
            'tax_percent' => $this->tax_percent,
            'min_stock_level' => $this->min_stock_level,
            'max_stock_level' => $this->max_stock_level,
            'reorder_level' => $this->reorder_level,
            'image' => $this->image,
            'image_url' => TenantStorage::temporaryUrl($this->image),
            'attributes' => $this->getAttribute('attributes') ?? [],
            // Tenant-defined fields. Separate from `attributes`, which belongs
            // to variants and category attributes. Cast so an empty bag is `{}`
            // and not `[]` — the form binds keys straight onto it.
            'custom_fields' => (object) ($this->custom_fields ?? []),
            'track_expiry' => $this->track_expiry,
            'track_batch' => $this->track_batch,
            'track_serial' => $this->track_serial,
            'has_variants' => $this->has_variants,
            'is_service' => (bool) $this->is_service,
            'warranty_months' => $this->warranty_months,
            'warranty_terms' => $this->warranty_terms,
            'drug_schedule' => $this->drug_schedule,
            'composition' => $this->composition,
            'manufacturer' => $this->manufacturer,
            // Derived so the POS never has to know which schedules mean what.
            'requires_prescription' => $this->resource->requiresPrescription(),
            /**
             * Jewellery. Cast rather than passed through because the product
             * editor binds them to numeric inputs, and a decimal cast hands back
             * a string that a number field then refuses to show.
             */
            'metal' => $this->metal,
            'purity' => $this->purity,
            'gross_weight' => $this->gross_weight === null ? null : (float) $this->gross_weight,
            'net_weight' => $this->net_weight === null ? null : (float) $this->net_weight,
            'stone_weight' => $this->stone_weight === null ? null : (float) $this->stone_weight,
            'stone_value' => (float) $this->stone_value,
            'making_charge_type' => $this->making_charge_type,
            'making_charge_value' => (float) $this->making_charge_value,
            'wastage_percent' => (float) $this->wastage_percent,
            'huid' => $this->huid,
            'hallmark_centre' => $this->hallmark_centre,
            'hallmarked_on' => $this->hallmarked_on?->toDateString(),
            'hallmark_charge' => (float) $this->hallmark_charge,
            // Whether the till prices this off the rate card at all — the same
            // question the POS asks, answered once here.
            'is_rate_priced' => $this->resource->isRatePriced(),
            // The stone register, only when it was asked for: the product list
            // is a hot endpoint and most shops have no stones at all.
            'stones' => ProductStoneResource::collection($this->whenLoaded('stones')),
            'stone_count' => $this->when(
                $this->relationLoaded('stones'),
                fn () => $this->stones->count()
            ),
            /**
             * Optical: the lens specification and the frame's own facts.
             * Numbers are cast out of their decimal casts for the same reason
             * the jewellery weights above are — the product editor binds them to
             * numeric inputs, which refuse the string a decimal cast hands back.
             */
            'lens_type' => $this->lens_type,
            'lens_material' => $this->lens_material,
            'lens_index' => $this->lens_index === null ? null : (float) $this->lens_index,
            'lens_coating' => $this->lens_coating,
            'min_sph' => $this->min_sph === null ? null : (float) $this->min_sph,
            'max_sph' => $this->max_sph === null ? null : (float) $this->max_sph,
            'max_cyl' => $this->max_cyl === null ? null : (float) $this->max_cyl,
            'is_frame' => (bool) $this->is_frame,
            'frame_shape' => $this->frame_shape,
            'frame_rim' => $this->frame_rim,
            'frame_material' => $this->frame_material,
            'frame_gender' => $this->frame_gender,
            'frame_colour' => $this->frame_colour,
            'frame_eye_size' => $this->frame_eye_size,
            'frame_bridge_size' => $this->frame_bridge_size,
            'frame_temple_length' => $this->frame_temple_length,
            'frame_tray' => $this->frame_tray,
            /**
             * The boxed marking as it is stamped and as a customer reads it —
             * 52▫18-140. Derived here rather than in the client because it is
             * printed on the tray label as well as shown on the screen, and two
             * places assembling it from three columns is two places to get the
             * separator wrong.
             */
            'frame_size' => $this->when(
                $this->frame_eye_size !== null || $this->frame_bridge_size !== null,
                fn () => trim(sprintf(
                    '%s▫%s%s',
                    $this->frame_eye_size ?? '—',
                    $this->frame_bridge_size ?? '—',
                    $this->frame_temple_length ? '-'.$this->frame_temple_length : '',
                )),
            ),
            // Agriculture plugin: crop, grade and technical name.
            'agri_input_type' => $this->agri_input_type,
            'crop' => $this->crop,
            'variety' => $this->variety,
            'technical_name' => $this->technical_name,
            'nutrient_content' => $this->nutrient_content,
            'germination_percent' => $this->germination_percent === null ? null : (float) $this->germination_percent,
            // Supermarket: whether an unattended lane may sell this unsupervised.
            'is_age_restricted' => (bool) $this->is_age_restricted,
            /**
             * Toys: the age band and the safety class. Ids always, so the product
             * editor can round-trip them; the labels only when the relations have
             * been loaded, so a workspace without the vertical pays nothing for
             * two joins it will never read.
             */
            'toy_age_grade_id' => $this->toy_age_grade_id,
            'toy_safety_category_id' => $this->toy_safety_category_id,
            'toy_age_grade' => $this->whenLoaded('toyAgeGrade', fn () => [
                'id' => $this->toyAgeGrade->id,
                'label' => $this->toyAgeGrade->label,
                'range' => $this->toyAgeGrade->range(),
                'warning' => $this->toyAgeGrade->warning,
            ]),
            'toy_safety_category' => $this->whenLoaded('toySafetyCategory', fn () => [
                'id' => $this->toySafetyCategory->id,
                'label' => $this->toySafetyCategory->label,
                'standard' => $this->toySafetyCategory->standard,
                'warning' => $this->toySafetyCategory->warning(),
                'hazards' => $this->toySafetyCategory->hazardLabels(),
            ]),
            /**
             * Cosmetics: the shade and the period after opening. `pao_months` is
             * cast out of its integer column for the product editor's numeric
             * input, the same reason the jewellery weights above are.
             */
            'cosmetic_shade_id' => $this->cosmetic_shade_id,
            'pao_months' => $this->pao_months === null ? null : (int) $this->pao_months,
            'cosmetic_shade' => $this->whenLoaded('cosmeticShade', fn () => [
                'id' => $this->cosmeticShade->id,
                'code' => $this->cosmeticShade->code,
                'name' => $this->cosmeticShade->name,
                'display_name' => $this->cosmeticShade->displayName(),
                'descriptor' => $this->cosmeticShade->descriptor(),
                'hex' => $this->cosmeticShade->hex,
            ]),
            /**
             * Books: what the title is, as opposed to what this shop calls it.
             * Ids and plain facts always, so the product editor can round-trip
             * them; the publisher and language names only when the relations have
             * been loaded, so a workspace without the vertical pays nothing for
             * two joins it will never read.
             *
             * `book_printed_price` is not a duplicate of `selling_price`: it is
             * the price the publisher printed on the cover, which the customer
             * can also read, and the shop's margin is worked out against it.
             */
            'book_isbn13' => $this->book_isbn13,
            'book_isbn10' => $this->book_isbn10,
            'book_publisher_id' => $this->book_publisher_id,
            'book_language_id' => $this->book_language_id,
            'book_binding' => $this->book_binding,
            'book_edition' => $this->book_edition,
            'book_pages' => $this->book_pages,
            'book_published_on' => $this->book_published_on?->toDateString(),
            'book_series' => $this->book_series,
            'book_volume' => $this->book_volume,
            // Cast out of its decimal cast for the same reason the jewellery
            // weights are: the product editor binds it to a numeric input, which
            // refuses the string a decimal cast hands back.
            'book_printed_price' => $this->book_printed_price === null ? null : (float) $this->book_printed_price,
            'book_publisher' => $this->whenLoaded('bookPublisher', fn () => [
                'id' => $this->bookPublisher->id,
                'name' => $this->bookPublisher->displayName(),
                'trade_discount_percent' => $this->bookPublisher->trade_discount_percent,
                'is_returnable' => (bool) $this->bookPublisher->is_returnable,
            ]),
            'book_language' => $this->whenLoaded('bookLanguage', fn () => [
                'id' => $this->bookLanguage->id,
                'label' => $this->bookLanguage->label,
                'display' => $this->bookLanguage->display(),
            ]),
            'is_active' => $this->is_active,
            'total_stock' => $this->when(
                $this->relationLoaded('stocks'),
                fn () => (float) $this->stocks->sum('quantity')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
