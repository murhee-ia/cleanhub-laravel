<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * The platform's tunable limits. Reads and writes go through the Setting model,
 * which fills defaults for any key not yet stored, so the settings form always
 * has a complete picture even on a fresh install.
 */
class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Setting::currentValues());
    }

    /**
     * Persist only the keys that were sent — a partial update leaves the rest
     * untouched — and record the change for the audit trail.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $changes = $request->validated();

        foreach ($changes as $key => $value) {
            Setting::setValue($key, (int) $value);
        }

        AuditLog::record($request->user(), 'settings.updated', null, ['changes' => $changes]);

        return response()->json(Setting::currentValues());
    }
}
