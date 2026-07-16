<?php

declare(strict_types=1);

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Minimal collector: reads an Excel/CSV sheet into heading-keyed rows so the
 * generic DataImportService can map them onto any module. Headers are
 * snake_cased by maatwebsite (e.g. "Company Name" → company_name).
 */
class RowsImport implements ToArray, WithHeadingRow
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    public function array(array $rows): void
    {
        $this->rows = $rows;
    }
}
