<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create sample auction house clients for development
        $clients = [
            [
                'name' => 'Heritage Auctions',
                'slug' => 'heritage-auctions',
                'settings' => [
                    'theme' => 'classic',
                    'welcome_message' => 'Welcome to Heritage Auctions! How can I help you today?',
                ],
            ],
            [
                'name' => 'Sotheby\'s',
                'slug' => 'sothebys',
                'settings' => [
                    'theme' => 'elegant',
                    'welcome_message' => 'Welcome to Sotheby\'s. How may I assist you?',
                ],
            ],
            [
                'name' => 'Christie\'s',
                'slug' => 'christies',
                'settings' => [
                    'theme' => 'modern',
                    'welcome_message' => 'Hello! Welcome to Christie\'s. What would you like to know?',
                ],
            ],
        ];

        foreach ($clients as $clientData) {
            Client::firstOrCreate(
                ['slug' => $clientData['slug']],
                $clientData
            );
        }

        $this->command->info('Created ' . count($clients) . ' sample clients.');
    }
}
