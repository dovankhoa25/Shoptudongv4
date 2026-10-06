<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nick\StoreNickPublicationRequest;
use App\Models\NickMediaFile;
use App\Models\NickPublication;
use App\Services\NickPublicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class NickPublicationController extends Controller
{
    public function create(Request $request)
    {
        return app(NickController::class)->create($request)->with('backgroundUpload', true);
    }

    public function upload(Request $request, NickPublicationService $service)
    {
        $request->validate(['image' => 'required|file|image|mimes:jpg,jpeg,png,gif,webp|max:5120']);
        $file = $service->upload($request->user(), $request->file('image'));

        return response()->json(['id' => $file->id], 201);
    }

    public function removeUpload(Request $request, string $id)
    {
        DB::transaction(function () use ($request, $id) {
            $file = NickMediaFile::whereKey($id)->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            abort_if($file->publication_id, 409, 'Ảnh đã thuộc một bản đăng.');
            if ($file->path) {
                Storage::disk('nick-staging')->delete($file->path);
            }
            $file->delete();
        });

        return response()->noContent();
    }

    public function store(StoreNickPublicationRequest $request, NickPublicationService $service)
    {
        $publication = $service->submit($request->user(), $request->validated());

        return response()->json(['publication' => $this->summary($this->summaryQuery()->findOrFail($publication->id))], 202);
    }

    public function index(Request $request)
    {
        $publications = $this->summaryQuery()
            ->when(! $request->user()->canViewAllAdminData(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderByDesc('id')->paginate(20)->through(fn ($publication) => $this->summary($publication));
        if ($request->expectsJson()) {
            return response()->json(['publications' => $publications]);
        }

        return Inertia::render('Admin/Nicks/Publications', ['publications' => $publications]);
    }

    public function retry(Request $request, string $uuid, NickPublicationService $service)
    {
        $publication = $this->owned($request, $uuid);
        $service->retry($publication);

        return response()->json(['publication' => $this->summary($this->summaryQuery()->findOrFail($publication->id))]);
    }

    public function cancel(Request $request, string $uuid, NickPublicationService $service)
    {
        $publication = $this->owned($request, $uuid);
        $service->cancel($publication);

        return response()->json(['publication' => $this->summary($this->summaryQuery()->findOrFail($publication->id))]);
    }

    private function owned(Request $request, string $uuid): NickPublication
    {
        return NickPublication::where('uuid', $uuid)
            ->when(! $request->user()->canViewAllAdminData(), fn ($q) => $q->where('user_id', $request->user()->id))->firstOrFail();
    }

    private function summary(NickPublication $publication): array
    {
        return [
            'uuid' => $publication->uuid, 'account_name' => $publication->account_name,
            'status' => $publication->status, 'nick_id' => $publication->nick_id,
            'error' => $publication->error, 'created_at' => $publication->created_at->toIso8601String(),
            'total' => (int) $publication->total_count, 'ready' => (int) $publication->ready_count,
            'processing' => (int) $publication->processing_count,
            'failed' => (int) $publication->failed_count,
            'errors' => $publication->failedFiles->map(fn ($file) => [
                'position' => $file->position + 1, 'name' => $file->name, 'message' => $file->error,
            ])->values()->all(),
        ];
    }

    private function summaryQuery(): Builder
    {
        // Polling needs counters and failed-file details, never credentials, sources,
        // disk paths, or every successful/waiting file in the batch.
        return NickPublication::query()
            ->select(['id', 'uuid', 'account_name', 'status', 'nick_id', 'error', 'created_at'])
            ->withCount([
                'files as total_count',
                'files as ready_count' => fn ($q) => $q->where('status', 'ready'),
                'files as processing_count' => fn ($q) => $q->where('status', 'processing'),
                'files as failed_count' => fn ($q) => $q->where('status', 'failed'),
            ])
            ->with('failedFiles:id,publication_id,position,name,error');
    }
}
