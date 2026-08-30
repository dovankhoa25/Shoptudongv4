<?php

namespace App\Enums;

enum Permission: string
{
    case DashboardView = 'dashboard.view';
    case AnalyticsView = 'analytics.view';

    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersLock = 'users.lock';
    case UsersManageRoles = 'users.manage-roles';
    case UsersAdjustBalance = 'users.adjust-balance';
    case UserCategoriesManage = 'user-categories.manage';

    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';
    case FrontendClientsView = 'frontend-clients.view';
    case FrontendClientsManage = 'frontend-clients.manage';

    case GameTypesView = 'game-types.view';
    case GameTypesManage = 'game-types.manage';
    case CategoriesView = 'categories.view';
    case CategoriesManage = 'categories.manage';
    case AttributesView = 'attributes.view';
    case AttributesManage = 'attributes.manage';
    case NicksView = 'nicks.view';
    case NicksManage = 'nicks.manage';
    case NicksRefund = 'nicks.refund';

    case CardTypesView = 'card-types.view';
    case CardTypesManage = 'card-types.manage';
    case CardsView = 'cards.view';

    case ServicesView = 'services.view';
    case ServicesManage = 'services.manage';
    case ServiceOrdersView = 'service-orders.view';
    case ServiceOrdersProcess = 'service-orders.process';
    case FieldsView = 'fields.view';
    case FieldsManage = 'fields.manage';
    case ServiceConfigurationManage = 'service-configuration.manage';

    case TransactionsView = 'transactions.view';
    case TransactionsAdjust = 'transactions.adjust';
    case WithdrawalsView = 'withdrawals.view';
    case WithdrawalsCreate = 'withdrawals.create';
    case WithdrawalsProcess = 'withdrawals.process';
    case CarotRechargesView = 'carot-recharges.view';

    case RandomBoxesView = 'random-boxes.view';
    case RandomBoxesManage = 'random-boxes.manage';
    case RandomNicksView = 'random-nicks.view';
    case RandomNicksManage = 'random-nicks.manage';
    case SpinsView = 'spins.view';
    case SpinsManage = 'spins.manage';
    case SpinTicketsView = 'spin-tickets.view';
    case SpinTicketsManage = 'spin-tickets.manage';

    case TradingDashboardView = 'trading-dashboard.view';
    case ServersView = 'servers.view';
    case ServersManage = 'servers.manage';
    case ServerGameLoginsView = 'server-game-logins.view';
    case ServerGameLoginsManage = 'server-game-logins.manage';
    case BotsView = 'bots.view';
    case BotsManage = 'bots.manage';
    case GoldPricesView = 'gold-prices.view';
    case GoldPricesManage = 'gold-prices.manage';
    case ImportsView = 'imports.view';
    case GoldOrdersView = 'gold-orders.view';
    case GoldOrdersProcess = 'gold-orders.process';
    case GemBotsView = 'gem-bots.view';
    case GemBotsManage = 'gem-bots.manage';
    case GemPricesView = 'gem-prices.view';
    case GemPricesManage = 'gem-prices.manage';
    case GemOrdersView = 'gem-orders.view';
    case GemOrdersProcess = 'gem-orders.process';
    case BotHistoriesView = 'bot-histories.view';

    case DepositsView = 'deposits.view';
    case DepositsManageCardTypes = 'deposits.manage-card-types';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function middleware(self ...$permissions): string
    {
        return 'permission:'.implode(',', array_map(
            static fn (self $permission): string => $permission->value,
            $permissions,
        ));
    }
}
