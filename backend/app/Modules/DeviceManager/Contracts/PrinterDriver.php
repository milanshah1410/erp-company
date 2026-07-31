<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Contracts;

use App\Modules\DeviceManager\Data\PrintJob;
use App\Modules\DeviceManager\Data\PrintResult;

/** Thermal receipt printers and label printers. */
interface PrinterDriver extends DeviceDriver
{
    /** Page language this adapter emits: escpos | zpl | tspl. */
    public function language(): string;

    /**
     * Compile a job into the printer's page language without sending it.
     * Pure and side-effect free — this is what makes print output testable.
     */
    public function compile(PrintJob $job): string;

    /** Compile and send. In simulator mode the payload is returned, not sent. */
    public function print(PrintJob $job): PrintResult;

    /** Kick the cash drawer, for printers wired to one (POS §13). */
    public function openDrawer(): PrintResult;
}
