<?php

namespace App\Http\Controllers\Common;

use App\Http\Controllers\Controller;
use App\Models\UserActivity;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function track(Request $request)
    {
        $request->validate([
            'activity_type' => 'required|string',
            'entity_id' => 'nullable|integer',
            'entity_type' => 'nullable|string',
            'metadata' => 'nullable|array',
            'session_id' => 'nullable|string',
        ]);

        UserActivity::create([
            'user_id' => auth('user')->id(),
            'session_id' => $request->session_id,
            'activity_type' => $request->activity_type,
            'entity_id' => $request->entity_id,
            'entity_type' => $request->entity_type,
            'metadata' => $request->metadata,
        ]);

        return response()->json(['status' => 'success']);
    }
}
