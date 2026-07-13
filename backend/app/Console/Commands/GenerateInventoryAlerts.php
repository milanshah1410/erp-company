<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\InventoryAlert;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Notifications\InventoryAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class GenerateInventoryAlerts extends Command
{
    protected $signature = 'inventory:generate-alerts {--expiry-warning-days=30}';

    protected $description = 'Scan batches for expiry and warehouse stock for low-stock, opening/updating in-app alerts';

    public function handle(): int
    {
        $warningDays = (int) $this->option('expiry-warning-days');
        $expiryCount = $this->generateExpiryAlerts($warningDays);
        $lowStockCount = $this->generateLowStockAlerts();

        $this->info("Generated/updated {$expiryCount} expiry alert(s) and {$lowStockCount} low-stock alert(s).");

        return self::SUCCESS;
    }

    private function generateExpiryAlerts(int $warningDays): int
    {
        $count = 0;
        $horizon = now()->addDays($warningDays)->toDateString();

        $batches = Batch::query()
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $horizon)
            ->get();

        foreach ($batches as $batch) {
            $isExpired = $batch->expiry_date->isPast();

            $alert = InventoryAlert::updateOrCreate(
                [
                    'type' => 'expiry',
                    'product_id' => $batch->product_id,
                    'warehouse_id' => $batch->warehouse_id,
                    'batch_id' => $batch->id,
                    'status' => 'open',
                ],
                [
                    'variant_id' => $batch->variant_id,
                    'severity' => $isExpired ? 'critical' : 'warning',
                    'message' => $isExpired
                        ? "Batch {$batch->batch_number} expired on {$batch->expiry_date->toDateString()} ({$batch->quantity} units on hand)."
                        : "Batch {$batch->batch_number} expires on {$batch->expiry_date->toDateString()} ({$batch->quantity} units on hand).",
                ],
            );
            if ($alert->wasRecentlyCreated) {
                $this->notifyAlertRecipients($alert);
            }
            $count++;
        }

        return $count;
    }

    private function generateLowStockAlerts(): int
    {
        $count = 0;

        // reorder_level lives on products, quantity on warehouse_stocks —
        // filter in PHP rather than a cross-table whereColumn().
        $lowStocks = WarehouseStock::query()
            ->with('product:id,name,reorder_level')
            ->get()
            ->filter(fn (WarehouseStock $s) => $s->product
                && (float) $s->product->reorder_level > 0
                && (float) $s->quantity <= (float) $s->product->reorder_level);

        foreach ($lowStocks as $stock) {
            $alert = InventoryAlert::updateOrCreate(
                [
                    'type' => 'low_stock',
                    'product_id' => $stock->product_id,
                    'warehouse_id' => $stock->warehouse_id,
                    'batch_id' => null,
                    'status' => 'open',
                ],
                [
                    'variant_id' => $stock->variant_id,
                    'severity' => (float) $stock->quantity <= 0 ? 'critical' : 'warning',
                    'message' => "{$stock->product->name} is at {$stock->quantity} units, at or below the reorder level of {$stock->product->reorder_level}.",
                ],
            );
            if ($alert->wasRecentlyCreated) {
                $this->notifyAlertRecipients($alert);
            }
            $count++;
        }

        return $count;
    }

    /** Low-stock/expiry alerts are relevant to warehouse operators and admins. */
    private function notifyAlertRecipients(InventoryAlert $alert): void
    {
        $recipients = User::role(['admin', 'super-admin', 'warehouse-manager'])->get();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new InventoryAlertNotification($alert));
        }
    }
}
