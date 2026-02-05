<?php

namespace Database\Seeders;

use App\Enums\ProductSource;
use App\Models\Client;
use App\Models\ProductCatalog;
use Illuminate\Database\Seeder;

class ProductCatalogSeeder extends Seeder
{
    /**
     * Seed sample product catalog items for Heritage Auctions.
     */
    public function run(): void
    {
        $client = Client::where('slug', 'heritage-auctions')->first();

        if (!$client) {
            return;
        }

        $products = [
            // Royal Doulton items (varied prices for testing median/range)
            [
                'title' => 'Royal Doulton Character Jug - Old Salt',
                'description' => 'Large character jug, excellent condition, porcelain',
                'source' => ProductSource::SOLD,
                'price' => 15000, // £150.00
                'sold_at' => now()->subMonths(3),
            ],
            [
                'title' => 'Royal Doulton Figurine - Top o\' the Hill',
                'description' => 'Classic porcelain figurine, mint condition',
                'source' => ProductSource::SOLD,
                'price' => 22000, // £220.00
                'sold_at' => now()->subMonths(6),
            ],
            [
                'title' => 'Royal Doulton Character Jug - Falstaff',
                'description' => 'Medium size, porcelain, good condition',
                'source' => ProductSource::ASKING,
                'price' => 18500, // £185.00
            ],
            [
                'title' => 'Royal Doulton Vase - Flambe',
                'description' => 'Red flambe glaze, porcelain, 1950s era',
                'source' => ProductSource::ASKING,
                'price' => 35000, // £350.00
            ],
            [
                'title' => 'Royal Doulton Plate - Dickens Series',
                'description' => 'Decorative plate, porcelain, good condition',
                'source' => ProductSource::ESTIMATE,
                'price' => 8500, // £85.00
            ],
            [
                'title' => 'Royal Doulton Tea Set - Bunnykins',
                'description' => 'Children\'s tea set, porcelain, complete set',
                'source' => ProductSource::SOLD,
                'price' => 12000, // £120.00
                'sold_at' => now()->subMonths(1),
            ],
            [
                'title' => 'Royal Doulton Character Jug - Winston Churchill',
                'description' => 'Large character jug, porcelain, excellent condition',
                'source' => ProductSource::ASKING,
                'price' => 28000, // £280.00
            ],
            [
                'title' => 'Royal Doulton Figurine - The Balloon Man',
                'description' => 'Classic figurine, porcelain, minor wear',
                'source' => ProductSource::ESTIMATE,
                'price' => 19500, // £195.00
            ],

            // Wedgwood items
            [
                'title' => 'Wedgwood Jasperware Vase',
                'description' => 'Blue and white jasperware, classical design',
                'source' => ProductSource::SOLD,
                'price' => 45000, // £450.00
                'sold_at' => now()->subMonths(2),
            ],
            [
                'title' => 'Wedgwood Tea Service',
                'description' => 'Complete service for 6, blue willow pattern',
                'source' => ProductSource::ASKING,
                'price' => 32000, // £320.00
            ],

            // General porcelain items
            [
                'title' => 'Meissen Porcelain Figurine',
                'description' => 'German porcelain, 19th century, shepherdess',
                'source' => ProductSource::SOLD,
                'price' => 85000, // £850.00
                'sold_at' => now()->subMonths(4),
            ],
            [
                'title' => 'Limoges Porcelain Box',
                'description' => 'French porcelain, hand-painted, trinket box',
                'source' => ProductSource::ASKING,
                'price' => 7500, // £75.00
            ],
            [
                'title' => 'Herend Porcelain Bird',
                'description' => 'Hungarian porcelain, fishnet pattern',
                'source' => ProductSource::ESTIMATE,
                'price' => 42000, // £420.00
            ],

            // Antique ceramics
            [
                'title' => 'Victorian Staffordshire Dog',
                'description' => 'Pair of spaniel figurines, circa 1880',
                'source' => ProductSource::SOLD,
                'price' => 28000, // £280.00
                'sold_at' => now()->subMonths(5),
            ],
            [
                'title' => 'Art Deco Clarice Cliff Vase',
                'description' => 'Bizarre range, geometric pattern',
                'source' => ProductSource::ASKING,
                'price' => 125000, // £1,250.00
            ],

            // More Royal Doulton for good test coverage
            [
                'title' => 'Royal Doulton Toby Jug - Happy John',
                'description' => 'Traditional toby jug, porcelain',
                'source' => ProductSource::ASKING,
                'price' => 9500, // £95.00
            ],
            [
                'title' => 'Royal Doulton Flambe Elephant',
                'description' => 'Red flambe glaze, porcelain figurine',
                'source' => ProductSource::SOLD,
                'price' => 48000, // £480.00
                'sold_at' => now()->subMonths(8),
            ],
            [
                'title' => 'Royal Doulton Series Ware Bowl',
                'description' => 'Coaching scenes, porcelain, good condition',
                'source' => ProductSource::ESTIMATE,
                'price' => 6500, // £65.00
            ],
            [
                'title' => 'Royal Doulton Character Jug - Robin Hood',
                'description' => 'Small size character jug, porcelain',
                'source' => ProductSource::ASKING,
                'price' => 7500, // £75.00
            ],
            [
                'title' => 'Royal Doulton Figurine - Fair Lady',
                'description' => 'Elegant lady figurine, porcelain, excellent',
                'source' => ProductSource::SOLD,
                'price' => 16500, // £165.00
                'sold_at' => now()->subMonths(2),
            ],
        ];

        foreach ($products as $product) {
            ProductCatalog::firstOrCreate(
                [
                    'client_id' => $client->id,
                    'title' => $product['title'],
                ],
                array_merge($product, [
                    'client_id' => $client->id,
                    'currency' => 'GBP',
                ])
            );
        }
    }
}
