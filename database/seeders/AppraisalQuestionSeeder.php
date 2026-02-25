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
        // Default questions for all clients
        $defaultQuestions = [
            [
                'key' => 'item_type',
                'label' => 'What type of item is this?',
                'help_text' => 'e.g., painting, sculpture, furniture, jewelry, ceramics, etc.',
                'input_type' => 'select',
                'required' => true,
                'order_index' => 1,
                'options' => [
                    'painting',
                    'sculpture',
                    'furniture',
                    'jewelry',
                    'ceramics',
                    'glassware',
                    'silver',
                    'watches',
                    'books',
                    'coins',
                    'stamps',
                    'textiles',
                    'other',
                ],
            ],
            [
                'key' => 'maker',
                'label' => 'Who made it?',
                'help_text' => 'Artist, manufacturer, or maker\'s name if known',
                'input_type' => 'text',
                'required' => false,
                'order_index' => 2,
                'options' => null,
            ],
            [
                'key' => 'age',
                'label' => 'How old is it?',
                'help_text' => 'Approximate age or date of creation (e.g., "circa 1920", "Victorian era")',
                'input_type' => 'text',
                'required' => false,
                'order_index' => 3,
                'options' => null,
            ],
            [
                'key' => 'material',
                'label' => 'What is it made of?',
                'help_text' => 'Primary materials (e.g., oil on canvas, sterling silver, mahogany)',
                'input_type' => 'text',
                'required' => false,
                'order_index' => 4,
                'options' => null,
            ],
            [
                'key' => 'size',
                'label' => 'What size is it?',
                'help_text' => 'Dimensions or size details (e.g., height x width x depth)',
                'input_type' => 'text',
                'required' => false,
                'order_index' => 5,
                'options' => null,
            ],
            [
                'key' => 'condition',
                'label' => 'What condition is it in?',
                'help_text' => 'Note any damage, repairs, or wear',
                'input_type' => 'select',
                'required' => true,
                'order_index' => 6,
                'options' => [
                    'excellent',
                    'very_good',
                    'good',
                    'fair',
                    'poor',
                ],
            ],
        ];

        $clients = Client::all();

        if ($clients->isEmpty()) {
            $this->command->warn('No clients found. Run ClientSeeder first.');
            return;
        }

        $questionsCreated = 0;

        foreach ($clients as $client) {
            foreach ($defaultQuestions as $questionData) {
                AppraisalQuestion::firstOrCreate(
                    [
                        'client_id' => $client->id,
                        'key' => $questionData['key'],
                    ],
                    array_merge($questionData, ['client_id' => $client->id])
                );
                $questionsCreated++;
            }
        }

        $this->command->info("Created {$questionsCreated} appraisal questions across {$clients->count()} clients.");
    }
}
