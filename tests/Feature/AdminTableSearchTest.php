<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminTableSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTableSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_hash_search_matches_only_the_exact_primary_key(): void
    {
        $target = User::factory()->create(['username' => 'target-user']);
        User::factory()->create(['username' => 'member-'.$target->id]);

        $query = User::query();
        AdminTableSearch::applyPreset($query, '#'.$target->id, 'users');

        $this->assertSame([$target->id], $query->pluck('id')->all());
    }

    public function test_plain_and_prefixed_search_use_only_the_whitelisted_fields(): void
    {
        $usernameMatch = User::factory()->create([
            'username' => 'needle-account',
            'email' => 'first@example.test',
        ]);
        $emailMatch = User::factory()->create([
            'username' => 'other-account',
            'email' => 'needle@example.test',
        ]);

        $plain = User::query();
        AdminTableSearch::applyPreset($plain, 'needle', 'users');
        $this->assertEqualsCanonicalizing([$usernameMatch->id, $emailMatch->id], $plain->pluck('id')->all());

        $targeted = User::query();
        AdminTableSearch::applyPreset($targeted, 'username:needle', 'users');
        $this->assertSame([$usernameMatch->id], $targeted->pluck('id')->all());
    }

    public function test_invalid_numeric_id_and_empty_known_alias_return_no_rows(): void
    {
        User::factory()->create();

        $invalidId = User::query();
        AdminTableSearch::applyPreset($invalidId, '#not-a-number', 'users');
        $this->assertSame(0, $invalidId->count());

        $emptyAlias = User::query();
        AdminTableSearch::applyPreset($emptyAlias, 'username:', 'users');
        $this->assertSame(0, $emptyAlias->count());

        $oversizedId = User::query();
        AdminTableSearch::applyPreset($oversizedId, '#'.str_repeat('9', 200), 'users');
        $this->assertSame(0, $oversizedId->count());
    }
}
