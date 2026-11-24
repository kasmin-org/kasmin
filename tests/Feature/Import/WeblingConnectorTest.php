<?php

namespace Tests\Feature\Import;

use App\Support\Import\WeblingConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeblingConnectorTest extends TestCase
{
    public function test_members_and_groups_become_import_rows(): void
    {
        Http::fake([
            'example.webling.eu/api/1/member' => Http::response(['objects' => [11, 12]]),
            'example.webling.eu/api/1/member/11,12' => Http::response([
                ['type' => 'member', 'id' => 11, 'parents' => [5], 'properties' => ['Vorname' => 'Ann', 'Name' => 'Brook', 'Geburtstag' => '2015-04-03', 'Geschlecht' => 'w', 'E-Mail' => 'family@example.org', 'Erziehungsberechtigte 1' => 'Chris']],
                ['type' => 'member', 'id' => 12, 'parents' => [5, 6], 'properties' => ['Vorname' => 'Ben', 'Name' => 'Brook', 'Geburtstag' => null]],
            ]),
            'example.webling.eu/api/1/membergroup/5,6' => Http::response([
                ['type' => 'membergroup', 'id' => 5, 'properties' => ['title' => 'Camp 2027']],
                ['type' => 'membergroup', 'id' => 6, 'properties' => ['title' => 'Waitlist']],
            ]),
        ]);
        $mapping = WeblingConnector::DEFAULT_MAPPING + ['guardian1_first_name' => 'Erziehungsberechtigte 1'];
        $rows = app(WeblingConnector::class)->rows('https://example.webling.eu', 'key', $mapping, ['gender' => ['w' => 'female']]);

        $this->assertSame(['source_id' => '11', 'first_name' => 'Ann', 'last_name' => 'Brook', 'birth_date' => '2015-04-03', 'gender' => 'female', 'email' => 'family@example.org', 'guardian1_first_name' => 'Chris', 'groups' => 'Camp 2027'], $rows[0]);
        $this->assertSame('Camp 2027, Waitlist', $rows[1]['groups']);
        $this->assertArrayNotHasKey('birth_date', $rows[1]);
        Http::assertSent(fn (Request $r) => $r->header('apikey')[0] === 'key');
    }

    public function test_only_https(): void
    {
        $this->expectException(\RuntimeException::class);
        app(WeblingConnector::class)->rows('http://example.webling.eu', 'key');
    }
}
