<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BotOverviewController;
use App\Models\{Bot, GemBot, Server};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Tests\TestCase;

class BotOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function overview(): array
    {
        return app(BotOverviewController::class)()->getData(true)['data'];
    }

    private function attributes(Server $server): array
    {
        return ['name' => 'Public bot', 'account_name' => 'private', 'account_password' => 'secret',
            'server_id' => $server->id, 'server_game_id' => 1, 'status' => true,
            'map_id' => '1', 'map_name' => 'Kame', 'area_number' => '12'];
    }

    public function test_overview_groups_all_servers_without_credentials_and_reuses_cache(): void
    {
        Cache::flush();
        $server = Server::create(['name' => 'One', 'status' => true]);
        $other = Server::create(['name' => 'Two', 'status' => true]);
        Bot::create([...$this->attributes($server), 'type' => 'selling_main']);
        Bot::create([...$this->attributes($other), 'type' => 'import_main']);
        GemBot::create($this->attributes($other));
        Bot::create([...$this->attributes($server), 'type' => 'selling_main', 'status' => false]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->overview();
        $this->assertCount(2, DB::getQueryLog());
        foreach ($data as $bots) {
            $this->assertCount(1, $bots);
            $this->assertArrayNotHasKey('account_name', $bots[0]);
            $this->assertArrayNotHasKey('account_password', $bots[0]);
        }
        $this->assertEquals($other->id, $data['import_main'][0]['server_id']);
        DB::flushQueryLog();
        $this->assertSame($data, $this->overview());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->getJson('/api/bots/overview')->assertOk()->assertJsonPath('success', true)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_creating_editing_moving_disabling_and_deleting_bots_invalidates_overview(): void
    {
        $server = Server::create(['name' => 'One', 'status' => true]);
        $other = Server::create(['name' => 'Two', 'status' => true]);
        foreach ([Bot::class => 'selling_main', GemBot::class => 'gem_selling'] as $class => $group) {
            $this->assertSame([], $this->overview()[$group]);
            $bot = $class::create([...$this->attributes($server), ...($class === Bot::class ? ['type' => $group] : [])]);
            $this->assertCount(1, $this->overview()[$group]);
            $bot->update(['name' => 'Changed', 'map_name' => 'New map', 'area_number' => '8', 'server_id' => $other->id]);
            $row = $this->overview()[$group][0];
            $this->assertSame('Changed', $row['name']);
            $this->assertSame('New map', $row['map_name']);
            $this->assertEquals(8, $row['area_number']);
            $this->assertEquals($other->id, $row['server_id']);
            $bot->update(['status' => false]);
            $this->assertSame([], $this->overview()[$group]);
            $bot->update(['status' => true]);
            $this->assertCount(1, $this->overview()[$group]);
            $bot->delete();
            $this->assertSame([], $this->overview()[$group]);
        }
    }

    public function test_bulk_admin_writes_and_type_changes_invalidate_overview(): void
    {
        $server = Server::create(['name' => 'One', 'status' => true]);
        foreach ([Bot::class => 'import_main', GemBot::class => 'gem_selling'] as $class => $group) {
            $bot = $class::create([...$this->attributes($server), ...($class === Bot::class ? ['type' => 'selling_main'] : [])]);
            $this->overview();
            if ($class === Bot::class) {
                $bot->update(['type' => 'import_main']);
                $this->assertSame([], $this->overview()['selling_main']);
            }
            $this->assertCount(1, $this->overview()[$group]);
            $controller = app($class === Bot::class ? \App\Http\Controllers\Admin\BotController::class : \App\Http\Controllers\Admin\GemBotController::class);
            $request = Request::create('/', 'POST', ['bot_ids' => [$bot->id]]);
            $class === Bot::class ? $controller->bulkDeactivate($request) : $controller->toggleStatus($bot);
            $this->assertSame([], $this->overview()[$group]);
            $class === Bot::class ? $controller->bulkActivate($request) : $controller->toggleStatus($bot);
            $this->assertCount(1, $this->overview()[$group]);
            $class === Bot::class ? $controller->bulkDelete($request) : $controller->destroy($bot);
            $this->assertSame([], $this->overview()[$group]);
        }
    }
}
