<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Unexpected failures use the standard error envelope and never expose
 * internals (SQL, paths) to API clients when debug is off.
 */
class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('api/_test/boom', fn () => throw new RuntimeException('SQLSTATE[42P01]: relation "secret_table" does not exist'));
    }

    public function test_unexpected_errors_are_hidden_without_debug(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/_test/boom')
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Server Error']);

        $this->assertStringNotContainsString('secret_table', $response->getContent());
    }

    public function test_unexpected_errors_show_their_message_in_debug(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'SQLSTATE[42P01]: relation "secret_table" does not exist');
    }

    public function test_project_context_errors_keep_their_own_response(): void
    {
        // ResolvesProject throws an HttpResponseException carrying a 422.
        $user = \App\Models\User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson('/api/v1/assets')->assertStatus(422)->assertJsonPath('success', false);
    }
}
