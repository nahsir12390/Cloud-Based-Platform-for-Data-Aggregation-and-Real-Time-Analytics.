<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');

            return response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store');
        } catch (Throwable) {
            Log::warning('Database readiness check failed.');

            return response()->json(['status' => 'unavailable'], 503)->header('Cache-Control', 'no-store');
        }
    }
}
