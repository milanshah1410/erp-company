<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentVoucher;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Voucher;
use Illuminate\Support\Collection;

/**
 * Export the books to Tally, Busy and Zoho Books (retail base §10).
 *
 * The shape of this file is set by one fact about Indian retail: the shop keeps
 * its day-to-day accounts here, and its *chartered accountant* keeps Tally. So
 * this is a one-way bridge, and it is deliberately a one-way bridge — pulling
 * balances back in would put two systems in charge of the same numbers and there
 * is no honest way to reconcile that automatically.
 *
 * Everything is built as a normalised row set first (`collect*` below) and only
 * then written in a target's format. Adding a fourth accounting package means
 * writing one formatter, not re-deriving the books.
 *
 * The three formats are genuinely different documents, not three CSV dialects:
 *
 *  - **Tally** takes a single XML envelope holding masters *and* vouchers, in that
 *    order, because a voucher referencing a ledger Tally has never heard of is
 *    rejected outright. One file, imported once.
 *  - **Busy** takes one CSV per document type with its own column order.
 *  - **Zoho Books** takes one CSV per document type, with its own column names
 *    again, and matches contacts by name — so contacts must be imported first.
 *
 * Amounts are unsigned; direction is carried by which side of the voucher a
 * ledger sits on. That is how all three packages read a transaction, and
 * exporting signed amounts is the classic way an import lands mirror-imaged.
 */
class AccountingExportService
{
    /** What can be exported. Order matters: masters before the vouchers that cite them. */
    public const DOCUMENTS = ['contacts', 'invoices', 'bills', 'receipts', 'payments', 'journals', 'expenses'];

    public const TARGETS = ['tally', 'busy', 'zoho'];

    /**
     * Default ledger names. Tally ships with these exact names in a fresh company,
     * which is why they are the defaults — an export that invents "Sales Revenue"
     * creates a duplicate ledger next to the one the CA already uses.
     */
    private const LEDGER_SALES = 'Sales Accounts';

    private const LEDGER_PURCHASE = 'Purchase Accounts';

    private const LEDGER_TAX = 'Duties & Taxes';

    private const LEDGER_CASH = 'Cash';

    private const LEDGER_BANK = 'Bank Accounts';

    private const LEDGER_DEBTORS = 'Sundry Debtors';

    private const LEDGER_CREDITORS = 'Sundry Creditors';

    private const LEDGER_EXPENSES = 'Indirect Expenses';

    // -----------------------------------------------------------------------
    // What is there to export
    // -----------------------------------------------------------------------

    /** Row counts per document type, so the screen can say what a run will contain. */
    public function summary(?string $from, ?string $to): array
    {
        return [
            'contacts' => Customer::query()->count() + Supplier::query()->count(),
            'invoices' => $this->dateFilter(Invoice::query(), 'invoice_date', $from, $to)->count(),
            'bills' => $this->dateFilter(PurchaseInvoice::query(), 'invoice_date', $from, $to)->count(),
            // Both halves of each, matching what collectReceipts/collectPayments
            // actually export — a count that disagreed with the file would be the
            // most misleading number on the screen.
            'receipts' => $this->dateFilter(Payment::query(), 'payment_date', $from, $to)->count()
                + $this->dateFilter(PaymentVoucher::query()->where('direction', 'inbound'), 'payment_date', $from, $to)->count(),
            'payments' => $this->dateFilter(SupplierPayment::query(), 'payment_date', $from, $to)->count()
                + $this->dateFilter(PaymentVoucher::query()->where('direction', 'outbound'), 'payment_date', $from, $to)->count(),
            'journals' => $this->dateFilter(Voucher::query(), 'voucher_date', $from, $to)
                ->whereNotNull('debit_ledger')->whereNotNull('credit_ledger')
                ->where('debit_ledger', '!=', '')->where('credit_ledger', '!=', '')
                ->count(),
            'expenses' => $this->dateFilter(Expense::query(), 'expense_date', $from, $to)->count(),
        ];
    }

    // -----------------------------------------------------------------------
    // Normalised row sets
    // -----------------------------------------------------------------------

