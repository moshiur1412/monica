<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportJobFactory extends Factory
{
    protected $model = ImportJob::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'user_id' => User::factory(),
            'vault_id' => null,
            'filename' => $this->faker->word.'.csv',
            'original_file_path' => 'imports/test.csv',
            'file_hash' => $this->faker->sha256,
            'total_rows' => 100,
            'processed_rows' => 0,
            'failed_rows' => 0,
            'status' => ImportJob::STATUS_PENDING,
            'errors' => null,
            'batch_size' => 50,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => ImportJob::STATUS_PENDING]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => ImportJob::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ImportJob::STATUS_COMPLETED,
            'processed_rows' => 100,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ImportJob::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }
}
