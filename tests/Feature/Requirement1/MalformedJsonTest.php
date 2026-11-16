<?php

namespace Tests\Feature\Requirement1;

use Illuminate\Testing\TestResponse;

/**
 * A body sent as JSON that is not valid JSON is a 400 Bad Request in the standard
 * error envelope: the request could not be read at all, which is different from
 * a readable request with invalid fields (422).
 */
class MalformedJsonTest extends ApiTestCase
{
    private function sendRaw(string $method, string $uri, string $body, string $contentType = 'application/json'): TestResponse
    {
        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_a_malformed_json_body_is_a_400(): void
    {
        $response = $this->sendRaw('POST', '/api/v1/auth/login', '{"email": "a@b.c", "password": ');

        $this->assertEnvelope($response, 400, success: false);
        $response->assertJsonPath('message', 'Malformed JSON body.');
    }

    public function test_it_applies_to_authenticated_writes_before_anything_runs(): void
    {
        $product = $this->makeProduct();
        $token = auth('api')->tokenById($this->makeUser('admin')->getKey());
        $this->resetAuthState();

        $response = $this->call('PUT', "/api/v1/products/{$product->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => "Bearer {$token}",
        ], '{"name": "x",}');

        $this->assertEnvelope($response, 400, success: false);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => $product->name]);
    }

    public function test_valid_json_with_invalid_fields_is_still_a_422(): void
    {
        $this->assertEnvelope($this->sendRaw('POST', '/api/v1/auth/login', '{"email": "not-an-email"}'), 422, success: false);
    }

    public function test_an_empty_json_body_is_a_422_not_a_400(): void
    {
        $this->assertEnvelope($this->sendRaw('POST', '/api/v1/auth/login', ''), 422, success: false);
    }

    public function test_a_body_that_is_not_sent_as_json_is_not_parsed_as_json(): void
    {
        $this->assertEnvelope($this->sendRaw('POST', '/api/v1/auth/login', 'email=a@b.c', 'application/x-www-form-urlencoded'), 422, success: false);
    }
}
