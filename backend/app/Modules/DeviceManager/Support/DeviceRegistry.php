<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Support;

use App\Modules\DeviceManager\Barcode\DatalogicBarcodeDriver;
use App\Modules\DeviceManager\Barcode\GenericHidBarcodeDriver;
use App\Modules\DeviceManager\Barcode\HoneywellBarcodeDriver;
use App\Modules\DeviceManager\Barcode\TvsBarcodeDriver;
use App\Modules\DeviceManager\Barcode\ZebraBarcodeDriver;
use App\Modules\DeviceManager\Biometric\EsslBiometricDriver;
use App\Modules\DeviceManager\Biometric\MantraBiometricDriver;
use App\Modules\DeviceManager\Biometric\RealtimeBiometricDriver;
use App\Modules\DeviceManager\Biometric\ZktecoBiometricDriver;
use App\Modules\DeviceManager\Printer\EscPosPrinterDriver;
use App\Modules\DeviceManager\Printer\HoneywellLabelPrinterDriver;
use App\Modules\DeviceManager\Printer\TscLabelPrinterDriver;
use App\Modules\DeviceManager\Printer\TvsThermalPrinterDriver;
use App\Modules\DeviceManager\Printer\ZebraLabelPrinterDriver;
use App\Modules\DeviceManager\Rfid\GenericRfidDriver;
use App\Modules\DeviceManager\Rfid\HoneywellRfidDriver;
use App\Modules\DeviceManager\Rfid\ImpinjRfidDriver;
use App\Modules\DeviceManager\Rfid\ZebraRfidDriver;
use App\Modules\DeviceManager\WeightScale\AveryWeightScaleDriver;
use App\Modules\DeviceManager\WeightScale\ContechWeightScaleDriver;
use App\Modules\DeviceManager\WeightScale\EssaeWeightScaleDriver;
use App\Modules\DeviceManager\WeightScale\GenericSerialWeightScaleDriver;

/**
 * The Device Manager plugin catalogue — the single source of truth for what
 * hardware the ERP supports.
 *
 * For every device class it lists the brand adapters, the class that implements
 * each, the configuration fields the screen must collect, and which actions the
 * adapter supports. The configuration dialog, the simulator panel and the API
 * validation are all rendered/derived from this one structure, so adding a brand
 * is: write the adapter, add a row here. Nothing else changes.
 *
 * `agent: true` on a field set means the adapter reaches the hardware through
 * the local Device Agent (see DeviceAgentClient) — unavoidable for USB/serial
 * gear, and precisely why Simulator mode exists.
 */
class DeviceRegistry
{
    /** Device classes, in the order the UI shows them. */
    public const TYPES = [
        'barcode' => [
            'label' => 'Barcode / QR Scanner',
            'icon' => 'pi pi-qrcode',
            'description' => 'Counter and warehouse scanners feeding POS, GRN, stock and gate screens.',
            'actions' => ['decode', 'poll'],
            'simulate' => 'scan',
            'simulate_label' => 'Mock Barcode Scan',
        ],
        'printer' => [
            'label' => 'Printer',
            'icon' => 'pi pi-print',
            'description' => 'Thermal receipt printers and barcode/price-tag label printers.',
            'actions' => ['print', 'open_drawer'],
            'simulate' => 'print',
            'simulate_label' => 'Print Preview',
        ],
        'weight_scale' => [
            'label' => 'Weighing Scale',
            'icon' => 'pi pi-sliders-h',
            'description' => 'Counter scales and weighbridges streaming a live weight frame.',
            'actions' => ['read', 'tare'],
            'simulate' => 'weight',
            'simulate_label' => 'Fake Weight Reading',
        ],
        'rfid' => [
            'label' => 'RFID Reader',
            'icon' => 'pi pi-wifi',
            'description' => 'UHF readers for gate passes, carton verification and bulk stock counts.',
            'actions' => ['inventory'],
            'simulate' => 'tags',
            'simulate_label' => 'Virtual Tag Read',
        ],
        'biometric' => [
            'label' => 'Biometric Terminal',
            'icon' => 'pi pi-id-card',
            'description' => 'Fingerprint and face attendance terminals feeding HR attendance.',
            'actions' => ['pull_punches'],
            'simulate' => 'punch',
            'simulate_label' => 'Virtual Fingerprint Event',
        ],
    ];

