<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Spin\SpinResource;
use App\Http\Resources\Spin\SpinRewardResource;
use App\Models\Spin;
use App\Models\SpinReward;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SpinRewardController extends Controller
{

    // app/Http/Controllers/Admin/SpinRewardController.php
    public function index(Spin $spin)
    {
        $rewards = $spin->rewards()->get();
        $totalProbability = $rewards->sum('probability');

        return Inertia::render('Admin/SpinRewards/Index', [
            // ✅ Dùng toArray thay vì để Laravel tự wrap
            'spin' => (new SpinResource($spin))->resolve(),
            'rewards' => SpinRewardResource::collection($rewards)->resolve(),
            'totalProbability' => round($totalProbability, 2),
            'remainingProbability' => round(100 - $totalProbability, 2),
        ]);
    }

    public function store(Request $request, Spin $spin)
    {
        $validated = $request->validate([
            'reward_type' => 'required|in:text,coin,gem,nick,item',
            'reward_value' => 'required|string|max:255',
            'image_file' => 'nullable|image|max:5120',
            'image_url' => 'nullable|url',
            'probability' => 'required|numeric|min:0|max:100',
        ]);

        $currentTotal = $spin->rewards()->sum('probability');
        if ($currentTotal + $validated['probability'] > 100) {
            return back()->withErrors([
                'probability' => 'Tổng xác suất vượt quá 100%. Còn lại: ' . (100 - $currentTotal) . '%'
            ])->withInput();
        }

        unset($validated['image_file'], $validated['image_url']);
        $validated['spin_id'] = $spin->id;

        $reward = SpinReward::create($validated);

        if ($request->hasFile('image_file')) {
            $reward->addMediaFromRequest('image_file')->toMediaCollection('image');
        } elseif ($request->filled('image_url')) {
            try {
                $reward->addMediaFromUrl($request->image_url)->toMediaCollection('image');
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($reward->hasMedia('image')) {
            $reward->update(['image' => $reward->getFirstMediaUrl('image')]);
        }

        return redirect()->route('admin.spins.rewards.index', $spin)
            ->with('success', 'Phần thưởng đã được thêm!');
    }

    public function update(Request $request, Spin $spin, SpinReward $reward)
    {
        if ($reward->spin_id !== $spin->id) {
            abort(404);
        }

        $validated = $request->validate([
            'reward_type' => 'required|in:text,coin,gem,nick,item',
            'reward_value' => 'required|string|max:255',
            'image_file' => 'nullable|image|max:5120',
            'image_url' => 'nullable|url',
            'probability' => 'required|numeric|min:0|max:100',
        ]);

        $currentTotal = $spin->rewards()
            ->where('id', '!=', $reward->id)
            ->sum('probability');

        if ($currentTotal + $validated['probability'] > 100) {
            return back()->withErrors([
                'probability' => 'Tổng xác suất vượt quá 100%. Còn lại: ' . (100 - $currentTotal) . '%'
            ])->withInput();
        }

        unset($validated['image_file'], $validated['image_url']);
        $reward->update($validated);

        if ($request->hasFile('image_file')) {
            $reward->clearMediaCollection('image');
            $reward->addMediaFromRequest('image_file')->toMediaCollection('image');
        } elseif ($request->filled('image_url') && $request->image_url !== $reward->getFirstMediaUrl('image')) {
            $reward->clearMediaCollection('image');
            try {
                $reward->addMediaFromUrl($request->image_url)->toMediaCollection('image');
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($reward->hasMedia('image')) {
            $reward->update(['image' => $reward->getFirstMediaUrl('image')]);
        }

        return redirect()->route('admin.spins.rewards.index', $spin)
            ->with('success', 'Phần thưởng đã được cập nhật!');
    }

    public function destroy(Spin $spin, SpinReward $reward)
    {
        if ($reward->spin_id !== $spin->id) {
            abort(404);
        }

        $reward->clearMediaCollection('image');
        $reward->delete();

        return redirect()->route('admin.spins.rewards.index', $spin)
            ->with('success', 'Phần thưởng đã được xóa!');
    }

    public function edit(Spin $spin, SpinReward $reward)
    {
        if ($reward->spin_id !== $spin->id) {
            abort(404);
        }

        return response()->json([
            'reward' => (new SpinRewardResource($reward->load('spin')))->resolve(),
            'spin' => (new SpinResource($spin))->resolve(),
        ]);
    }
}
