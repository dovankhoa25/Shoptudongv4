<?php

namespace App\Observers;

use App\Models;
use App\Support\ApiCache;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Model writes share invalidation rules. Bulk SQL and pivot writes invalidate at their call sites. */
final class PublicCacheObserver
{
    public const GROUPS = [
        Models\GameType::class => ['public:catalog'],
        Models\User::class => ['admin:analytics'],
        Models\Category::class => ['public:catalog', 'public:nick', 'admin:analytics'],
        Models\CategoryTemplate::class => ['public:catalog'],
        Models\Service::class => ['public:catalog'],
        Models\Field::class => ['public:catalog'],
        Models\Attribute::class => ['public:nick'],
        Models\AttributeOption::class => ['public:nick'],
        Models\Server::class => ['public:servers', 'public:server-prices', 'public:bots', 'public:gembot', 'public:nro-metadata'],
        Models\GoldPrice::class => ['public:servers', 'public:server-prices'],
        Models\GemPrice::class => ['public:servers', 'public:server-prices'],
        Models\Bot::class => ['public:bots'],
        Models\GemBot::class => ['public:gembot', 'public:server-prices'],
        Models\Spin::class => ['public:nick'],
        Models\SpinReward::class => ['public:nick'],
        Models\RandomBox::class => ['public:nick'],
        Models\RandomNick::class => ['public:nick'],
        Models\Nick::class => ['public:nick'],
        Models\NroAccountSnapshot::class => ['public:nick', 'public:nro-shop:listings'],
        Models\CardType::class => ['public:card-types'],
    ];

    private const BOT_FIELDS = ['name', 'server_id', 'type', 'map_name', 'map_id', 'area_number', 'status'];
    private const GEM_FIELDS = ['name', 'server_id', 'map_name', 'map_id', 'area_number', 'coordinates', 'status', 'gem_qty'];

    public function created(Model $model): void
    {
        $this->invalidate($model);
    }

    public function updated(Model $model): void
    {
        if ($model instanceof Models\User && !$model->wasChanged(['username', 'email'])) return;
        // Gold inventory/credential-only heartbeats do not change public bot cards.
        if ($model instanceof Models\Bot && !$model->wasChanged(self::BOT_FIELDS)) return;
        if ($model instanceof Models\GemBot && !$model->wasChanged(self::GEM_FIELDS)) return;
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        if ($model instanceof Media) {
            $type = Model::getActualClassNameForMorph($model->model_type);
            ApiCache::clearGroups(match ($type) {
                Models\Category::class => ['public:catalog'],
                Models\Nick::class, Models\Spin::class, Models\SpinReward::class, Models\RandomBox::class => ['public:nick'],
                default => [],
            });
            return;
        }
        ApiCache::clearGroups(self::GROUPS[$model::class] ?? []);
    }
}
