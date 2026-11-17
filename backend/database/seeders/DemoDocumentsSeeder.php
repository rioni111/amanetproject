<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\Matter;
use App\Models\SigningRequest;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\Requests\ClientSubmissionService;
use App\Services\Requests\DocumentRequestService;
use App\Services\Requests\DocumentReviewService;
use App\Services\Signing\SigningRequestService;
use App\Services\Signing\SigningService;
use App\Support\SigningConsent;
use Database\Seeders\Support\DemoPdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Phase 7 — fictional documents, versions, client requests and signatures for the demo workspace.
 *
 * Everything is created THROUGH THE APPLICATION'S OWN SERVICES (the same code the API runs), so every
 * invariant holds exactly as for real use: private storage with SHA-256, immutable versions, hashed links,
 * submissions, review decisions, signed PDFs with evidence, activity events and administrator notifications.
 * No link token is kept: requests that still wait for the client have no link (the administrator generates or
 * e-mails one during the demo).
 *
 * Idempotent and additive: each scenario is keyed on (matter, document or request title) and skipped when
 * present; only the demo matters LEX-2026-0001…0007 are touched, and only while they exist and are not
 * archived. Switch off with LEXORA_DEMO_DOCUMENTS=false (PHPUnit and the E2E database do).
 */
class DemoDocumentsSeeder extends Seeder
{
    private const DEMO_UA = 'Lexora demo data seeder (fictional signature)';

    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentRequestService $requests,
        private readonly ClientSubmissionService $submissions,
        private readonly DocumentReviewService $reviews,
        private readonly SigningRequestService $signingRequests,
        private readonly SigningService $signing,
    ) {}

    public function run(): void
    {
        $admin = User::query()->where('email', mb_strtolower((string) config('lexora.demo_admin.email')))->first();
        if ($admin === null) {
            $this->command?->warn('Demo documents skipped: the demo administrator does not exist.');

            return;
        }

        // LEX-2026-0001 — Harborline Freight: a contract with two versions and an approved client upload.
        $this->onMatter('LEX-2026-0001', function (Matter $m) use ($admin) {
            $msa = $this->document($m, $admin, 'Master Services Agreement — carrier draft', 'contracts', 'msa-carrier-draft.pdf', $this->msa(1));
            if ($msa !== null && $msa->versions()->count() === 1) {
                $this->documents->addVersion($m, $msa, $this->upload('msa-carrier-redline.pdf', $this->msa(2)), $admin);
            }
            $this->clientRequest($m, $admin, 'Certificate of insurance', 'Please upload the current certificate of insurance covering goods in transit.', 'evidence', null,
                'approved', 'certificate-of-insurance.pdf', 'Certificate of Insurance', 'Goods-in-transit cover for Harborline Freight Ltd.');
        });

        // LEX-2026-0002 — Amelia Okafor: one upload waiting for review, one request still with the client.
        $this->onMatter('LEX-2026-0002', function (Matter $m) use ($admin) {
            $this->clientRequest($m, $admin, 'Employment contract and final payslips', 'Please upload your signed employment contract and your last three payslips as one PDF.', 'evidence', null,
                'under_review', 'employment-contract-and-payslips.pdf', 'Employment Contract and Payslips', 'Contract of employment and payslips (fictional).');
            $this->clientRequest($m, $admin, 'Termination letter', 'Please upload the termination letter you received from your employer, including the envelope or e-mail it came with.', 'correspondence', 4);
        });

        // LEX-2026-0003 — Brightfield Renewables: a board resolution signed by the administrator.
        $this->onMatter('LEX-2026-0003', function (Matter $m) use ($admin) {
            $resolution = $this->document($m, $admin, 'Board resolution — subsidiary incorporation', 'other', 'board-resolution.pdf', DemoPdf::make(
                'Board Resolution', 'Brightfield Renewables GmbH - incorporation of a local operating subsidiary', [
                    ['1. Resolution', 'The board resolves to incorporate a wholly owned operating subsidiary and to appoint two directors.'],
                    ['2. Authority', 'Counsel is authorised to prepare and file the articles of association and the registration documents.'],
                ], true));
            if ($resolution !== null && ! SigningRequest::query()->where('document_id', $resolution->id)->exists()) {
                $this->signing->signNowAsAdmin($m, $resolution, $resolution->currentVersion, [
                    'method' => 'drawn', 'strokes' => $this->strokes(), 'page' => 1, 'x' => 0.12, 'y' => 0.72, 'width' => 0.3,
                    'consent' => true, 'consent_version' => SigningConsent::VERSION,
                ], $admin, '127.0.0.1', self::DEMO_UA);
            }
        });

        // LEX-2026-0004 — Tomas Lindqvist: a purchase agreement signed by the client, a correction request.
        $this->onMatter('LEX-2026-0004', function (Matter $m) use ($admin) {
            $agreement = $this->document($m, $admin, 'Purchase agreement — 18 Elm Street', 'agreements', 'purchase-agreement-18-elm-street.pdf', DemoPdf::make(
                'Residential Purchase Agreement', '18 Elm Street - fictional conveyancing matter', [
                    ['1. Parties', 'The seller agrees to sell and the buyer, Tomas Lindqvist, agrees to buy the property at 18 Elm Street.'],
                    ['2. Price and deposit', 'The purchase price is payable on completion; a ten per cent deposit is held by the seller\'s conveyancer.'],
                    ['3. Completion', 'Completion takes place twenty-eight days after exchange, subject to a satisfactory title review.'],
                ], true));
            if ($agreement !== null && ! SigningRequest::query()->where('document_id', $agreement->id)->exists()) {
                [$request, $issued] = $this->signingRequests->create($m, [
                    'document_id' => $agreement->id, 'document_version_id' => $agreement->current_version_id, 'signer_type' => 'client',
                    'signer_name' => 'Tomas Lindqvist', 'signer_email' => 'tomas.lindqvist@example.org',
                    'message' => 'Please review and sign the purchase agreement before exchange.',
                ], $admin, true);
                $this->signingRequests->markShared($m, $request, $admin, $request->fresh()->lock_version);
                $this->signing->recordViewed($issued->link);
                $this->signing->recordStarted($issued->link);
                $this->signing->signAsClient($issued->link, [
                    'method' => 'typed', 'typed_name' => 'Tomas Lindqvist', 'confirmed_name' => 'Tomas Lindqvist',
                    'page' => 1, 'x' => 0.12, 'y' => 0.74, 'width' => 0.32, 'consent' => true, 'consent_version' => SigningConsent::VERSION,
                ], '127.0.0.1', self::DEMO_UA);
            }
            $this->clientRequest($m, $admin, 'Proof of funds', 'Please upload a recent bank statement or mortgage offer showing the funds for completion.', 'evidence', 9,
                'correction_requested', 'proof-of-funds.pdf', 'Proof of Funds', 'Bank statement extract (fictional).',
                'The statement is older than three months. Please upload one issued within the last 30 days.');
        });

        // LEX-2026-0006 — Meridian Coast: heads of terms ready to be sent to the client for signature.
        $this->onMatter('LEX-2026-0006', function (Matter $m) use ($admin) {
            $heads = $this->document($m, $admin, 'Heads of terms — hotel lease renewal', 'agreements', 'heads-of-terms-hotel-lease.pdf', DemoPdf::make(
                'Heads of Terms - Hotel Lease Renewal', 'Meridian Coast Hospitality Group - subject to contract', [
                    ['Term', 'A new lease of fifteen years with a tenant-only break at year ten.'],
                    ['Rent review', 'Stepped rent review every five years, capped and collared, as negotiated.'],
                    ['Repair', 'Repairing obligations limited by reference to a schedule of condition.'],
                ], true));
            if ($heads !== null && ! SigningRequest::query()->where('document_id', $heads->id)->exists()) {
                $this->signingRequests->create($m, [
                    'document_id' => $heads->id, 'document_version_id' => $heads->current_version_id, 'signer_type' => 'client',
                    'signer_name' => 'Marta Ilić', 'signer_email' => 'counsel@meridiancoast.example.com',
                    'message' => 'Please sign the agreed heads of terms so we can instruct the landlord\'s solicitors.',
                ], $admin, false);
            }
        });

        // LEX-2026-0007 — Priya Raman: a draft for review.
        $this->onMatter('LEX-2026-0007', function (Matter $m) use ($admin) {
            $this->document($m, $admin, 'Founders\' agreement — draft for review', 'agreements', 'founders-agreement-draft.pdf', DemoPdf::make(
                'Founders\' Agreement (Draft)', 'For review - fictional demonstration', [
                    ['Vesting', 'Founder shares vest over four years with a one-year cliff.'],
                    ['Leaver provisions', 'Good and bad leaver definitions apply to unvested shares.'],
                    ['Intellectual property', 'All founders assign relevant intellectual property to the company.'],
                ]));
        });
    }

    /** Runs a scenario on a demo matter that exists and is not archived (never on anything else). */
    private function onMatter(string $number, callable $scenario): void
    {
        $matter = Matter::query()->where('matter_number', $number)->first();
        if ($matter === null || $matter->isArchived()) {
            return;
        }
        $scenario($matter);
    }

    /** Creates the document unless a document with this title already exists on the matter (then returns it). */
    private function document(Matter $matter, User $admin, string $title, string $category, string $file, string $bytes): ?Document
    {
        $existing = Document::query()->whereBelongsTo($matter)->where('title', $title)->first();
        if ($existing !== null) {
            return $existing->load('currentVersion');
        }

        return $this->documents->create($matter, $this->upload($file, $bytes), ['title' => $title, 'category' => $category], $admin)->load('currentVersion');
    }

    /**
     * A client document request in the given state. The client's upload goes through the real public
     * submission service with a link issued for that purpose (its token is discarded afterwards).
     */
    private function clientRequest(Matter $matter, User $admin, string $title, string $instructions, string $category, ?int $dueInDays,
        ?string $state = null, ?string $file = null, ?string $pdfTitle = null, ?string $pdfSubtitle = null, ?string $correction = null): void
    {
        if (DocumentRequest::query()->whereBelongsTo($matter)->where('title', $title)->exists()) {
            return;
        }
        [$request, $issued] = $this->requests->create($matter, array_filter([
            'title' => $title, 'instructions' => $instructions, 'requested_category' => $category,
            'due_date' => $dueInDays !== null ? Carbon::now()->addDays($dueInDays)->toDateString() : null,
        ], fn ($v) => $v !== null), $admin, $state !== null);
        if ($state === null) {
            return; // waiting for the client; no link yet
        }

        $bytes = DemoPdf::make($pdfTitle, $pdfSubtitle, [['Contents', 'Fictional document uploaded by the client through the secure link.']]);
        $submission = $this->submissions->submit($issued->link, $this->upload($file, $bytes), 'Uploaded as requested.');
        $this->reviews->startReview($matter, $request->fresh(), $submission->id, $admin, $request->fresh()->lock_version);
        match ($state) {
            'approved' => $this->reviews->approve($matter, $request->fresh(), $submission->id, $admin, $request->fresh()->lock_version),
            'correction_requested' => $this->reviews->requestCorrection($matter, $request->fresh(), $submission->id, (string) $correction, $admin, $request->fresh()->lock_version, null, false),
            default => null, // 'under_review'
        };
    }

    private function upload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lexora-demo-');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function msa(int $version): string
    {
        return DemoPdf::make('Master Services Agreement', $version === 1 ? 'Carrier draft - version 1' : 'Redline returned to the carrier - version 2', [
            ['1. Services', 'The carrier provides regional freight services under purchase orders issued by Harborline Freight Ltd.'],
            ['2. Liability', $version === 1
                ? 'The carrier\'s aggregate liability is capped at one times the annual fees.'
                : 'The carrier\'s aggregate liability is capped at two times the annual fees (amended).'],
            ['3. Termination', 'Either party may terminate on ninety days\' written notice, or immediately for material breach.'],
        ]);
    }

    /** @return list<list<array{0: float, 1: float}>> a plausible drawn signature, normalised to the signature area */
    private function strokes(): array
    {
        $wave = [];
        for ($i = 0; $i <= 24; $i++) {
            $wave[] = [round(0.05 + $i * 0.035, 3), round(0.5 + 0.28 * sin($i / 2.2), 3)];
        }

        return [$wave, [[0.25, 0.85], [0.45, 0.78], [0.65, 0.88], [0.85, 0.8]]];
    }
}
