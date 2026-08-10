<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Debit Note (retail base §3). Promotes the free-text
 * `stock_returns.debit_note_number` — which a clerk typed by hand, or left
 * blank — into a numbered document the shop can print and post to its supplier.
 *
 * Deliberately *not* a new table. A debit note is not a second event: the goods
 * going back to the vendor and the note claiming credit for them are one act,
 * and the return already carries the lines, the rates, the supplier and the
 * GRN it came off. This mirrors how the sales side already works — a
 * SalesReturn *is* the credit note and carries `credit_note_number` — so the
 * two halves of the book read the same way.
 *
 * What is genuinely new is the issue *step*. The number is minted on demand
 * rather than at creation, because a return is often raised while the goods are
 * still being counted and only becomes a claim once the shop has decided what
 * it is asking the vendor for. Once minted it is never re-minted (the
 * controller refuses), so a supplier can never be sent two notes bearing the
 * same series number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_returns', function (Blueprint $table) {
            /**
             * The note's own date, separate from `return_date`. Goods often go
             * back on the van the same afternoon and the claim is raised at the
             * month end against that vendor's account; dating the note by the
             * day the stock left would land it in the wrong GST period.
             */
            $table->date('debit_note_date')->nullable()->after('debit_note_number');

            /**
             * One tax rate for the note, not per line. A purchase debit note is
             * a reversal of a bill the shop already holds, and the reversal is
             * claimed at the rate that bill charged — so the number is copied
             * from the supplier's invoice, not re-derived per article. Shops
             * that return mixed-rate goods raise one note per rate, which is
             * also what their vendor's accountant expects.
             */
            $table->decimal('debit_note_tax_percent', 5, 2)->default(0)->after('debit_note_date');

            // Who minted it and when — the audit trail behind a document that
            // leaves the building and asks another business for money.
            $table->timestamp('debit_note_issued_at')->nullable()->after('debit_note_tax_percent');
            $table->foreignId('debit_note_issued_by')->nullable()->after('debit_note_issued_at')
                ->constrained('users')->nullOnDelete();

            /**
             * Not unique. The column pre-dates this migration and already holds
             * whatever clerks typed into it — including blanks and duplicates —
             * and a unique index would fail to build on a live workspace. The
             * series is kept clean at the point of issue instead: the controller
             * mints from nextDebitNoteNumber() and refuses to re-issue.
             */
            $table->index(['tenant_id', 'debit_note_number'], 'stock_returns_debit_note_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_returns', function (Blueprint $table) {
            $table->dropIndex('stock_returns_debit_note_idx');
            $table->dropConstrainedForeignId('debit_note_issued_by');
            $table->dropColumn(['debit_note_date', 'debit_note_tax_percent', 'debit_note_issued_at']);
        });
    }
};
