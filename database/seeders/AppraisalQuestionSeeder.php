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
        $client = Client::where('slug', 'heritage-auctions')->first();

        if (!$client) {
            return;
        }

        $questions = [
            [
                'key' => 'maker',
                'label' => 'Who is the maker or manufacturer?',
                'help_text' => "If unknown, say 'unknown'.",
                'input_type' => 'text',
                'required' => true,
                'order_index' => 1,
            ],
            [
                'key' => 'age',
                'label' => 'What is the approximate age or era?',
                'help_text' => "Examples: 'circa 1950', 'early 20th century'.",
                'input_type' => 'text',
                'required' => true,
                'order_index' => 2,
            ],
            [
                'key' => 'material',
                'label' => 'What material is it made from?',
                'help_text' => 'Examples: gold, porcelain, wood.',
                'input_type' => 'text',
                'required' => true,
                'order_index' => 3,
            ],
            [
                'key' => 'condition',
                'label' => 'What is the condition?',
                'help_text' => "Examples: excellent, good, fair, damaged.",
                'input_type' => 'text',
                'required' => true,
                'order_index' => 4,
            ],
            [
                'key' => 'notes',
                'label' => 'Any additional notes or details?',
                'help_text' => 'Optional details like markings, provenance, or dimensions.',
                'input_type' => 'text',
                'required' => false,
                'order_index' => 5,
            ],
        ];

        foreach ($questions as $question) {
            AppraisalQuestion::firstOrCreate(
                [
                    'client_id' => $client->id,
                    'key' => $question['key'],
                ],
                array_merge($question, [
                    'client_id' => $client->id,
                ])
            );
        }
    }
}