    /**
     * Parties, as ledger masters.
     *
     * A customer is a Sundry Debtor and a supplier a Sundry Creditor. A party that
     * is both — which happens constantly in wholesale — is exported twice under
     * one name, and that is correct: Tally will merge them into a single ledger
     * because the name is the key, and the second row only re-states the group.
     */
    public function collectContacts(): Collection
    {
        $customers = Customer::query()->get()->map(fn (Customer $c) => [
            'kind' => 'customer',
            'name' => $c->name,
            'group' => self::LEDGER_DEBTORS,
            'company' => $c->company_name,
            'gstin' => $c->gst_number,
            'phone' => $c->phone,
            'email' => $c->email,
            'address' => $this->address($c),
            'state' => $c->state,
            'pincode' => $c->pincode,
            'opening_balance' => (float) ($c->opening_balance ?? 0),
            'credit_days' => (int) ($c->payment_terms_days ?? 0),
        ]);

        $suppliers = Supplier::query()->get()->map(fn (Supplier $s) => [
            'kind' => 'supplier',
            'name' => $s->name,
            'group' => self::LEDGER_CREDITORS,
            'company' => $s->company_name,
            'gstin' => $s->gst_number,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $this->address($s),
            'state' => $s->state,
            'pincode' => $s->pincode,
            'opening_balance' => (float) ($s->opening_balance ?? 0),
            'credit_days' => (int) ($s->payment_terms_days ?? 0),
        ]);

        return $customers->concat($suppliers)->values();
    }

    /**
     * Sales invoices.
     *
     * Tax comes off the sales *order*, not the invoice: `invoices` carries only a
     * total, and the tax it contains is on the order that produced it. Splitting
     * it out matters — an invoice imported as one gross sales figure makes the
     * GST return unreconcilable, which is the whole reason a CA wants the file.
     */
    public function collectInvoices(?string $from, ?string $to): Collection
    {
        return $this->dateFilter(Invoice::query(), 'invoice_date', $from, $to)
            ->with(['customer:id,name,company_name,gst_number,state', 'salesOrder:id,tax_amount,subtotal'])
            ->orderBy('invoice_date')
            ->get()
            ->map(function (Invoice $i) {
                $tax = round((float) ($i->salesOrder->tax_amount ?? 0), 2);
                $total = round((float) $i->total, 2);

                return [
                    'number' => $i->invoice_number,
                    'date' => $i->invoice_date?->toDateString(),
                    'due_date' => $i->due_date?->toDateString(),
                    'party' => $i->customer?->name ?? 'Cash Sales',
                    'party_gstin' => $i->customer?->gst_number,
                    'party_state' => $i->customer?->state,
                    // Taxable value derived from the total rather than read from
                    // the order's subtotal, so the three figures always add up
                    // even where a discount or a round-off sat between them.
                    'taxable' => round($total - $tax, 2),
                    'tax' => $tax,
                    'total' => $total,
                    'sales_ledger' => self::LEDGER_SALES,
                    'party_ledger' => $i->customer?->name ?? 'Cash Sales',
                    'status' => $i->status,
                ];
            });
    }

    /** Purchase bills — the buying-side mirror of the above. */
    public function collectBills(?string $from, ?string $to): Collection
    {
        return $this->dateFilter(PurchaseInvoice::query(), 'invoice_date', $from, $to)
            ->with('supplier:id,name,company_name,gst_number,state')
            ->orderBy('invoice_date')
            ->get()
            ->map(fn (PurchaseInvoice $b) => [
                'number' => $b->invoice_number ?: ('PB-'.$b->id),
                'date' => $b->invoice_date?->toDateString(),
                'due_date' => $b->due_date?->toDateString(),
                'party' => $b->supplier?->name ?? 'Cash Purchase',
                'party_gstin' => $b->supplier?->gst_number,
                'party_state' => $b->supplier?->state,
                'taxable' => round((float) $b->subtotal, 2),
                'tax' => round((float) $b->tax_amount, 2),
                'total' => round((float) $b->total, 2),
                'purchase_ledger' => self::LEDGER_PURCHASE,
                'party_ledger' => $b->supplier?->name ?? 'Cash Purchase',
            ]);
    }

