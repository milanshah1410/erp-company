<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Renders any report's flat row data as an Excel/CSV sheet.
 */
class GenericArrayExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function __construct(
        private readonly array $rows,
        private readonly array $headings,
    ) {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }
}
