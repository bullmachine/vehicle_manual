<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->with('user');
        if ($request->filled('module')) {
            $query->where('module', $request->input('module'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(fn ($builder) => $builder->where('action', 'like', "%{$term}%")->orWhere('module', 'like', "%{$term}%")->orWhere('record_id', 'like', "%{$term}%"));
        }
        $query->latest('created_at');

        return response()->json($query->paginate($this->perPage($request, 30)));
    }
}