    /**
     * Money in.
     *
     * Two sources, for the same reason as collectPayments below: a receipt is
     * either recorded against an invoice (`payments`) or raised as a standalone
     * receipt voucher, which is what an on-account collection and a customer
     * advance are. Exporting one and not the other leaves a debtor's balance in
     * Tally disagreeing with the balance here.
     */
    public function collectReceipts(?string $from, ?string $to): Collection
    {
        $againstInvoices = $this->dateFilter(Payment::query(), 'payment_date', $from, $to)
            ->with('invoice:id,invoice_number,customer_id', 'invoice.customer:id,name')
            ->orderBy('payment_date')
            ->get()
            ->map(fn (Payment $p) => [
                'number' => 'RCPT-'.$p->id,
                'date' => $p->payment_date?->toDateString(),
                'party' => $p->invoice?->customer?->name ?? 'Cash Sales',
                'against' => $p->invoice?->invoice_number,
                'amount' => round((float) $p->amount, 2),
                'mode' => $p->mode,
                // Which ledger the money landed in. Anything that is not literal
                // cash is booked to the bank group — a card settlement and a UPI
                // credit both arrive in a bank account, not a till.
                'deposit_ledger' => $p->mode === 'cash' ? self::LEDGER_CASH : self::LEDGER_BANK,
                'narration' => $p->notes,
            ]);

        return $againstInvoices
            ->concat($this->paymentVouchers('inbound', 'Cash Sales', $from, $to))
            ->values();
    }

    /**
     * Money out.
     *
     * Two sources, deliberately unioned rather than picked between: a bill can be
     * settled from the purchase invoice itself (`supplier_payments`, which hangs
     * off the bill and carries no party of its own — the supplier comes from the
     * invoice) *or* raised as a standalone payment voucher, which is what an
     * on-account payment and an advance are. Exporting one and not the other is
     * how a supplier's balance in Tally ends up disagreeing with the balance here.
     */
    public function collectPayments(?string $from, ?string $to): Collection
    {
        $againstBills = $this->dateFilter(SupplierPayment::query(), 'payment_date', $from, $to)
            ->with('purchaseInvoice.supplier:id,name', 'purchaseInvoice:id,invoice_number,supplier_id')
            ->orderBy('payment_date')
            ->get()
            ->map(fn (SupplierPayment $p) => [
                'number' => 'SPMT-'.$p->id,
                'date' => $p->payment_date?->toDateString(),
                'party' => $p->purchaseInvoice?->supplier?->name ?? 'Cash Purchase',
                'against' => $p->purchaseInvoice?->invoice_number,
                'amount' => round((float) $p->amount, 2),
                'mode' => $p->mode ?: 'cash',
                'deposit_ledger' => ($p->mode ?: 'cash') === 'cash' ? self::LEDGER_CASH : self::LEDGER_BANK,
                'narration' => $p->notes,
            ]);

        return $againstBills
            ->concat($this->paymentVouchers('outbound', 'Cash Purchase', $from, $to))
            ->values();
    }

    /**
     * Standalone payment vouchers in one direction.
     *
     * `direction` is 'inbound' / 'outbound' — the column's own vocabulary, not
     * 'in'/'out'; one table holds both sides of the money, so getting this value
     * wrong silently exports receipts as payments.
     *
     * The party name comes off the polymorphic `party()` relation. `partyAlias()`
     * looks like the right method and is not — it returns the *type* ("customer",
     * "supplier"), which would put every voucher in the file against a ledger
     * literally named "supplier".
     */
    private function paymentVouchers(string $direction, string $fallbackParty, ?string $from, ?string $to): Collection
    {
        return $this->dateFilter(PaymentVoucher::query(), 'payment_date', $from, $to)
            ->where('direction', $direction)
            ->with('party')
            ->orderBy('payment_date')
            ->get()
            ->map(fn (PaymentVoucher $v) => [
                'number' => $v->voucher_number,
                'date' => $v->payment_date?->toDateString(),
                'party' => $v->party?->name ?: $fallbackParty,
                'against' => $v->reference_no,
                'amount' => round((float) $v->amount, 2),
                'mode' => $v->mode ?: 'cash',
                'deposit_ledger' => ($v->mode ?: 'cash') === 'cash' ? self::LEDGER_CASH : self::LEDGER_BANK,
                'narration' => $v->notes,
            ]);
    }

