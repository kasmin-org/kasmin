<?php

namespace Tests\Unit\Auth;

use App\Support\Auth\Base32H;
use PHPUnit\Framework\TestCase;

class Base32HTest extends TestCase
{
    public function test_random_codes_use_only_the_32_digits(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = Base32H::random(6);
            $this->assertMatchesRegularExpression('/^[0-9A-HJ-NP-RTV-Z]{6}$/', $code);
        }
        $this->assertSame(32, strlen(Base32H::DIGITS));
        $this->assertSame(32, count(array_unique(str_split(Base32H::DIGITS))));
    }

    public function test_reading_is_lenient(): void
    {
        $this->assertSame('K7M2QX', Base32H::normalize('k7m-2qx'));
        $this->assertSame('K7M2QX', Base32H::normalize(' K7M 2QX '));
        $this->assertSame('015V00', Base32H::normalize('OIsu-o0'));
        $this->assertSame('', Base32H::normalize('ä€!'));
        $this->assertSame('482913', Base32H::normalize('482913'));
    }

    public function test_codes_are_shown_in_groups_of_three(): void
    {
        $this->assertSame('K7M-2QX', Base32H::format('k7m2qx'));
    }
}
