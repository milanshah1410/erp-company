<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exports\GenericArrayExport;
use App\Http\Controllers\Controller;
use App\Services\AccountingExportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hand the books to Tally, Busy or Zoho Books (retail base §10).
 *
 * A one-way bridge, deliberately: the shop keeps its day-to-day accounts here and
 * its chartered accountant keeps Tally, so this produces a file the CA imports.
 * Pulling balances back would put two systems in charge of the same numbers.
 *
 * The three targets differ in *shape*, not only in column names, and the API
 * mirrors that rather than pretending otherwise:
 *
 *  - Tally wants one XML envelope holding masters and every voucher type
 *    together, so `document=all` is not merely allowed there, it is the norm.
 *  - Busy and Zoho want one CSV per document type, so they need a specific
 *    document and refuse `all` — with a message that says what to ask for
 *    instead, rather than silently exporting whichever one came first.
 */
class AccountingExportController extends Controller
{
    public function __construct(private readonly AccountingExportService $exports) {}

    /**
     * What a run would contain, plus the shape of each target. Drives the whole
     * screen, so nobody downloads an empty file to find out it was empty.
     */
    public function summary(Request $request)
    {
        [$from, $to] = $this->period($request);

        return response()->json([
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'counts' => $this->exports->summary($from, $to),
                /**
                 * Manual vouchers with only one ledger filled in. Legitimate in
                 * this app — the books derive the other side from the payment mode
                 * — but not a journal entry any accounting package will accept, so
                 * they are reported rather than silently dropped from the file.
                 */
                'skipped_journals' => $this->exports->unexportableJournals($from, $to),
                'documents' => AccountingExportService::DOCUMENTS,
                'targets' => [
                    'tally' => ['format' => 'xml', 'supports_all' => true],
                    'busy' => ['format' => 'csv', 'supports_all' => false],
                    'zoho' => ['format' => 'csv', 'supports_all' => false],
                ],
            ],
        ]);
    }

    /** The file itself. */
    public function download(Request $request)
    {
        $data = $request->validate([
            'target' => ['required', Rule::in(AccountingExportService::TARGETS)],
            'document' => ['required', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            // Tally only: which document types go into the one envelope.
            'documents' => ['nullable', 'array'],
            'documents.*' => [Rule::in(AccountingExportService::DOCUMENTS)],
        ]);

        [$from, $to] = $this->period($request);
        $target = $data['target'];
        $document = $data['document'];
        $stamp = now()->format('Y-m-d');

        if ($target === 'tally') {
            // Keep the caller's order out of it: Tally rejects a voucher whose
            // ledger it has not seen yet, so masters must lead whatever was asked
            // for. DOCUMENTS is already in that order.
            $requested = $document === 'all'
                ? AccountingExportService::DOCUMENTS
                : ($data['documents'] ?? [$document]);
            $documents = array_values(array_intersect(AccountingExportService::DOCUMENTS, $requested));

            if ($documents === []) {
                return response()->json(['message' => 'Choose at least one kind of document to export.'], 422);
            }

            $xml = $this->exports->tallyXml($documents, $from, $to);

            return $this->streamText($xml, "tally-{$stamp}.xml", 'application/xml');
        }

        // Busy and Zoho: one CSV per document type.
        if ($document === 'all') {
            return response()->json([
                'message' => ucfirst($target).' imports one file per document type. Pick a single document — contacts first, then invoices, bills, receipts, payments, journals and expenses.',
            ], 422);
        }
        if (! in_array($document, AccountingExportService::DOCUMENTS, true)) {
            return response()->json(['message' => "There is nothing called '{$document}' to export."], 422);
        }

        $columns = $this->exports->columns($target, $document);
        $rows = $this->exports->rowsFor($document, $from, $to);

        $exportRows = $rows->map(
            fn (array $row) => array_map(fn (string $key) => $row[$key] ?? null, array_values($columns)),
        )->all();

        return Excel::download(
            new GenericArrayExport($exportRows, array_keys($columns)),
            "{$target}-{$document}-{$stamp}.csv",
        );
    }

    /**
     * Preview the first few rows on screen.
     *
     * Worth having because these files are handed to somebody else: an accountant
     * who imports a month of vouchers against the wrong ledger names spends a day
     * undoing it, and the cheapest place to catch that is before the download.
     */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'target' => ['required', Rule::in(AccountingExportService::TARGETS)],
            'document' => ['required', Rule::in(AccountingExportService::DOCUMENTS)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        [$from, $to] = $this->period($request);
        $rows = $this->exports->rowsFor($data['document'], $from, $to);

        if ($data['target'] === 'tally') {
            // The XML for a handful of rows, so the ledger names and the debit /
            // credit sides can be read before anything is imported.
            return response()->json([
                'format' => 'xml',
                'total' => $rows->count(),
                'sample' => $this->exports->tallyXml([$data['document']], $from, $to),
            ]);
        }

        $columns = $this->exports->columns($data['target'], $data['document']);

        return response()->json([
            'format' => 'csv',
            'total' => $rows->count(),
            'columns' => array_keys($columns),
            'rows' => $rows->take(10)->map(
                fn (array $row) => array_map(fn (string $key) => $row[$key] ?? null, array_values($columns)),
            )->values(),
        ]);
    }

    /**
     * Default window: the current month. A blank date range that meant "every
     * voucher you have ever written" would be a plausible first click and a very
     * slow one.
     */
    private function period(Request $request): array
    {
        // input(), not query(): the same helper serves the GET endpoints and any
        // future POST, and a filter silently ignored is worse than one refused.
        return [
            $request->input('date_from') ?: now()->startOfMonth()->toDateString(),
            $request->input('date_to') ?: now()->toDateString(),
        ];
    }

    private function streamText(string $body, string $filename, string $contentType): StreamedResponse
    {
        return response()->streamDownload(function () use ($body) {
            echo $body;
        }, $filename, ['Content-Type' => $contentType]);
    }
}
