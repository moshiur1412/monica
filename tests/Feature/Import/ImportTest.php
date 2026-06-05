<?php

namespace Tests\Feature\Import;

use App\Domains\Contact\Import\Jobs\ProcessImportBatchJob;
use App\Domains\Contact\Import\Jobs\ProcessImportJob;
use App\Models\Account;
use App\Models\Contact;
use App\Models\ContactInformationType;
use App\Models\ImportJob;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use DatabaseMigrations;

    private User $user;

    private Account $account;

    private Vault $vault;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->account = Account::factory()->create();
        $this->user = User::factory()->create([
            'account_id' => $this->account->id,
            'is_account_administrator' => true,
        ]);

        $this->vault = Vault::factory()->create([
            'account_id' => $this->account->id,
        ]);

        $this->user->vaults()->attach($this->vault->id, [
            'permission' => Vault::PERMISSION_MANAGE,
            'contact_id' => Contact::factory()->create([
                'vault_id' => $this->vault->id,
            ])->id,
        ]);

        ContactInformationType::factory()->create([
            'account_id' => $this->account->id,
            'name' => 'email',
            'type' => 'email',
            'can_be_deleted' => false,
        ]);

        ContactInformationType::factory()->create([
            'account_id' => $this->account->id,
            'name' => 'phone',
            'type' => 'phone',
            'can_be_deleted' => false,
        ]);

        $this->token = $this->user->createToken('test', ['read', 'write'])->plainTextToken;
    }

    #[Test]
    public function it_creates_an_import_job_on_upload(): void
    {
        $csvContent = "name,email,phone\nJohn Doe,john@example.com,+1234567890\nJane Smith,jane@example.com,+9876543210";
        $csv = UploadedFile::fake()->createWithContent('contacts.csv', $csvContent);

        $response = $this->withToken($this->token)
            ->postJson('/api/import', [
                'file' => $csv,
                'vault_id' => $this->vault->id,
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id', 'filename', 'total_rows', 'processed_rows',
                'failed_rows', 'status', 'progress_pct', 'created_at',
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals('contacts.csv', $data['filename']);
        $this->assertNotEmpty($data['id']);

        $this->assertDatabaseHas('import_jobs', [
            'id' => $data['id'],
            'filename' => 'contacts.csv',
        ]);

        $importJob = ImportJob::find($data['id']);
        $this->assertNotNull($importJob);
        $this->assertTrue(Storage::disk('local')->exists($importJob->original_file_path));
    }

    #[Test]
    public function it_processes_import_and_updates_progress(): void
    {
        $filePath = 'imports/test_contacts.csv';
        Storage::disk('local')->put($filePath, "name,email,phone\nJohn Doe,john@example.com,+1234567890\nJane Smith,jane@example.com,+9876543210");

        $importJob = ImportJob::factory()->create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'vault_id' => $this->vault->id,
            'original_file_path' => $filePath,
            'total_rows' => 0,
            'status' => ImportJob::STATUS_PENDING,
            'batch_size' => 50,
        ]);

        $job = new ProcessImportJob($importJob->id, [
            'account_id' => $this->account->id,
            'vault_id' => $this->vault->id,
            'author_id' => $this->user->id,
        ]);
        $job->handle(app(\App\Domains\Contact\Import\Services\CsvParser::class));

        $importJob->refresh();
        $this->assertEquals(2, $importJob->total_rows);
        $this->assertEquals('completed', $importJob->status);
        $this->assertEquals(2, $importJob->processed_rows);
        $this->assertEquals(100, $importJob->progressPct());
        $this->assertNotNull($importJob->started_at);
        $this->assertNotNull($importJob->completed_at);
        $this->assertEquals(3, Contact::count());
    }

    #[Test]
    public function it_handles_row_errors_and_continues(): void
    {
        $filePath = 'imports/test_errors.csv';
        Storage::disk('local')->put($filePath, "name,email,phone\nJohn Doe,john@example.com,+1234567890\n,,+1234567890\nJane Smith,not-an-email,+9876543210");

        $importJob = ImportJob::factory()->create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'vault_id' => $this->vault->id,
            'original_file_path' => $filePath,
            'total_rows' => 0,
            'status' => ImportJob::STATUS_PENDING,
            'batch_size' => 50,
        ]);

        $job = new ProcessImportJob($importJob->id, [
            'account_id' => $this->account->id,
            'vault_id' => $this->vault->id,
            'author_id' => $this->user->id,
        ]);
        $job->handle(app(\App\Domains\Contact\Import\Services\CsvParser::class));

        $importJob->refresh();
        $this->assertEquals('completed', $importJob->status);
        $this->assertEquals(1, $importJob->processed_rows);
        $this->assertEquals(2, $importJob->failed_rows);
        $this->assertCount(2, $importJob->errors ?? []);
    }

    #[Test]
    public function it_returns_import_status_via_api(): void
    {
        $csvContent = "name,email,phone\nJohn Doe,john@example.com,+1234567890";
        $csv = UploadedFile::fake()->createWithContent('contacts.csv', $csvContent);

        $response = $this->withToken($this->token)
            ->postJson('/api/import', [
                'file' => $csv,
                'vault_id' => $this->vault->id,
            ]);

        $importJobId = $response->json('data.id');

        $importJob = ImportJob::find($importJobId);

        $this->assertNotNull($importJob);

        $this->assertEquals('completed', $importJob->fresh()->status);

        $showResponse = $this->withToken($this->token)
            ->getJson("/api/import/{$importJobId}");

        $showResponse->assertStatus(200);
        $showResponse->assertJson([
            'data' => [
                'id' => $importJobId,
                'status' => 'completed',
                'progress_pct' => 100,
                'processed_rows' => 1,
                'failed_rows' => 0,
            ],
        ]);
    }

    #[Test]
    public function it_lists_imports_paginated(): void
    {
        ImportJob::factory()->count(3)->create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/api/import');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'filename', 'status', 'progress_pct', 'created_at'],
            ],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
        $this->assertCount(3, $response->json('data'));
    }

    #[Test]
    public function it_cancels_a_running_import(): void
    {
        $importJob = ImportJob::factory()->processing()->create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/api/import/{$importJob->id}/cancel");

        $response->assertStatus(200);
        $this->assertEquals('cancelled', $importJob->fresh()->status);
        $this->assertNotNull($importJob->fresh()->cancelled_at);
    }

    #[Test]
    public function it_returns_errors_paginated(): void
    {
        $errors = [];
        for ($i = 1; $i <= 15; $i++) {
            $errors[] = ['row' => $i, 'message' => "Error {$i}"];
        }

        $importJob = ImportJob::factory()->completed()->create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'errors' => $errors,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/import/{$importJob->id}/errors?page=1&per_page=5");

        $response->assertStatus(200);
        $response->assertJson([
            'meta' => [
                'current_page' => 1,
                'per_page' => 5,
                'total' => 15,
                'last_page' => 3,
            ],
        ]);
        $this->assertCount(5, $response->json('data'));
    }

    #[Test]
    public function it_downloads_error_csv(): void
    {
        $filePath = 'imports/test_errors_download.csv';
        $csvContent = "name,email,phone\nJohn Doe,john@example.com,+1234567890\n,Bad Row,\nJane,invalid-email,+9876543210";
        Storage::disk('local')->put($filePath, $csvContent);

        $importJob = ImportJob::factory()->completed()->create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'original_file_path' => $filePath,
            'filename' => 'test_errors.csv',
            'errors' => [
                ['row' => 2, 'message' => 'Missing required field: name'],
                ['row' => 3, 'message' => "Invalid email: 'invalid-email'"],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->get("/api/import/{$importJob->id}/errors.csv");

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename="test_errors_errors.csv"');

        $content = $response->getContent();
        $this->assertStringContainsString('Missing required field: name', $content);
        $this->assertStringContainsString("Invalid email: 'invalid-email'", $content);
        $this->assertStringContainsString('John Doe', $content);
        $this->assertStringContainsString('john@example.com', $content);
    }

    #[Test]
    public function it_rejects_unauthorized_access(): void
    {
        $importJob = ImportJob::factory()->create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
        ]);

        $otherUser = User::factory()->create([
            'account_id' => $this->account->id,
        ]);
        $otherToken = $otherUser->createToken('test', ['read'])->plainTextToken;

        $response = $this->withToken($otherToken)
            ->getJson("/api/import/{$importJob->id}");

        $response->assertStatus(404);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $response = $this->withToken($this->token)
            ->postJson('/api/import', []);

        $response->assertStatus(422);
    }

    #[Test]
    public function batch_job_checks_cancellation_flag(): void
    {
        $filePath = 'imports/cancelled_test.csv';
        Storage::disk('local')->put($filePath, "name,email,phone\nJohn Doe,john@example.com,+1234567890");

        $importJob = ImportJob::factory()->create([
            'account_id' => $this->account->id,
            'user_id' => $this->user->id,
            'vault_id' => $this->vault->id,
            'status' => ImportJob::STATUS_CANCELLED,
            'original_file_path' => $filePath,
            'total_rows' => 1,
            'batch_size' => 50,
        ]);

        $job = new ProcessImportBatchJob(
            $importJob->id,
            0,
            1,
            [
                'account_id' => $this->account->id,
                'vault_id' => $this->vault->id,
                'author_id' => $this->user->id,
            ]
        );

        $handleMethod = new \ReflectionMethod($job, 'handle');
        $handleMethod->setAccessible(true);

        $importJob->refresh();
        $this->assertTrue($importJob->isCancelled());

        $handleMethod->invokeArgs($job, [
            app(\App\Domains\Contact\Import\Services\CsvParser::class),
            app(\App\Domains\Contact\Import\Services\ImportContactFromRow::class),
        ]);

        $this->assertEquals(1, Contact::count());
    }
}