    /**
     * Manual vouchers.
     *
     * Only rows carrying both a debit and a credit ledger are exported. A voucher
     * with one side blank is legitimate here (this app's books derive the other
     * side from the payment mode) but it is not a journal entry any accounting
     * package can accept, and guessing the missing leg would post something the
     * shop never wrote. Skipped rows are counted and reported.
     */
    public function collectJournals(?string $from, ?string $to): Collection
    {
        return $this->dateFilter(Voucher::query(), 'voucher_date', $from, $to)
            ->orderBy('voucher_date')
            ->get()
            ->filter(fn (Voucher $v) => filled($v->debit_ledger) && filled($v->credit_ledger))
            ->map(fn (Voucher $v) => [
                'number' => $v->voucher_number,
                'date' => $v->voucher_date?->toDateString(),
                'type' => $v->voucher_type,
                'debit_ledger' => $v->debit_ledger,
                'credit_ledger' => $v->credit_ledger,
                'amount' => round((float) $v->amount, 2),
                'party' => $v->party_name,
                'reference' => $v->reference_no,
                'narration' => $v->narration,
            ])
            ->values();
    }

    /** How many manual vouchers could not be exported, and why. */
    public function unexportableJournals(?string $from, ?string $to): int
    {
        return $this->dateFilter(Voucher::query(), 'voucher_date', $from, $to)
            ->where(function ($q) {
                $q->whereNull('debit_ledger')->orWhereNull('credit_ledger')
                    ->orWhere('debit_ledger', '')->orWhere('credit_ledger', '');
            })
            ->count();
    }

    /** Expenses, as payment vouchers against an expense ledger. */
    public function collectExpenses(?string $from, ?string $to): Collection
    {
        return $this->dateFilter(Expense::query(), 'expense_date', $from, $to)
            ->with('category:id,name')
            ->orderBy('expense_date')
            ->get()
            ->map(fn (Expense $e) => [
                'number' => $e->expense_number,
                'date' => $e->expense_date?->toDateString(),
                // The category becomes the ledger name, which is how a shop's own
                // "Shop Rent" and "Electricity" end up as ledgers in Tally rather
                // than as one undifferentiated expenses lump.
                'expense_ledger' => $e->category?->name ?: self::LEDGER_EXPENSES,
                'amount' => round((float) $e->amount, 2),
                'mode' => $e->payment_method ?: 'cash',
                'paid_from' => ($e->payment_method ?: 'cash') === 'cash' ? self::LEDGER_CASH : self::LEDGER_BANK,
                'payee' => $e->payee,
                'reference' => $e->reference_no,
                'narration' => $e->description,
                'status' => $e->status,
            ]);
    }

    // -----------------------------------------------------------------------
    // Tally XML
    // -----------------------------------------------------------------------

    /**
     * One envelope, masters then vouchers.
     *
     * `$documents` decides what goes in; contacts are always emitted when any
     * voucher type is included, because Tally rejects a voucher whose party
     * ledger does not exist and the shop would have no way to tell why.
     */
    public function tallyXml(array $documents, ?string $from, ?string $to): string
    {
        $company = $this->companyName();
        $needsParties = array_intersect($documents, ['invoices', 'bills', 'receipts', 'payments']) !== [];

        $parts = [];

        if (in_array('contacts', $documents, true) || $needsParties) {
            foreach ($this->collectContacts() as $c) {
                $parts[] = $this->tallyLedger($c);
            }
        }
        if (in_array('invoices', $documents, true)) {
            foreach ($this->collectInvoices($from, $to) as $row) {
                $parts[] = $this->tallySalesVoucher($row);
            }
        }
        if (in_array('bills', $documents, true)) {
            foreach ($this->collectBills($from, $to) as $row) {
                $parts[] = $this->tallyPurchaseVoucher($row);
            }
        }
        if (in_array('receipts', $documents, true)) {
            foreach ($this->collectReceipts($from, $to) as $row) {
                $parts[] = $this->tallyReceiptVoucher($row);
            }
        }
        if (in_array('payments', $documents, true)) {
            foreach ($this->collectPayments($from, $to) as $row) {
                $parts[] = $this->tallyPaymentVoucher($row);
            }
        }
        if (in_array('journals', $documents, true)) {
            foreach ($this->collectJournals($from, $to) as $row) {
                $parts[] = $this->tallyJournalVoucher($row);
            }
        }
        if (in_array('expenses', $documents, true)) {
            foreach ($this->collectExpenses($from, $to) as $row) {
                $parts[] = $this->tallyExpenseVoucher($row);
            }
        }

        $body = implode("\n", $parts);

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <ENVELOPE>
              <HEADER>
                <TALLYREQUEST>Import Data</TALLYREQUEST>
              </HEADER>
              <BODY>
                <IMPORTDATA>
                  <REQUESTDESC>
                    <REPORTNAME>All Masters</REPORTNAME>
                    <STATICVARIABLES>
                      <SVCURRENTCOMPANY>{$this->x($company)}</SVCURRENTCOMPANY>
                    </STATICVARIABLES>
                  </REQUESTDESC>
                  <REQUESTDATA>
            {$body}
                  </REQUESTDATA>
                </IMPORTDATA>
              </BODY>
            </ENVELOPE>
            XML;
    }

