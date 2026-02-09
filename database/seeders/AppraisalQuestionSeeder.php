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
                'key' => 'dimensions',
                'label' => 'What are the dimensions?',
                'help_text' => 'Height x Width x Depth in inches or centimeters',
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
                    'excellent' => 'Excellent - Like new, no visible wear',
                    'very_good' => 'Very Good - Minor wear, well preserved',
                    'good' => 'Good - Some wear consistent with age',
                    'fair' => 'Fair - Noticeable wear or minor damage',
                    'poor' => 'Poor - Significant damage or repairs needed',
                ],
            ],
            [
                'key' => 'provenance',
                'label' => 'Do you know its history?',
                'help_text' => 'Previous owners, where acquired, any documentation',
                'input_type' => 'textarea',
                'required' => false,
                'order_index' => 7,
                'options' => null,
            ],
            [
                'key' => 'markings',
                'label' => 'Are there any signatures or marks?',
                'help_text' => 'Signatures, hallmarks, labels, stamps, or inscriptions',
                'input_type' => 'text',
                'required' => false,
                'order_index' => 8,
                'options' => null,
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
