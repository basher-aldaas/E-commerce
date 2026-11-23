<?php

namespace Tests\Feature\Requirement1;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The API documentation is the hand-written docs/openapi.yaml. These tests keep it
 * in step with the routes Laravel really registers, in both directions, and check
 * that Swagger UI serves it.
 */
class SwaggerTest extends TestCase
{
    private function document(): array
    {
        return Yaml::parseFile(base_path('docs/openapi.yaml'));
    }

    /** @return list<string> e.g. "post /api/v1/orders/{orderId}/status" */
    private function documentedOperations(): array
    {
        $operations = [];
        foreach ($this->document()['paths'] as $path => $methods) {
            foreach (array_keys($methods) as $method) {
                $operations[] = strtolower($method).' '.$path;
            }
        }
        sort($operations);

        return $operations;
    }

    /** @return list<string> */
    private function registeredApiOperations(): array
    {
        $operations = [];
        foreach (Route::getRoutes() as $route) {
            $uri = '/'.$route->uri();
            if (! preg_match('#^/api/v\d+/#', $uri)) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[] = strtolower($method).' '.$uri;
                }
            }
        }
        sort($operations);

        return $operations;
    }

    public function test_every_registered_api_route_is_documented_and_nothing_else(): void
    {
        $this->assertSame($this->registeredApiOperations(), $this->documentedOperations());
    }

    public function test_the_document_is_valid_openapi_with_jwt_bearer_auth(): void
    {
        $document = $this->document();

        $this->assertStringStartsWith('3.', $document['openapi']);
        $this->assertSame('bearer', $document['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertSame('JWT', $document['components']['securitySchemes']['bearerAuth']['bearerFormat']);
        $this->assertSame([], $document['paths']['/api/v1/auth/login']['post']['security']);
    }

    public function test_every_reference_points_to_a_defined_component(): void
    {
        $document = $this->document();
        $raw = file_get_contents(base_path('docs/openapi.yaml'));
        preg_match_all("~\\\$ref: '#/components/(\w+)/(\w+)'~", $raw, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches);
        foreach ($matches as [, $section, $name]) {
            $this->assertArrayHasKey($name, $document['components'][$section] ?? [], "Undefined \$ref #/components/{$section}/{$name}");
        }
    }

    public function test_warehouse_and_versioning_errors_are_documented(): void
    {
        $paths = $this->document()['paths'];

        $create = $paths['/api/v1/orders']['post']['responses'];
        foreach (['201', '401', '403', '409', '422', '503', '504'] as $code) {
            $this->assertArrayHasKey($code, $create, "POST /api/v1/orders is missing {$code}");
        }
        $this->assertArrayHasKey('/api/v1/products', $paths);
        $this->assertArrayHasKey('/api/v2/products', $paths);
    }

    public function test_swagger_ui_serves_the_yaml_file(): void
    {
        $this->get('/api/documentation')->assertOk();

        $docs = $this->get('/docs');
        $docs->assertOk();
        $this->assertStringContainsString('application/yaml', $docs->headers->get('Content-Type'));
        $this->assertSame(file_get_contents(base_path('docs/openapi.yaml')), $docs->getContent());
    }
}
