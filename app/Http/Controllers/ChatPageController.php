<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChatPageController extends Controller
{
    public function user(): Response
    {
        return Inertia::render('Messages/Index');
    }

    public function agent(Request $request): Response
    {
        abort_unless(
            $request->user()->status === User::STATUS_ACTIVE
                && $request->user()->isChatAgent()
                && $request->user()->can('chats.view'),
            403,
        );

        return Inertia::render('Admin/Chats/Index');
    }
}
