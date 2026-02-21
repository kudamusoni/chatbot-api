<?php

namespace Tests\Feature\Domain;

use App\Services\PriceParser;
use Tests\TestCase;

class PriceParserTest extends TestCase
{
    public function test_accepts_valid_prices_with_up_to_two_decimals(): void
    {
        $parser = new PriceParser();

        $this->assertSame(9500, $parser->parseToMinorUnits('95'));
        $this->assertSame(9500, $parser->parseToMinorUnits('95.0'));
        $this->assertSame(9500, $parser->parseToMinorUnits('95.00'));
        $this->assertSame(9500, $parser->parseToMinorUnits('£95.00'));
    }

    public function test_rejects_invalid_or_unsupported_formats(): void
    {
        $parser = new PriceParser();

        $this->assertNull($parser->parseToMinorUnits('95.555'));
        $this->assertNull($parser->parseToMinorUnits('95,00'));
        $this->assertNull($parser->parseToMinorUnits('-95'));
        $this->assertNull($parser->parseToMinorUnits(''));
    }
}