    private function tallyLedger(array $c): string
    {
        return <<<XML
                    <TALLYMESSAGE xmlns:UDF="TallyUDF">
                      <LEDGER NAME="{$this->x($c['name'])}" ACTION="Create">
                        <NAME>{$this->x($c['name'])}</NAME>
                        <PARENT>{$this->x($c['group'])}</PARENT>
                        <ISBILLWISEON>Yes</ISBILLWISEON>
                        <PARTYGSTIN>{$this->x($c['gstin'] ?? '')}</PARTYGSTIN>
                        <LEDGERPHONE>{$this->x($c['phone'] ?? '')}</LEDGERPHONE>
                        <EMAIL>{$this->x($c['email'] ?? '')}</EMAIL>
                        <ADDRESS.LIST><ADDRESS>{$this->x($c['address'] ?? '')}</ADDRESS></ADDRESS.LIST>
                        <LEDSTATENAME>{$this->x($c['state'] ?? '')}</LEDSTATENAME>
                        <PINCODE>{$this->x($c['pincode'] ?? '')}</PINCODE>
                      </LEDGER>
                    </TALLYMESSAGE>
            XML;
    }

    /**
     * A sales voucher: party debited by the gross, sales and tax credited.
     *
     * Tally's sign convention is the thing to get right — `ISDEEMEDPOSITIVE` Yes
     * with a *negative* amount is a debit, No with a positive amount is a credit.
     * It reads backwards and it is not negotiable.
     */
    private function tallySalesVoucher(array $r): string
    {
        $date = $this->tallyDate($r['date']);
        $taxLine = $r['tax'] > 0 ? $this->tallyLedgerEntry(self::LEDGER_TAX, $r['tax'], credit: true) : '';

        return <<<XML
                    <TALLYMESSAGE xmlns:UDF="TallyUDF">
                      <VOUCHER VCHTYPE="Sales" ACTION="Create" OBJVIEW="Invoice Voucher View">
                        <DATE>{$date}</DATE>
                        <EFFECTIVEDATE>{$date}</EFFECTIVEDATE>
                        <VOUCHERTYPENAME>Sales</VOUCHERTYPENAME>
                        <VOUCHERNUMBER>{$this->x($r['number'])}</VOUCHERNUMBER>
                        <REFERENCE>{$this->x($r['number'])}</REFERENCE>
                        <PARTYLEDGERNAME>{$this->x($r['party_ledger'])}</PARTYLEDGERNAME>
                        <ISINVOICE>Yes</ISINVOICE>
            {$this->tallyLedgerEntry($r['party_ledger'], $r['total'], credit: false)}
            {$this->tallyLedgerEntry($r['sales_ledger'], $r['taxable'], credit: true)}
            {$taxLine}
                      </VOUCHER>
                    </TALLYMESSAGE>
            XML;
    }