    /** Reusable field definitions so every agent-backed adapter asks the same way. */
    private static function agentFields(): array
    {
        return [
            ['key' => 'agent_url', 'label' => 'Device Agent URL', 'type' => 'text', 'required' => true, 'default' => 'http://localhost:8181/device', 'help' => 'The local bridge running on the counter PC. Not needed in Simulator mode.'],
        ];
    }

    private static function serialFields(string $defaultBaud = '9600'): array
    {
        return [
            ['key' => 'com_port', 'label' => 'Serial port', 'type' => 'text', 'required' => false, 'default' => 'COM3', 'help' => 'COM3 on Windows, /dev/ttyUSB0 on Linux.'],
            ['key' => 'baud_rate', 'label' => 'Baud rate', 'type' => 'select', 'required' => false, 'default' => $defaultBaud, 'options' => ['1200', '2400', '4800', '9600', '19200', '38400', '57600', '115200']],
        ];
    }

    /**
     * type => driver key => adapter metadata.
     *
     * @return array<string,array<string,array{
     *   label:string, class:class-string, models:string,
     *   settings:array<int,array<string,mixed>>,
     *   credentials:array<int,array<string,mixed>>,
     *   help:string
     * }>>
     */
    public static function drivers(): array
    {
        return [
            // ---------------------------------------------------------------
            // Barcode / QR scanners
            // ---------------------------------------------------------------
            'barcode' => [
                'generic_hid' => [
                    'label' => 'Generic HID (Keyboard Wedge)',
                    'class' => GenericHidBarcodeDriver::class,
                    'models' => 'Any USB scanner that types the code',
                    'settings' => [
                        ['key' => 'prefix', 'label' => 'Prefix to strip', 'type' => 'text', 'required' => false],
                        ['key' => 'suffix', 'label' => 'Suffix to strip', 'type' => 'text', 'required' => false, 'default' => '\r'],
                        ['key' => 'min_length', 'label' => 'Minimum code length', 'type' => 'number', 'required' => false, 'default' => 4],
                    ],
                    'credentials' => [],
                    'help' => 'No agent needed: the scanner types into the focused field and the browser posts the frame here for cleaning.',
                ],
                'tvs' => [
                    'label' => 'TVS Electronics',
                    'class' => TvsBarcodeDriver::class,
                    'models' => 'BS-L100, BS-i202, BSC-101',
                    'settings' => array_merge(self::agentFields(), self::serialFields(), [
                        ['key' => 'interface', 'label' => 'Interface', 'type' => 'select', 'required' => false, 'default' => 'hid', 'options' => ['hid', 'serial']],
                        ['key' => 'prefix', 'label' => 'Programmed prefix', 'type' => 'text', 'required' => false],
                    ]),
                    'credentials' => [],
                    'help' => 'TVS units ship in HID mode; switch to serial only if the unit was reprogrammed with the TVS config barcodes.',
                ],
                'honeywell' => [
                    'label' => 'Honeywell',
                    'class' => HoneywellBarcodeDriver::class,
                    'models' => 'Voyager 1250g, Xenon 1900, Orbit 7190g',
                    'settings' => array_merge(self::agentFields(), self::serialFields('115200'), [
                        ['key' => 'aim_identifiers', 'label' => 'Scanner sends AIM symbology IDs', 'type' => 'checkbox', 'required' => false, 'default' => true, 'help' => 'Honeywell prefixes ]E0 / ]C1 / ]Q1 when "Transmit Code ID" is on.'],
                    ]),
                    'credentials' => [],
                    'help' => 'Symbology is read from the AIM identifier when enabled, so the ERP knows an EAN-13 from a QR without guessing.',
                ],
                'zebra' => [
                    'label' => 'Zebra',
                    'class' => ZebraBarcodeDriver::class,
                    'models' => 'DS2208, DS8178, LI4278',
                    'settings' => array_merge(self::agentFields(), [
                        ['key' => 'cradle_host' , 'label' => 'Cradle host / IP (cordless)', 'type' => 'text', 'required' => false],
                        ['key' => 'code_id', 'label' => 'Code ID transmission', 'type' => 'select', 'required' => false, 'default' => 'symbol', 'options' => ['none', 'symbol', 'aim']],
                    ]),
                    'credentials' => [],
                    'help' => 'Zebra scanners transmit a single-letter Symbol Code ID (A = UPC/EAN, F = Code 128, s = QR) unless configured otherwise.',
                ],
                'datalogic' => [
                    'label' => 'Datalogic',
                    'class' => DatalogicBarcodeDriver::class,
                    'models' => 'QuickScan QW2100, Gryphon GD4500',
                    'settings' => array_merge(self::agentFields(), self::serialFields(), [
                        ['key' => 'strip_stx_etx', 'label' => 'Frame wrapped in STX/ETX', 'type' => 'checkbox', 'required' => false, 'default' => true],
                    ]),
                    'credentials' => [],
                    'help' => 'Datalogic RS-232 mode wraps the payload in STX … ETX; USB-COM mode usually does not.',
                ],
            ],

            // ---------------------------------------------------------------
            // Printers
            // ---------------------------------------------------------------
            'printer' => [
                'escpos_generic' => [
                    'label' => 'Generic Thermal (ESC/POS)',
                    'class' => EscPosPrinterDriver::class,
                    'models' => 'Epson TM-T82, Rugtek, most 58/80 mm printers',
                    'settings' => array_merge(self::agentFields(), [
                        ['key' => 'paper_width', 'label' => 'Paper width (mm)', 'type' => 'select', 'required' => false, 'default' => '80', 'options' => ['58', '80']],
                        ['key' => 'codepage', 'label' => 'Code page', 'type' => 'select', 'required' => false, 'default' => 'cp437', 'options' => ['cp437', 'cp850', 'utf8']],
                        ['key' => 'cut_paper', 'label' => 'Cut paper after receipt', 'type' => 'checkbox', 'required' => false, 'default' => true],
                        ['key' => 'drawer_pin', 'label' => 'Cash-drawer pin', 'type' => 'select', 'required' => false, 'default' => '0', 'options' => ['0', '1']],
                    ]),
                    'credentials' => [],
                    'help' => 'The ESC/POS baseline: receipts, cut and drawer kick. Use this unless the brand needs its own init sequence.',
                ],
                'tvs' => [
                    'label' => 'TVS Electronics Thermal',
                    'class' => TvsThermalPrinterDriver::class,
                    'models' => 'RP 3160 Gold, RP 3200, RP 45 Shoppe',
                    'settings' => array_merge(self::agentFields(), [
                        ['key' => 'paper_width', 'label' => 'Paper width (mm)', 'type' => 'select', 'required' => false, 'default' => '80', 'options' => ['58', '80']],
                        ['key' => 'print_speed', 'label' => 'Print speed', 'type' => 'select', 'required' => false, 'default' => 'normal', 'options' => ['normal', 'fast']],
                        ['key' => 'drawer_pin', 'label' => 'Cash-drawer pin', 'type' => 'select', 'required' => false, 'default' => '0', 'options' => ['0', '1']],
                    ]),
                    'credentials' => [],
                    'help' => 'ESC/POS with the TVS init block and its longer drawer pulse, which generic timings sometimes fail to trip.',
                ],
                'zebra_zpl' => [
                    'label' => 'Zebra Label (ZPL II)',
                    'class' => ZebraLabelPrinterDriver::class,
                    'models' => 'ZD220, ZD230, GK420t, ZT411',
                    'settings' => array_merge(self::agentFields(), [
                        ['key' => 'dpi', 'label' => 'Resolution (dpi)', 'type' => 'select', 'required' => false, 'default' => '203', 'options' => ['203', '300', '600']],
                        ['key' => 'label_width_mm', 'label' => 'Label width (mm)', 'type' => 'number', 'required' => false, 'default' => 50],
                        ['key' => 'label_height_mm', 'label' => 'Label height (mm)', 'type' => 'number', 'required' => false, 'default' => 25],
                        ['key' => 'darkness', 'label' => 'Darkness (0-30)', 'type' => 'number', 'required' => false, 'default' => 10],
                        ['key' => 'tcp_host', 'label' => 'Network printer IP (optional)', 'type' => 'text', 'required' => false, 'help' => 'Set to print straight to port 9100 instead of through the agent.'],
                    ]),
                    'credentials' => [],
                    'help' => 'Emits ZPL II — ^XA/^FO/^BC — so label geometry is driven by the dpi and label size set here.',
                ],
                'tsc_tspl' => [
                    'label' => 'TSC Label (TSPL)',
                    'class' => TscLabelPrinterDriver::class,
                    'models' => 'TE244, TTP-244 Pro, TDP-225',
                    'settings' => array_merge(self::agentFields(), [
                        ['key' => 'dpi', 'label' => 'Resolution (dpi)', 'type' => 'select', 'required' => false, 'default' => '203', 'options' => ['203', '300']],
                        ['key' => 'label_width_mm', 'label' => 'Label width (mm)', 'type' => 'number', 'required' => false, 'default' => 50],
                        ['key' => 'label_height_mm', 'label' => 'Label height (mm)', 'type' => 'number', 'required' => false, 'default' => 25],
                        ['key' => 'gap_mm', 'label' => 'Gap between labels (mm)', 'type' => 'number', 'required' => false, 'default' => 2],
                        ['key' => 'density', 'label' => 'Density (0-15)', 'type' => 'number', 'required' => false, 'default' => 8],
                    ]),
                    'credentials' => [],
                    'help' => 'Emits TSPL — SIZE/GAP/BARCODE/PRINT. The gap setting must match the physical label stock or the printer mis-feeds.',
                ],
                'honeywell_pc42' => [
                    'label' => 'Honeywell Label',
                    'class' => HoneywellLabelPrinterDriver::class,
                    'models' => 'PC42t, PC42d, PM42',
                    'settings' => array_merge(self::agentFields(), [
                        ['key' => 'dpi', 'label' => 'Resolution (dpi)', 'type' => 'select', 'required' => false, 'default' => '203', 'options' => ['203', '300']],
                        ['key' => 'label_width_mm', 'label' => 'Label width (mm)', 'type' => 'number', 'required' => false, 'default' => 50],
                        ['key' => 'label_height_mm', 'label' => 'Label height (mm)', 'type' => 'number', 'required' => false, 'default' => 25],
                        ['key' => 'darkness', 'label' => 'Darkness (0-30)', 'type' => 'number', 'required' => false, 'default' => 12],
                    ]),
                    'credentials' => [],
                    'help' => 'PC42/PM42 units run in ZPL-II emulation from the factory, so this reuses the ZPL compiler with Honeywell defaults.',
                ],
            ],

            // ---------------------------------------------------------------
            // Weighing scales
            // ---------------------------------------------------------------
            'weight_scale' => [
                'essae' => [
                    'label' => 'Essae-Teraoka',
                    'class' => EssaeWeightScaleDriver::class,
                    'models' => 'DS-215, DS-852, SI-810',
                    'settings' => array_merge(self::agentFields(), self::serialFields(), [
                        ['key' => 'unit', 'label' => 'Unit', 'type' => 'select', 'required' => false, 'default' => 'kg', 'options' => ['kg', 'g']],
                        ['key' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'required' => false, 'default' => 30],
                    ]),
                    'credentials' => [],
                    'help' => 'Reads the Toledo-style continuous frame — "ST,GS,+  12.345kg" — where ST/US is stability and GS/NT gross vs net.',
                ],
                'avery' => [
                    'label' => 'Avery Weigh-Tronix',
                    'class' => AveryWeightScaleDriver::class,
                    'models' => 'ZM201, ZM303, weighbridge indicators',
                    'settings' => array_merge(self::agentFields(), self::serialFields('9600'), [
                        ['key' => 'unit', 'label' => 'Unit', 'type' => 'select', 'required' => false, 'default' => 'kg', 'options' => ['kg', 't']],
                        ['key' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'required' => false, 'default' => 60000],
                    ]),
                    'credentials' => [],
                    'help' => 'Parses the ZM series default string — STX, sign, 7-digit weight, unit, then G/N and a stability flag.',
                ],
                'contech' => [
                    'label' => 'Contech',
                    'class' => ContechWeightScaleDriver::class,
                    'models' => 'CI-201, CH Series, TS Series',
                    'settings' => array_merge(self::agentFields(), self::serialFields(), [
                        ['key' => 'unit', 'label' => 'Unit', 'type' => 'select', 'required' => false, 'default' => 'kg', 'options' => ['kg', 'g']],
                        ['key' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'required' => false, 'default' => 50],
                    ]),
                    'credentials' => [],
                    'help' => 'Signed fixed-width frame — "+0012.345 kg" — with no stability flag, so readings are only trusted after two identical samples.',
                ],
                'generic_serial' => [
                    'label' => 'Generic Serial ASCII',
                    'class' => GenericSerialWeightScaleDriver::class,
                    'models' => 'Any indicator streaming a numeric weight',
                    'settings' => array_merge(self::agentFields(), self::serialFields(), [
                        ['key' => 'unit', 'label' => 'Unit', 'type' => 'select', 'required' => false, 'default' => 'kg', 'options' => ['kg', 'g', 't', 'lb']],
                        ['key' => 'decimals', 'label' => 'Decimal places', 'type' => 'number', 'required' => false, 'default' => 3],
                        ['key' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'required' => false, 'default' => 100],
                    ]),
                    'credentials' => [],
                    'help' => 'Last-resort adapter: pulls the first signed decimal number out of whatever the indicator sends.',
                ],
            ],

            // ---------------------------------------------------------------
            // RFID readers
            // ---------------------------------------------------------------
            'rfid' => [
                'zebra_fx' => [
                    'label' => 'Zebra FX Series',
                    'class' => ZebraRfidDriver::class,
                    'models' => 'FX7500, FX9600, RFD40 handheld',
                    'settings' => [
                        ['key' => 'reader_host', 'label' => 'Reader IP / host', 'type' => 'text', 'required' => true],
                        ['key' => 'antennas', 'label' => 'Antenna ports in use', 'type' => 'text', 'required' => false, 'default' => '1,2', 'help' => 'Comma-separated, e.g. 1,2,3,4.'],
                        ['key' => 'power_dbm', 'label' => 'Transmit power (dBm)', 'type' => 'number', 'required' => false, 'default' => 27],
                        ['key' => 'rssi_floor', 'label' => 'Ignore reads weaker than (dBm)', 'type' => 'number', 'required' => false, 'default' => -70],
                    ],
                    'credentials' => [
                        ['key' => 'api_token', 'label' => 'Reader API token', 'type' => 'password', 'required' => false],
                    ],
                    'help' => 'Talks to the FX reader\'s REST/IoT connector and reads the tagList report (epc, antennaID, peakRssi, tagSeenCount).',
                ],
                'impinj' => [
                    'label' => 'Impinj Speedway',
                    'class' => ImpinjRfidDriver::class,
                    'models' => 'Speedway R420, R700',
                    'settings' => [
                        ['key' => 'reader_host', 'label' => 'Reader IP / host', 'type' => 'text', 'required' => true],
                        ['key' => 'antennas', 'label' => 'Antenna ports in use', 'type' => 'text', 'required' => false, 'default' => '1,2'],
                        ['key' => 'power_dbm', 'label' => 'Transmit power (dBm)', 'type' => 'number', 'required' => false, 'default' => 30],
                        ['key' => 'rssi_floor', 'label' => 'Ignore reads weaker than (dBm)', 'type' => 'number', 'required' => false, 'default' => -72],
                    ],
                    'credentials' => [
                        ['key' => 'api_token', 'label' => 'IoT interface token', 'type' => 'password', 'required' => false],
                    ],
                    'help' => 'Reads the Speedway IoT interface tag_reads stream (epc, antennaPort, peakRssi).',
                ],
                'honeywell_if2' => [
                    'label' => 'Honeywell IF Series',
                    'class' => HoneywellRfidDriver::class,
                    'models' => 'IF2, IF61, IH45 handheld',
                    'settings' => [
                        ['key' => 'reader_host', 'label' => 'Reader IP / host', 'type' => 'text', 'required' => true],
                        ['key' => 'antennas', 'label' => 'Antenna ports in use', 'type' => 'text', 'required' => false, 'default' => '1'],
                        ['key' => 'rssi_floor', 'label' => 'Ignore reads weaker than (dBm)', 'type' => 'number', 'required' => false, 'default' => -68],
                    ],
                    'credentials' => [
                        ['key' => 'api_token', 'label' => 'Reader password', 'type' => 'password', 'required' => false],
                    ],
                    'help' => 'Reads the IF series basic-reading-mode report, whose rows use short keys (id, ant, rssi).',
                ],
                'generic_rfid' => [
                    'label' => 'Generic Reader (via agent)',
                    'class' => GenericRfidDriver::class,
                    'models' => 'Any reader the Device Agent can drive',
                    'settings' => array_merge(self::agentFields(), [
                        ['key' => 'rssi_floor', 'label' => 'Ignore reads weaker than (dBm)', 'type' => 'number', 'required' => false, 'default' => -75],
                    ]),
                    'credentials' => [],
                    'help' => 'Expects the agent to return {tags:[{epc, antenna, rssi, count}]} — the escape hatch for unlisted readers.',
                ],
            ],

            // ---------------------------------------------------------------
            // Biometric terminals
            // ---------------------------------------------------------------
            'biometric' => [
                'zkteco' => [
                    'label' => 'ZKTeco',
                    'class' => ZktecoBiometricDriver::class,
                    'models' => 'K40, F18, iClock 680, MB460',
                    'settings' => [
                        ['key' => 'device_host', 'label' => 'Device IP / host', 'type' => 'text', 'required' => true],
                        ['key' => 'device_port', 'label' => 'Port', 'type' => 'number', 'required' => false, 'default' => 4370],
                        ['key' => 'bridge_url', 'label' => 'Bridge / ADMS URL', 'type' => 'text', 'required' => false, 'help' => 'The service that speaks the ZK protocol on the LAN and exposes attendance logs as JSON.'],
                        ['key' => 'in_status', 'label' => 'Status code meaning "IN"', 'type' => 'text', 'required' => false, 'default' => '0'],
                    ],
                    'credentials' => [
                        ['key' => 'comm_key', 'label' => 'Comm key (device password)', 'type' => 'password', 'required' => false],
                    ],
                    'help' => 'Attendance rows arrive as {pin, time, status, verify}; status 0/4 is check-in and 1/5 check-out on stock firmware.',
                ],
                'essl' => [
                    'label' => 'eSSL',
                    'class' => EsslBiometricDriver::class,
                    'models' => 'X990, K90, Biomax N-BM10',
                    'settings' => [
                        ['key' => 'device_host', 'label' => 'Device IP / host', 'type' => 'text', 'required' => true],
                        ['key' => 'device_port', 'label' => 'Port', 'type' => 'number', 'required' => false, 'default' => 4370],
                        ['key' => 'bridge_url', 'label' => 'eTimeTrack / ADMS URL', 'type' => 'text', 'required' => false],
                        ['key' => 'serial_no', 'label' => 'Device serial number', 'type' => 'text', 'required' => false, 'help' => 'eSSL push payloads identify the terminal by serial, not IP.'],
                    ],
                    'credentials' => [
                        ['key' => 'comm_key', 'label' => 'Comm key', 'type' => 'password', 'required' => false],
                        ['key' => 'api_token', 'label' => 'eTimeTrack API token', 'type' => 'password', 'required' => false],
                    ],
                    'help' => 'Maps eSSL\'s log shape (EmployeeCode, PunchTime, InOutMode) where InOutMode 1 = in and 2 = out.',
                ],
                'mantra' => [
                    'label' => 'Mantra',
                    'class' => MantraBiometricDriver::class,
                    'models' => 'MFSTAB, mfs100 terminal, MIRA100',
                    'settings' => [
                        ['key' => 'device_host', 'label' => 'Device IP / host', 'type' => 'text', 'required' => false],
                        ['key' => 'bridge_url', 'label' => 'Mantra service URL', 'type' => 'text', 'required' => false],
                    ],
                    'credentials' => [
                        ['key' => 'api_token', 'label' => 'Service token', 'type' => 'password', 'required' => false],
                    ],
                    'help' => 'Mantra terminals post camelCase logs (userId, punchDateTime, direction) from their local service.',
                ],
                'realtime' => [
                    'label' => 'Realtime',
                    'class' => RealtimeBiometricDriver::class,
                    'models' => 'T52, T5, Eco S C121',
                    'settings' => [
                        ['key' => 'device_host', 'label' => 'Device IP / host', 'type' => 'text', 'required' => true],
                        ['key' => 'device_port', 'label' => 'Port', 'type' => 'number', 'required' => false, 'default' => 5005],
                        ['key' => 'bridge_url', 'label' => 'Bridge URL', 'type' => 'text', 'required' => false],
                    ],
                    'credentials' => [
                        ['key' => 'comm_key', 'label' => 'Comm key', 'type' => 'password', 'required' => false],
                    ],
                    'help' => 'Realtime exports use UserID / LogDate with a C1 in-out flag.',
                ],
            ],
        ];
    }

