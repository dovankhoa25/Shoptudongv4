<?php

namespace Tests\Unit;

use App\Http\Controllers\AppAuto\AppGoldTransactionController;
use App\Http\Controllers\AppAuto\VersionTwo\AppGoldTransactionVersionTwoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoldTransactionErrorResponseTest extends TestCase
{
    public static function controllers(): array
    {
        return [[AppGoldTransactionController::class], [AppGoldTransactionVersionTwoController::class]];
    }

    #[DataProvider('controllers')]
    public function test_transaction_failure_does_not_expose_infrastructure_details(string $controller): void
    {
        DB::shouldReceive('transaction')->once()
            ->andThrow(new \RuntimeException('Connection failed at 203.0.113.42:3306 /srv/private'));
        $request = Request::create('/app/test', 'PUT', [
            'status' => 'processing', 'bot_id' => 1, 'gold_qty' => 0, 'gold_bar_qty' => 0,
        ]);
        $response = app($controller)->update($request, 1);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertStringNotContainsString('203.0.113.42', $response->getContent());
        $this->assertStringNotContainsString('/srv/private', $response->getContent());
    }
}
