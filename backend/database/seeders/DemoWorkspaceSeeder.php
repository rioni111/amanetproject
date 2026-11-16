<?php

namespace Database\Seeders;

use App\Enums\ActivityEventType;
use App\Enums\ClientType;
use App\Enums\MatterStatus;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fictional clients, matters, tasks, notes and history for the demo workspace.
 * All names, emails (reserved example.* domains) and phone numbers (555-01xx) are fictional.
 * Dates are relative to the seeding time so the dashboard always looks current.
 */
class DemoWorkspaceSeeder extends Seeder
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function run(): void
    {
        DB::transaction(function () {
            $admin = User::query()->where('email', mb_strtolower((string) config('lexora.demo_admin.email')))->first()
                ?? User::query()->orderBy('id')->first();

            $clients = $this->seedClients();
            $matters = $this->seedMatters($clients, $admin);
            $this->seedTasks($matters, $admin);
            $this->seedNotes($matters, $admin);
        });
    }

    /** @return array<string, Client> keyed by reference number */
    private function seedClients(): array
    {
        $rows = [
            ['CL-1001', 'Harborline Freight Ltd.', ClientType::Organization, 'legal@harborline.example.com', '+1 555 0101 2200', "14 Quay Road\nPort Ellison, PE 20114"],
            ['CL-1002', 'Amelia Okafor', ClientType::Individual, 'amelia.okafor@example.org', '+1 555 0102 4410', null],
            ['CL-1003', 'Brightfield Renewables GmbH', ClientType::Organization, 'contracts@brightfield.example.com', '+1 555 0103 9001', "Solarweg 8\n40210 Nordhafen"],
            ['CL-1004', 'Tomas Lindqvist', ClientType::Individual, 'tomas.lindqvist@example.org', '+1 555 0104 3377', null],
            ['CL-1005', 'Cedar & Vale Architects LLP', ClientType::Organization, 'office@cedarvale.example.com', '+1 555 0105 6620', "200 Mason Avenue, Suite 4\nRiverton, RV 30551"],
            ['CL-1006', 'Priya Raman', ClientType::Individual, 'priya.raman@example.net', null, null],
            ['CL-1007', 'Meridian Coast Hospitality Group', ClientType::Organization, 'counsel@meridiancoast.example.com', '+1 555 0107 1180', "1 Promenade Plaza\nBayview, BV 90412"],
            ['CL-1008', 'Daniel Ferreira', ClientType::Individual, 'd.ferreira@example.com', '+1 555 0108 5094', null],
        ];

        $clients = [];
        foreach ($rows as [$ref, $name, $type, $email, $phone, $address]) {
            $clients[$ref] = Client::query()->firstOrCreate(
                ['reference_number' => $ref],
                ['name' => $name, 'client_type' => $type, 'email' => $email, 'phone' => $phone, 'address' => $address],
            );
        }

        return $clients;
    }

    /**
     * @param  array<string, Client>  $clients
     * @return array<string, Matter> keyed by matter number
     */
    private function seedMatters(array $clients, ?User $admin): array
    {
        $now = Carbon::now()->startOfHour();
        $d = fn (?int $days, int $hour = 17) => $days === null ? null : $now->copy()->addDays($days)->setTime($hour, 0);

        // [number, client, title, type, status, priority, opened, due, closed, archived, last activity (days ago), description]
        $rows = [
            ['LEX-2026-0001', 'CL-1001', 'Master Services Agreement Review', 'Contract Review', MatterStatus::InProgress, Priority::High, -40, 3, null, null, 0,
                'Review and negotiate the renewed master services agreement with a new regional carrier, focusing on liability caps and termination rights.'],
            ['LEX-2026-0002', 'CL-1002', 'Wrongful Dismissal Claim', 'Employment Dispute', MatterStatus::Open, Priority::Urgent, -12, 6, null, null, 1,
                'Claim arising from termination without notice. Limitation period requires filing before the deadline.'],
            ['LEX-2026-0003', 'CL-1003', 'Subsidiary Company Registration', 'Corporate Registration', MatterStatus::InProgress, Priority::Medium, -25, 11, null, null, 2,
                'Incorporation of a local operating subsidiary, including articles of association and director appointments.'],
            ['LEX-2026-0004', 'CL-1004', 'Residential Purchase — 18 Elm Street', 'Property Agreement', MatterStatus::Open, Priority::Medium, -8, 21, null, null, 3,
                'Conveyancing for the purchase of a residential property, including title review and the purchase agreement.'],
            ['LEX-2026-0005', 'CL-1005', 'Unpaid Fee Recovery Proceedings', 'Commercial Litigation', MatterStatus::OnHold, Priority::High, -70, 35, null, null, 6,
                'Recovery of outstanding design fees. On hold pending a without-prejudice settlement proposal.'],
            ['LEX-2026-0006', 'CL-1007', 'Hotel Lease Renegotiation', 'Property Agreement', MatterStatus::InProgress, Priority::Urgent, -30, -2, null, null, 0,
                'Renegotiation of the head lease for a beachfront hotel, including rent review and repair obligations.'],
            ['LEX-2026-0007', 'CL-1006', 'Founder Equity Agreement', 'Contract Review', MatterStatus::Open, Priority::Low, -5, 13, null, null, 4,
                'Review of a founders\' agreement covering vesting, leaver provisions and intellectual property assignment.'],
            ['LEX-2026-0008', 'CL-1001', 'Cargo Damage Dispute', 'Commercial Litigation', MatterStatus::Closed, Priority::High, -150, -60, -20, null, 20,
                'Dispute over damaged goods in transit. Resolved through a negotiated settlement.'],
            ['LEX-2026-0009', 'CL-1008', 'Non-Compete Clause Review', 'Employment Dispute', MatterStatus::Closed, Priority::Low, -90, null, -45, null, 45,
                'Advice on the enforceability of post-employment restrictions.'],
            ['LEX-2026-0010', 'CL-1003', 'Supplier Framework Agreement', 'Contract Review', MatterStatus::Archived, Priority::Medium, -220, -150, -130, -100, 100,
                'Framework agreement with component suppliers. Completed and archived.'],
        ];

        $matters = [];
        foreach ($rows as [$number, $clientRef, $title, $type, $status, $priority, $opened, $due, $closed, $archived, $lastActivity, $description]) {
            $matter = Matter::query()->firstOrNew(['matter_number' => $number]);

            if (! $matter->exists) {
                $openedAt = $d($opened, 9);
                $matter->forceFill([
                    'client_id' => $clients[$clientRef]->id,
                    'title' => $title,
                    'description' => $description,
                    'matter_type' => $type,
                    'status' => $status,
                    'priority' => $priority,
                    'opened_at' => $openedAt,
                    'due_at' => $d($due),
                    'closed_at' => $d($closed, 15),
                    'archived_at' => $d($archived, 10),
                    'archived_from_status' => $status === MatterStatus::Archived ? ($closed !== null ? MatterStatus::Closed : MatterStatus::Open) : null,
                    'created_at' => $openedAt,
                    'updated_at' => $now->copy()->subDays($lastActivity)->subMinutes(count($matters) * 7),
                ])->save();

                $this->activity->record($matter, ActivityEventType::MatterCreated, "Matter {$number} opened for {$clients[$clientRef]->name}.", $admin, $matter, occurredAt: $openedAt);

                if ($status !== MatterStatus::Open) {
                    $changedAt = $d($closed ?? $archived, 15) ?? $openedAt->copy()->addDays(2);
                    $this->activity->record($matter, ActivityEventType::MatterStatusChanged, "Status changed to {$status->label()}.", $admin, $matter,
                        ['from' => MatterStatus::Open->value, 'to' => $status->value], $changedAt);
                }
            }

            $matters[$number] = $matter;
        }

        return $matters;
    }

    /** @param array<string, Matter> $matters */
    private function seedTasks(array $matters, ?User $admin): void
    {
        $now = Carbon::now()->startOfHour();

        // [matter, title, status, priority, due (days), completed (days)]
        $rows = [
            ['LEX-2026-0001', 'Mark up liability and indemnity clauses', TaskStatus::InProgress, Priority::High, 1, null],
            ['LEX-2026-0001', 'Confirm insurance certificates with client', TaskStatus::Todo, Priority::Medium, 2, null],
            ['LEX-2026-0001', 'Send first redline to counterparty', TaskStatus::Completed, Priority::High, -5, -6],
            ['LEX-2026-0002', 'Collect employment contract and payslips', TaskStatus::Completed, Priority::High, -3, -4],
            ['LEX-2026-0002', 'Draft particulars of claim', TaskStatus::InProgress, Priority::Urgent, 4, null],
            ['LEX-2026-0002', 'Schedule witness interview', TaskStatus::Todo, Priority::Medium, 5, null],
            ['LEX-2026-0003', 'Prepare articles of association', TaskStatus::InProgress, Priority::Medium, 7, null],
            ['LEX-2026-0003', 'Obtain director identity documents', TaskStatus::Todo, Priority::Medium, 9, null],
            ['LEX-2026-0004', 'Order title and land registry search', TaskStatus::Todo, Priority::Medium, 6, null],
            ['LEX-2026-0005', 'Review settlement proposal', TaskStatus::Todo, Priority::High, 14, null],
            ['LEX-2026-0006', 'Agree rent review mechanism', TaskStatus::InProgress, Priority::Urgent, -1, null],
            ['LEX-2026-0006', 'Circulate revised heads of terms', TaskStatus::Todo, Priority::High, 1, null],
            ['LEX-2026-0007', 'Summarise vesting and leaver provisions', TaskStatus::Todo, Priority::Low, 10, null],
            ['LEX-2026-0008', 'File signed settlement agreement', TaskStatus::Completed, Priority::Medium, -22, -21],
            ['LEX-2026-0009', 'Issue advice letter', TaskStatus::Completed, Priority::Low, -46, -46],
        ];

        foreach ($rows as [$number, $title, $status, $priority, $due, $completed]) {
            $matter = $matters[$number];
            $task = Task::query()->firstOrNew(['matter_id' => $matter->id, 'title' => $title]);

            if ($task->exists) {
                continue;
            }

            $createdAt = $matter->opened_at->addDay();
            $task->forceFill([
                'status' => $status,
                'priority' => $priority,
                'due_at' => $now->copy()->addDays($due)->setTime(17, 0),
                'completed_at' => $completed === null ? null : $now->copy()->addDays($completed)->setTime(14, 30),
                'created_by' => $admin?->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();

            $this->activity->record($matter, ActivityEventType::TaskCreated, "Task added: {$title}.", $admin, $task, occurredAt: $createdAt);
            if ($task->completed_at) {
                $this->activity->record($matter, ActivityEventType::TaskCompleted, "Task completed: {$title}.", $admin, $task, occurredAt: $task->completed_at);
            }
        }
    }

    /** @param array<string, Matter> $matters */
    private function seedNotes(array $matters, ?User $admin): void
    {
        $rows = [
            ['LEX-2026-0001', 'Client is prepared to accept a liability cap of 2x annual fees but not lower. Escalate if the carrier insists on 1x.', 2],
            ['LEX-2026-0002', 'Limitation period confirmed. Client prefers to explore settlement before issuing, but we must file regardless if no response by the deadline.', 1],
            ['LEX-2026-0003', 'Registry has updated its beneficial-ownership form; use the new template for the filing pack.', 3],
            ['LEX-2026-0005', 'Opposing counsel requested 30 days to respond to the settlement proposal. Diarised follow-up.', 6],
            ['LEX-2026-0006', 'Landlord open to a stepped rent review if the repair covenant is narrowed. Prepare alternative wording.', 0],
            ['LEX-2026-0008', 'Settlement funds received in full. File closed.', 20],
        ];

        foreach ($rows as [$number, $body, $daysAgo]) {
            $matter = $matters[$number];
            $note = MatterNote::query()->firstOrNew(['matter_id' => $matter->id, 'body' => $body]);

            if ($note->exists) {
                continue;
            }

            $at = Carbon::now()->subDays($daysAgo)->subHours(2);
            $note->forceFill(['author_id' => $admin?->id, 'created_at' => $at, 'updated_at' => $at])->save();

            $this->activity->record($matter, ActivityEventType::NoteAdded, 'Internal note added.', $admin, $note, occurredAt: $at);
        }
    }
}
