<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class AdminTableSearch
{
    public const CONTAINS = 'contains';

    public const EXACT = 'exact';

    public static function applyPreset(Builder $query, mixed $rawSearch, string $preset): Builder
    {
        [$broadFields, $aliases] = self::preset($preset);

        return self::apply($query, $rawSearch, $broadFields, $aliases);
    }

    /**
     * Add a table search constraint to an existing query.
     *
     * The existing builder is deliberately reused so global scopes and explicit
     * role/user ownership constraints always remain in force.
     *
     * @param  array<int, string|array<string, mixed>>  $broadFields
     * @param  array<string, string|array<string, mixed>>  $aliases
     */
    public static function apply(
        Builder $query,
        mixed $rawSearch,
        array $broadFields,
        array $aliases = [],
    ): Builder {
        if (! is_scalar($rawSearch)) {
            return $query;
        }

        $search = trim((string) $rawSearch);

        if ($search === '') {
            return $query;
        }

        if (str_starts_with($search, '#')) {
            self::applyPrimaryKey($query, trim(substr($search, 1)));

            return $query;
        }

        if (preg_match('/^([a-z][a-z0-9_]*):(.*)$/is', $search, $matches) === 1) {
            $alias = strtolower($matches[1]);

            if (array_key_exists($alias, $aliases)) {
                $value = trim($matches[2]);

                if ($value === '' || ! self::isValidValue($aliases[$alias], $value)) {
                    $query->whereRaw('1 = 0');

                    return $query;
                }

                $query->where(function (Builder $nested) use ($aliases, $alias, $value): void {
                    self::applyField($nested, $aliases[$alias], $value, 'and');
                });

                return $query;
            }
        }

        if ($broadFields === []) {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $query->where(function (Builder $nested) use ($broadFields, $search): void {
            foreach (array_values($broadFields) as $index => $field) {
                self::applyField($nested, $field, $search, $index === 0 ? 'and' : 'or');
            }
        });

        return $query;
    }

    /**
     * A regular text column searched with LIKE.
     *
     * @return array{column: string, mode: string}
     */
    public static function text(string $column): array
    {
        return ['column' => $column, 'mode' => self::CONTAINS];
    }

    /**
     * A column matched exactly.
     *
     * @return array{column: string, mode: string, type?: string}
     */
    public static function exact(string $column, ?string $type = null): array
    {
        return array_filter([
            'column' => $column,
            'mode' => self::EXACT,
            'type' => $type,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Text columns on a related model searched with LIKE.
     *
     * @param  array<int, string>  $columns
     * @return array{relation: string, columns: array<int, string>, mode: string}
     */
    public static function relation(string $relation, array $columns): array
    {
        return [
            'relation' => $relation,
            'columns' => $columns,
            'mode' => self::CONTAINS,
        ];
    }

    private static function applyPrimaryKey(Builder $query, string $value): void
    {
        $model = $query->getModel();
        $integerKey = $model->getKeyType() === 'int';

        if (
            $value === ''
            || mb_strlen($value) > 191
            || ($integerKey && (strlen($value) > 20 || ! ctype_digit($value)))
        ) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($model->getQualifiedKeyName(), $value);
    }

    /**
     * @param  string|array<string, mixed>  $field
     */
    private static function applyField(Builder $query, string|array $field, string $value, string $boolean): void
    {
        if (is_string($field)) {
            self::applyColumn($query, $field, self::CONTAINS, $value, $boolean);

            return;
        }

        $mode = $field['mode'] ?? self::CONTAINS;

        if (isset($field['relation'])) {
            $method = $boolean === 'or' ? 'orWhereHas' : 'whereHas';
            $columns = array_values($field['columns'] ?? []);

            $query->{$method}($field['relation'], function (Builder $related) use ($columns, $mode, $value): void {
                foreach ($columns as $index => $column) {
                    self::applyColumn($related, $column, $mode, $value, $index === 0 ? 'and' : 'or');
                }
            });

            return;
        }

        if (isset($field['columns'])) {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $columns = array_values($field['columns']);

            $query->{$method}(function (Builder $nested) use ($columns, $mode, $value): void {
                foreach ($columns as $index => $column) {
                    self::applyColumn($nested, $column, $mode, $value, $index === 0 ? 'and' : 'or');
                }
            });

            return;
        }

        self::applyColumn($query, $field['column'], $mode, $value, $boolean);
    }

    private static function applyColumn(
        Builder $query,
        string $column,
        string $mode,
        string $value,
        string $boolean,
    ): void {
        $operator = $mode === self::EXACT ? '=' : 'like';
        $needle = $mode === self::EXACT ? $value : "%{$value}%";

        $query->where($column, $operator, $needle, $boolean);
    }

    /**
     * @param  string|array<string, mixed>  $field
     */
    private static function isValidValue(string|array $field, string $value): bool
    {
        if (mb_strlen($value) > 191) {
            return false;
        }

        if (is_array($field) && ($field['type'] ?? null) === 'integer') {
            return strlen($value) <= 20 && ctype_digit($value);
        }

        return true;
    }

    /**
     * Keep the server-side whitelist in one place. Controllers only select a
     * preset; user input can never become a column or relation name.
     *
     * @return array{0: array<int, string|array<string, mixed>>, 1: array<string, string|array<string, mixed>>}
     */
    private static function preset(string $preset): array
    {
        $user = self::relation('user', ['username', 'email']);
        $userId = self::exact('user_id', 'integer');

        return match ($preset) {
            'users' => [
                ['username', 'email'],
                ['username' => self::text('username'), 'email' => self::text('email')],
            ],
            'roles' => [
                ['name'],
                ['name' => self::text('name')],
            ],
            'frontendClients' => [
                ['name', 'id', 'allowed_origins'],
                [
                    'client_id' => self::exact('id'),
                    'name' => self::text('name'),
                    'domain' => self::text('allowed_origins'),
                ],
            ],
            'gameTypes' => [
                ['name', 'slug'],
                ['name' => self::text('name'), 'slug' => self::exact('slug')],
            ],
            'categories' => [
                ['name', 'slug', 'template'],
                [
                    'name' => self::text('name'),
                    'slug' => self::exact('slug'),
                    'template' => self::exact('template'),
                ],
            ],
            'attributes' => [
                ['name', self::relation('options', ['option_value'])],
                [
                    'name' => self::text('name'),
                    'option' => self::relation('options', ['option_value']),
                ],
            ],
            'fields' => [
                ['label', 'field_key'],
                ['label' => self::text('label'), 'key' => self::exact('field_key')],
            ],
            'services' => [
                ['name', 'description'],
                ['name' => self::text('name')],
            ],
            'cardTypes' => [
                ['telco'],
                ['telco' => self::exact('telco')],
            ],
            'servers' => [
                ['name', 'name_view', 'ip', 'port'],
                [
                    'name' => ['columns' => ['name', 'name_view']],
                    'ip' => self::exact('ip'),
                    'port' => self::exact('port'),
                ],
            ],
            'serverGameLogins' => [
                ['name', 'ip', 'port'],
                ['name' => self::text('name'), 'ip' => self::exact('ip'), 'port' => self::exact('port')],
            ],
            'nicks' => [
                ['account_name', 'description', self::relation('user', ['username', 'email'])],
                [
                    'account' => self::text('account_name'),
                    'user' => self::relation('user', ['username', 'email']),
                    'user_id' => $userId,
                    'category_id' => self::exact('category_id', 'integer'),
                ],
            ],
            'nickOrders' => [
                [
                    self::relation('nick', ['account_name']),
                    self::relation('buyer', ['username', 'email']),
                    self::relation('seller', ['username', 'email']),
                ],
                [
                    'nick_id' => self::exact('nick_id', 'integer'),
                    'buyer' => self::relation('buyer', ['username', 'email']),
                    'buyer_id' => self::exact('buyer_id', 'integer'),
                    'seller' => self::relation('seller', ['username', 'email']),
                    'seller_id' => self::exact('seller_id', 'integer'),
                ],
            ],
            'randomBoxes' => [
                ['name', self::relation('category', ['name'])],
                ['name' => self::text('name'), 'category_id' => self::exact('category_id', 'integer')],
            ],
            'randomNicks' => [
                ['account', 'description', self::relation('randomBox', ['name'])],
                [
                    'account' => self::text('account'),
                    'box' => self::relation('randomBox', ['name']),
                    'random_box_id' => self::exact('random_box_id', 'integer'),
                ],
            ],
            'spins' => [
                ['name', 'description', self::relation('category', ['name'])],
                ['name' => self::text('name'), 'category_id' => self::exact('category_id', 'integer')],
            ],
            'spinResults' => [
                [$user, self::relation('spin', ['name']), 'reward_value'],
                [
                    'user' => $user,
                    'user_id' => $userId,
                    'spin' => self::relation('spin', ['name']),
                    'spin_id' => self::exact('spin_id', 'integer'),
                    'reward' => self::text('reward_value'),
                ],
            ],
            'spinTickets' => [
                [$user, self::relation('spin', ['name'])],
                [
                    'user' => $user,
                    'user_id' => $userId,
                    'spin' => self::relation('spin', ['name']),
                    'spin_id' => self::exact('spin_id', 'integer'),
                ],
            ],
            'bots' => [
                ['name', 'account_name', 'map_name', 'proxy'],
                [
                    'name' => self::text('name'),
                    'account' => self::text('account_name'),
                    'map' => self::text('map_name'),
                    'server_id' => self::exact('server_id', 'integer'),
                ],
            ],
            'botHistory' => [
                ['note', 'ip_address', self::relation('adminUser', ['username', 'email'])],
                [
                    'entity_id' => self::exact('entity_id', 'integer'),
                    'transaction_id' => self::exact('transaction_id', 'integer'),
                    'admin' => self::relation('adminUser', ['username', 'email']),
                    'admin_id' => self::exact('admin_user_id', 'integer'),
                    'ip' => self::exact('ip_address'),
                ],
            ],
            'prices' => [
                [self::relation('server', ['name', 'name_view'])],
                [
                    'server' => self::relation('server', ['name', 'name_view']),
                    'server_id' => self::exact('server_id', 'integer'),
                ],
            ],
            'goldOrders' => [
                ['character_name', $user, self::relation('bot', ['name', 'account_name'])],
                [
                    'character' => self::text('character_name'),
                    'user' => $user,
                    'user_id' => $userId,
                    'bot' => self::relation('bot', ['name', 'account_name']),
                    'bot_id' => self::exact('bot_id', 'integer'),
                ],
            ],
            'gemOrders' => [
                ['character_name', 'item', $user],
                [
                    'character' => self::text('character_name'),
                    'user' => $user,
                    'user_id' => $userId,
                    'item' => self::text('item'),
                ],
            ],
            'serviceOrders' => [
                [
                    'account',
                    'description',
                    $user,
                    self::relation('receiver', ['username', 'email']),
                    self::relation('service', ['name']),
                ],
                [
                    'account' => self::text('account'),
                    'user' => $user,
                    'user_id' => $userId,
                    'receiver' => self::relation('receiver', ['username', 'email']),
                    'receiver_id' => self::exact('receiver_id', 'integer'),
                    'service' => self::relation('service', ['name']),
                    'service_id' => self::exact('service_id', 'integer'),
                ],
            ],
            'cards' => [
                ['code', 'serial', 'trans_id', $user],
                [
                    'code' => self::exact('code'),
                    'serial' => self::exact('serial'),
                    'trans_id' => self::exact('trans_id'),
                    'user' => $user,
                    'user_id' => $userId,
                ],
            ],
            'bankTopups' => [
                ['provider_transaction_id', 'reference_code', 'payment_code', 'account_number', 'content', $user],
                [
                    'sepay_id' => self::exact('provider_transaction_id'),
                    'payment_code' => self::exact('payment_code'),
                    'reference' => self::exact('reference_code'),
                    'account_number' => self::exact('account_number'),
                    'user' => $user,
                    'user_id' => $userId,
                ],
            ],
            'carotRecharges' => [
                ['account_name', 'transaction_code', $user],
                [
                    'account' => self::text('account_name'),
                    'transaction' => self::exact('transaction_code'),
                    'user' => $user,
                    'user_id' => $userId,
                ],
            ],
            'transactions' => [
                ['description', $user, self::relation('performer', ['username', 'email'])],
                [
                    'user' => $user,
                    'user_id' => $userId,
                    'performer' => self::relation('performer', ['username', 'email']),
                    'related_id' => self::exact('related_id'),
                    'idempotency' => self::exact('idempotency_key'),
                ],
            ],
            'withdrawals' => [
                [
                    'bank_name',
                    'bank_account_number',
                    'bank_account_name',
                    'note_user',
                    'note',
                    $user,
                    self::relation('approver', ['username', 'email']),
                ],
                [
                    'user' => $user,
                    'user_id' => $userId,
                    'bank' => self::text('bank_name'),
                    'account_number' => self::exact('bank_account_number'),
                    'account_name' => self::text('bank_account_name'),
                    'approver' => self::relation('approver', ['username', 'email']),
                ],
            ],
            default => throw new \InvalidArgumentException("Unknown admin table search preset [{$preset}]."),
        };
    }
}