    /** @return string[] */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    public static function hasType(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    /** All driver keys for a type. @return string[] */
    public static function driverKeys(string $type): array
    {
        return array_keys(self::drivers()[$type] ?? []);
    }

    public static function driver(string $type, string $driver): ?array
    {
        return self::drivers()[$type][$driver] ?? null;
    }

    public static function has(string $type, string $driver): bool
    {
        return self::driver($type, $driver) !== null;
    }

    /** Every valid "type:driver" pair, for request validation. @return string[] */
    public static function pairs(): array
    {
        $pairs = [];
        foreach (self::drivers() as $type => $drivers) {
            foreach (array_keys($drivers) as $key) {
                $pairs[] = "{$type}:{$key}";
            }
        }

        return $pairs;
    }

    /**
     * The catalogue as the configuration screen consumes it: types with their
     * brands and field definitions, minus the PHP class names.
     */
    public static function forFrontend(): array
    {
        $drivers = self::drivers();
        $out = [];

        foreach (self::TYPES as $type => $meta) {
            $brands = [];
            foreach ($drivers[$type] ?? [] as $key => $driver) {
                $brands[] = [
                    'driver' => $key,
                    'label' => $driver['label'],
                    'models' => $driver['models'],
                    'settings' => $driver['settings'],
                    'credentials' => $driver['credentials'],
                    'help' => $driver['help'],
                ];
            }

            $out[] = [
                'type' => $type,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'description' => $meta['description'],
                'actions' => $meta['actions'],
                'simulate' => $meta['simulate'],
                'simulate_label' => $meta['simulate_label'],
                'drivers' => $brands,
            ];
        }

        return $out;
    }

    /** Default settings for a newly picked brand, straight from the field defaults. */
    public static function defaultSettings(string $type, string $driver): array
    {
        $meta = self::driver($type, $driver);
        $defaults = [];
        foreach ($meta['settings'] ?? [] as $field) {
            if (array_key_exists('default', $field)) {
                $defaults[$field['key']] = $field['default'];
            }
        }

        return $defaults;
    }
}
