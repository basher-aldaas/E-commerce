<?php

namespace Tests\Feature\Requirement1;

use OpenApi\Generator;
use Tests\TestCase;

/**
 * Builds the OpenAPI document in memory, so storage/api-docs is not rewritten.
 */
class SwaggerTest extends TestCase
{
    public function test_documented_paths_cover_the_public_api(): void
    {
        $document = json_decode(
            (new Generator())->generate(config('l5-swagger.documentations.default.paths.annotations'))->toJson(),
            true
        );

        $expected = [
            '/api/v1/auth/register' => 'post',
            '/api/v1/auth/login' => 'post',
            '/api/v1/auth/logout' => 'post',
            '/api/v1/products' => 'post',
            '/api/v1/products/{product}' => 'get',
            '/api/v2/products' => 'get',
            '/api/v2/products/{product}' => 'get',
            '/api/v1/categories' => 'post',
            '/api/v1/inventory/{product}' => 'patch',
            '/api/v1/orders' => 'post',
            '/api/v1/orders/{orderId}' => 'get',
            '/api/v1/orders/{orderId}/status' => 'post',
            '/api/v1/orders/{orderId}/track' => 'get',
        ];

        foreach ($expected as $path => $method) {
            $this->assertArrayHasKey($path, $document['paths'], "Missing path: {$path}");
            $this->assertArrayHasKey($method, $document['paths'][$path], "Missing {$method} on {$path}");
        }
    }
}
