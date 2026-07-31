<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Contracts;

use App\Models\Device;
use App\Modules\DeviceManager\Data\ConnectionResult;

/**
 * The base contract every brand adapter implements, regardless of device class.
 *
 * A driver is a thin, stateless translator between one brand's protocol and the
 * ERP's normalised data objects. It never writes to the database and never logs:
 * DeviceManagerService owns persistence and the event log, so a driver stays
 * trivially unit-testable in isolation.
 */
interface DeviceDriver
{
    /** Registry key of this adapter, e.g. 'zebra_zpl'. */
    public function key(): string;

    /** Device class this adapter belongs to: barcode|printer|weight_scale|rfid|biometric. */
    public function type(): string;

    /** The configured device this instance is bound to. */
    public function device(): Device;

    /**
     * Verify the device is reachable and configured. In simulator mode this
     * answers instantly and successfully — that is the point of the mode.
     */
    public function testConnection(): ConnectionResult;
}
