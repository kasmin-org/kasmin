<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Contract check (Q06): the generated OpenAPI document covers every route of the REST API v1.
 */
class OpenApiTest extends TestCase
{
    public function test_openapi_document_lists_every_api_route_with_bearer_security(): void
    {
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        $this->assertSame('3.1.0', substr((string) $doc['openapi'], 0, 3).'.0');
        $documented = [];
        foreach ($doc['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper((string) $method).' '.$path;
            }
        }
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $path = '/'.substr($route->uri(), strlen('api/v1/'));
                $this->assertContains($method.' '.$path, $documented, "{$method} {$path} is missing in the OpenAPI document");
            }
        }
        $this->assertSame('bearer', $doc['components']['securitySchemes']['http']['scheme'] ?? $doc['components']['securitySchemes'][array_key_first($doc['components']['securitySchemes'])]['scheme'] ?? null);
    }
}