    private function tallyPurchaseVoucher(array $r): string
    {
        $date = $this->tallyDate($r['date']);
        $taxLine = $r['tax'] > 0 ? $this->tallyLedgerEntry(self::LEDGER_TAX, $r['tax'], credit: false) : '';

        return <<<XML
                    <TALLYMESSAGE xmlns:UDF="TallyUDF">
                      <VOUCHER VCHTYPE="Purchase" ACTION="Create" OBJVIEW="Invoice Voucher View">
                        <DATE>{$date}</DATE>
                        <EFFECTIVEDATE>{$date}</EFFECTIVEDATE>
                        <VOUCHERTYPENAME>Purchase</VOUCHERTYPENAME>
                        <VOUCHERNUMBER>{$this->x($r['number'])}</VOUCHERNUMBER>
                        <PARTYLEDGERNAME>{$this->x($r['party_ledger'])}</PARTYLEDGERNAME>
                        <ISINVOICE>Yes</ISINVOICE>
            {$this->tallyLedgerEntry($r['purchase_ledger'], $r['taxable'], credit: false)}
            {$taxLine}
            {$this->tallyLedgerEntry($r['party_ledger'], $r['total'], credit: true)}
                      </VOUCHER>
                    </TALLYMESSAGE>
            XML;
    }

    private function tallyReceiptVoucher(array $r): string
    {
        $date = $this->tallyDate($r['date']);
        $narration = $r['against'] ? 'Against '.$r['against'] : (string) ($r['narration'] ?? '');

        return <<<XML
                    <TALLYMESSAGE xmlns:UDF="TallyUDF">
                      <VOUCHER VCHTYPE="Receipt" ACTION="Create">
                        <DATE>{$date}</DATE>
                        <VOUCHERTYPENAME>Receipt</VOUCHERTYPENAME>
                        <VOUCHERNUMBER>{$this->x($r['number'])}</VOUCHERNUMBER>
                        <NARRATION>{$this->x($narration)}</NARRATION>
            {$this->tallyLedgerEntry($r['deposit_ledger'], $r['amount'], credit: false)}
            {$this->tallyLedgerEntry($r['party'], $r['amount'], credit: true)}
                      </VOUCHER>
                    </TALLYMESSAGE>
            XML;
    }

    private function tallyPaymentVoucher(array $r): string
    {
        $date = $this->tallyDate($r['date']);

        return <<<XML
                    <TALLYMESSAGE xmlns:UDF="TallyUDF">
                      <VOUCHER VCHTYPE="Payment" ACTION="Create">
                        <DATE>{$date}</DATE>
                        <VOUCHERTYPENAME>Payment</VOUCHERTYPENAME>
                        <VOUCHERNUMBER>{$this->x($r['number'])}</VOUCHERNUMBER>
                        <NARRATION>{$this->x((string) ($r['narration'] ?? ''))}</NARRATION>
            {$this->tallyLedgerEntry($r['party'], $r['amount'], credit: false)}
            {$this->tallyLedgerEntry($r['deposit_ledger'], $r['amount'], credit: true)}
                      </VOUCHER>
                    </TALLYMESSAGE>
            XML;
    }

    private function tallyJournalVoucher(array $r): string
    {
        $date = $this->tallyDate($r['date']);

        return <<<XML
                    <TALLYMESSAGE xmlns:UDF="TallyUDF">
                      <VOUCHER VCHTYPE="Journal" ACTION="Create">
                        <DATE>{$date}</DATE>
                        <VOUCHERTYPENAME>Journal</VOUCHERTYPENAME>
                        <VOUCHERNUMBER>{$this->x($r['number'])}</VOUCHERNUMBER>
                        <NARRATION>{$this->x((string) ($r['narration'] ?? ''))}</NARRATION>
            {$this->tallyLedgerEntry($r['debit_ledger'], $r['amount'], credit: false)}
            {$this->tallyLedgerEntry($r['credit_ledger'], $r['amount'], credit: true)}
                      </VOUCHER>
                    </TALLYMESSAGE>
            XML;
    }

