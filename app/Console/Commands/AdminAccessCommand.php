<?php

namespace App\Console\Commands;

use App\Models\AdminAccessDevice;
use App\Services\AdminAccessService;
use Illuminate\Console\Command;

class AdminAccessCommand extends Command
{
    protected $signature = 'security:admin-access {action=list : list, approve, revoke, status, enable or disable} {id? : Access request ID}';

    protected $description = 'Manage admin device approvals and the approval policy from a trusted server console';

    public function handle(AdminAccessService $service): int
    {
        $action = $this->argument('action');
        if (in_array($action, ['status', 'enable', 'disable'], true)) {
            if ($action !== 'status') {
                $service->setApprovalRequired($action === 'enable');
            }
            $this->info('Admin login approval: '.($service->approvalRequired() ? 'ENABLED' : 'DISABLED'));

            return self::SUCCESS;
        }
        if ($action === 'list') {
            $this->table(['ID', 'User ID', 'Username', 'IP', 'Status', 'Last seen', 'User-Agent'],
                AdminAccessDevice::with('user:id,username')->latest('id')->limit(100)->get()->map(fn ($row) => [
                    $row->id, $row->user_id, $row->user?->username, $row->ip_address, $row->status,
                    $row->last_seen_at?->toDateTimeString(), $row->user_agent,
                ])->all());

            return self::SUCCESS;
        }
        if (! in_array($action, ['approve', 'revoke'], true) || ! $this->argument('id')) {
            $this->error('Use list, approve <request-id>, revoke <request-id>, status, enable or disable.');

            return self::FAILURE;
        }
        $device = AdminAccessDevice::findOrFail($this->argument('id'));
        $service->{$action}($device, null);
        $this->info('Updated request #'.$device->id.' for user #'.$device->user_id.' / '.$device->ip_address);

        return self::SUCCESS;
    }
}
