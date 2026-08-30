<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceFieldController extends Controller
{
    public function index(Request $request)
    {
        $services = Service::select(['id', 'name', 'status'])
            ->with(['fields' => function ($q) {
                $q->select(['fields.id', 'label', 'field_key', 'type', 'required']);
            }])
            ->get();

        $selectedService = null;

        if ($request->service) {
            $selectedService = Service::select(['id', 'name'])
                ->with(['fields' => function ($q) {
                    $q->select(['fields.id', 'label', 'field_key', 'type']);
                }])
                ->find($request->service);

            if ($selectedService) {
                $selectedService->fields->each->makeHidden('pivot');
            }
        }

        return inertia('Admin/ServiceFields/Index', [
            'services' => $services,
            'selectedService' => $selectedService
        ]);
    }

    /**
     * Gán Fields cho Service
     */
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'field_ids' => 'required|array',
            'field_ids.*' => 'exists:fields,id',
        ]);

        $service = Service::find($validated['service_id']);
        $service->fields()->syncWithoutDetaching($validated['field_ids']);

        return redirect()->back()
            ->with('success', 'Gán field cho service thành công!');
    }

    /**
     * Bỏ gán Field khỏi Service
     */
    public function remove(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'field_id' => 'required|exists:fields,id',
        ]);

        $service = Service::find($validated['service_id']);
        $service->fields()->detach($validated['field_id']);

        return redirect()->back()
            ->with('success', 'Bỏ field khỏi service thành công!');
    }
}
