<?php

namespace App\Console\Commands;

use App\Models\AdminAccessDevice;
use App\Services\AdminAccessService;
use Illuminate\Console\Command;

class AdminAccessCommand extends Command
{
    protected $signature = 'security:admin-access {action=list : list, approve or revoke} {id? : Access request ID}';

    protected $description = 'List or approve/revoke an admin device and exact IP from a trusted server console';

    public function handle(AdminAccessService $service): int
    {
        $action = $this->argument('action');
        if ($action === 'list') {
            $this->table(['ID', 'User ID', 'Username', 'IP', 'Status', 'Last seen', 'User-Agent'],
                AdminAccessDevice::with('user:id,username')->latest('id')->limit(100)->get()->map(fn ($row) => [
                    $row->id, $row->user_id, $row->user?->username, $row->ip_address, $row->status,
                    $row->last_seen_at?->toDateTimeString(), $row->user_agent,
                ])->all());

            return self::SUCCESS;
        }
        if (! in_array($action, ['approve', 'revoke'], true) || ! $this->argument('id')) {
            $this->error('Use list, approve <request-id> or revoke <request-id>.');

            return self::FAILURE;
        }
        $device = AdminAccessDevice::findOrFail($this->argument('id'));
        $service->{$action}($device, null);
        $this->info('Updated request #'.$device->id.' for user #'.$device->user_id.' / '.$device->ip_address);

        return self::SUCCESS;
    }
}
