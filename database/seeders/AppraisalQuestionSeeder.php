<?php

namespace Database\Seeders;

use App\Models\AppraisalQuestion;
use App\Models\Client;
use Illuminate\Database\Seeder;

class AppraisalQuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $clients = Client::all();

        if ($clients->isEmpty()) {
            $this->command->warn('No clients found. Run ClientSeeder first.');
            return;
        }

        foreach ($clients as $client) {
            AppraisalQuestion::ensureDefaultsForClient((string) $client->id);
        }

        $this->command->info('Ensured default appraisal questions for '.$clients->count().' clients.');
    }
}
