<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Printer;

use App\Modules\DeviceManager\Data\PrintResult;

/**
 * TVS Electronics thermal printers (RP 3160 Gold, RP 3200, RP 45 Shoppe).
 *
 * ESC/POS-compatible, with two practical differences worth their own adapter:
 * the RP series wants its print-density/speed byte set after reset or the first
 * receipt of the day prints faint, and its drawer solenoid needs a noticeably
 * longer pulse than the generic 50 ms — the usual reason a drawer "does not open"
 * on a generic driver.
 */
class TvsThermalPrinterDriver extends EscPosPrinterDriver
{
    protected function initSequence(): string
    {
        $speed = ((string) $this->setting('print_speed', 'normal')) === 'fast' ? "\x02" : "\x01";

        return parent::initSequence()
            . self::GS . '(' . 'K' . "\x02\x00\x31" . $speed   // print speed
            . self::ESC . 'c' . '5' . "\x01";                  // panel buttons off while printing
    }

    public function openDrawer(): PrintResult
    {
        $pin = ((string) $this->setting('drawer_pin', '0')) === '1' ? "\x01" : "\x00";
        // 100 ms on / 200 ms off — the RP series' documented drawer timing.
        $payload = self::ESC . 'p' . $pin . "\x32" . "\x64";

        if ($this->simulated()) {
            return new PrintResult(
                ok: true,
                language: $this->language(),
                preview: $this->previewOf($payload),
                bytes: strlen($payload),
                message: 'Simulated TVS drawer kick (100/200 ms pulse) — nothing sent.',
                simulated: true,
            );
        }

        $response = $this->agent()->sendRaw($this->device(), 'drawer', $payload, ['pin' => $pin === "\x01" ? 1 : 0]);

        return new PrintResult(
            ok: (bool) ($response['ok'] ?? true),
            language: $this->language(),
            preview: $this->previewOf($payload),
            bytes: strlen($payload),
            message: $response['message'] ?? 'Drawer pulse sent.',
        );
    }
}
