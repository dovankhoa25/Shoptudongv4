<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;
class ApiRateLimitIsolationTest extends TestCase {
    use RefreshDatabase;
    public function test_public_burst_does_not_block_authenticated_profile(): void {
        for($i=0;$i<60;$i++)$this->getJson('/api/nick/99999999')->assertNotFound();
        $this->getJson('/api/nick/99999999')->assertStatus(429);
        Passport::actingAs(User::factory()->create(),['profile:read']);
        $this->getJson('/api/me/balance')->assertOk();
    }
    public function test_profile_read_burst_does_not_block_purchase_validation(): void {
        Passport::actingAs(User::factory()->create(),['*']);
        for($i=0;$i<60;$i++)$this->getJson('/api/me/balance')->assertOk();
        $this->getJson('/api/me/balance')->assertStatus(429);
        $this->postJson('/api/purchase',[])->assertUnprocessable();
    }
}