    private function tallyExpenseVoucher(array $r): string
    {
        $date = $this->tallyDate($r['date']);

        return <<<XML
                    <TALLYMESSAGE xmlns:UDF="TallyUDF">
                      <VOUCHER VCHTYPE="Payment" ACTION="Create">
                        <DATE>{$date}</DATE>
                        <VOUCHERTYPENAME>Payment</VOUCHERTYPENAME>
                        <VOUCHERNUMBER>{$this->x($r['number'])}</VOUCHERNUMBER>
                        <NARRATION>{$this->x((string) ($r['narration'] ?? ''))}</NARRATION>
            {$this->tallyLedgerEntry($r['expense_ledger'], $r['amount'], credit: false)}
            {$this->tallyLedgerEntry($r['paid_from'], $r['amount'], credit: true)}
                      </VOUCHER>
                    </TALLYMESSAGE>
            XML;
    }

    /**
     * One leg of a voucher.
     *
     * Tally's convention, stated once here so nothing else in this file has to
     * remember it: a *debit* is ISDEEMEDPOSITIVE=Yes with a negative AMOUNT, and a
     * *credit* is ISDEEMEDPOSITIVE=No with a positive AMOUNT.
     */
    private function tallyLedgerEntry(string $ledger, float $amount, bool $credit): string
    {
        $signed = $credit ? number_format($amount, 2, '.', '') : '-'.number_format($amount, 2, '.', '');
        $deemed = $credit ? 'No' : 'Yes';

        return <<<XML
                        <ALLLEDGERENTRIES.LIST>
                          <LEDGERNAME>{$this->x($ledger)}</LEDGERNAME>
                          <ISDEEMEDPOSITIVE>{$deemed}</ISDEEMEDPOSITIVE>
                          <AMOUNT>{$signed}</AMOUNT>
                        </ALLLEDGERENTRIES.LIST>
            XML;
    }

    /** Tally wants YYYYMMDD with no separators. */
    private function tallyDate(?string $date): string
    {
        return $date ? str_replace('-', '', $date) : '';
    }

    private function x(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // -----------------------------------------------------------------------
    // CSV: Busy and Zoho Books
    // -----------------------------------------------------------------------

    /**
     * Column map for one (target, document) pair: header label => row key.
     *
     * Declared as data rather than written as code per combination, so the column
     * names each package expects are visible in one place and can be corrected
     * against its import template without touching any logic.
     *
     * @return array<string, string>
     */
    public function columns(string $target, string $document): array
    {
        if ($target === 'zoho') {
            return match ($document) {
                // Zoho matches contacts by display name, so that column must be
                // exactly right or every invoice creates a new contact.
                'contacts' => [
                    'Display Name' => 'name', 'Company Name' => 'company', 'GST Identification Number (GSTIN)' => 'gstin',
                    'Phone' => 'phone', 'EmailID' => 'email', 'Billing Address' => 'address',
                    'Billing State' => 'state', 'Billing Code' => 'pincode',
                    'Opening Balance' => 'opening_balance', 'Payment Terms' => 'credit_days',
                ],
                'invoices' => [
                    'Invoice Number' => 'number', 'Invoice Date' => 'date', 'Due Date' => 'due_date',
                    'Customer Name' => 'party', 'GST Identification Number (GSTIN)' => 'party_gstin',
                    'Place of Supply' => 'party_state', 'Item Total' => 'taxable',
                    'Tax Amount' => 'tax', 'Total' => 'total', 'Account' => 'sales_ledger',
                ],
                'bills' => [
                    'Bill Number' => 'number', 'Bill Date' => 'date', 'Due Date' => 'due_date',
                    'Vendor Name' => 'party', 'GST Identification Number (GSTIN)' => 'party_gstin',
                    'Source of Supply' => 'party_state', 'Item Total' => 'taxable',
                    'Tax Amount' => 'tax', 'Total' => 'total', 'Account' => 'purchase_ledger',
                ],
                'receipts' => [
                    'Payment Number' => 'number', 'Date' => 'date', 'Customer Name' => 'party',
                    'Invoice Number' => 'against', 'Amount' => 'amount',
                    'Payment Mode' => 'mode', 'Deposit To' => 'deposit_ledger', 'Description' => 'narration',
                ],
                'payments' => [
                    'Payment Number' => 'number', 'Date' => 'date', 'Vendor Name' => 'party',
                    'Amount' => 'amount', 'Payment Mode' => 'mode', 'Paid Through' => 'deposit_ledger',
                    'Description' => 'narration',
                ],
                'journals' => [
                    'Journal Number' => 'number', 'Journal Date' => 'date', 'Account' => 'debit_ledger',
                    'Offset Account' => 'credit_ledger', 'Amount' => 'amount',
                    'Reference Number' => 'reference', 'Description' => 'narration',
                ],
                'expenses' => [
                    'Expense Number' => 'number', 'Date' => 'date', 'Expense Account' => 'expense_ledger',
                    'Amount' => 'amount', 'Paid Through' => 'paid_from', 'Vendor Name' => 'payee',
                    'Reference Number' => 'reference', 'Description' => 'narration',
                ],
                default => [],
            };
        }

        // Busy. Its importers are laid out around a master name plus the two
        // ledgers a transaction touches, in a flatter shape than Zoho's.
        return match ($document) {
            'contacts' => [
                'Name' => 'name', 'Group' => 'group', 'Alias' => 'company', 'GSTIN' => 'gstin',
                'Phone' => 'phone', 'Email' => 'email', 'Address' => 'address',
                'State' => 'state', 'Pincode' => 'pincode', 'Opening Balance' => 'opening_balance',
            ],
            'invoices' => [
                'Vch No' => 'number', 'Date' => 'date', 'Party Name' => 'party', 'GSTIN' => 'party_gstin',
                'Taxable Amount' => 'taxable', 'Tax Amount' => 'tax', 'Bill Amount' => 'total',
                'Sales Account' => 'sales_ledger',
            ],
            'bills' => [
                'Vch No' => 'number', 'Date' => 'date', 'Party Name' => 'party', 'GSTIN' => 'party_gstin',
                'Taxable Amount' => 'taxable', 'Tax Amount' => 'tax', 'Bill Amount' => 'total',
                'Purchase Account' => 'purchase_ledger',
            ],
            'receipts' => [
                'Vch No' => 'number', 'Date' => 'date', 'Party Name' => 'party',
                'Against Bill' => 'against', 'Amount' => 'amount', 'Mode' => 'mode',
                'Cash/Bank Account' => 'deposit_ledger', 'Narration' => 'narration',
            ],
            'payments' => [
                'Vch No' => 'number', 'Date' => 'date', 'Party Name' => 'party',
                'Amount' => 'amount', 'Mode' => 'mode', 'Cash/Bank Account' => 'deposit_ledger',
                'Narration' => 'narration',
            ],
            'journals' => [
                'Vch No' => 'number', 'Date' => 'date', 'Debit Account' => 'debit_ledger',
                'Credit Account' => 'credit_ledger', 'Amount' => 'amount',
                'Reference' => 'reference', 'Narration' => 'narration',
            ],
            'expenses' => [
                'Vch No' => 'number', 'Date' => 'date', 'Expense Account' => 'expense_ledger',
                'Amount' => 'amount', 'Cash/Bank Account' => 'paid_from', 'Payee' => 'payee',
                'Reference' => 'reference', 'Narration' => 'narration',
            ],
            default => [],
        };
    }

    /** The normalised rows for one document type. */
    public function rowsFor(string $document, ?string $from, ?string $to): Collection
    {
        return match ($document) {
            'contacts' => $this->collectContacts(),
            'invoices' => $this->collectInvoices($from, $to),
            'bills' => $this->collectBills($from, $to),
            'receipts' => $this->collectReceipts($from, $to),
            'payments' => $this->collectPayments($from, $to),
            'journals' => $this->collectJournals($from, $to),
            'expenses' => $this->collectExpenses($from, $to),
            default => collect(),
        };
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function dateFilter($query, string $column, ?string $from, ?string $to)
    {
        if ($from) {
            $query->whereDate($column, '>=', $from);
        }
        if ($to) {
            $query->whereDate($column, '<=', $to);
        }

        return $query;
    }

    private function address($party): string
    {
        return collect([$party->address, $party->city, $party->state, $party->pincode])
            ->filter()
            ->implode(', ');
    }

    /** The Tally company the file is aimed at — the workspace's own company name. */
    private function companyName(): string
    {
        return (string) (Company::query()->orderBy('id')->value('name') ?: config('app.name'));
    }
}
