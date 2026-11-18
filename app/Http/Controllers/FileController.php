<?php

// app/Http/Controllers/FileController.php (excerpt)

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileController extends Controller
{
    // GET /api/files?search=... used by the file list screen
    public function index(Request $request): JsonResponse
    {
        $search = (string) $request->query('search', '');
        $accountId = $request->user()->account_id;

        // BRD-301 & BRD-304 Fix: Parameterized query builder prevents SQL injection;
        // Eager-loading ('owner', 'tags') eliminates N+1 queries; pagination bounds memory
        $files = File::query()
            ->where('account_id', $accountId)
            ->when($search !== '', function ($query) use ($search) {
                $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $query->where('name', 'LIKE', '%' . $escapedSearch . '%');
            })
            ->with([
                'owner:id,name,email',
                'tags:id,file_id,name',
            ])
            ->orderBy('id', 'desc')
            ->paginate(50);

        return response()->json($files);
    }

    // GET /api/files/{id}
    // Fixed IDOR: Scopes file lookup to the authenticated user's account
    public function show(Request $request, int $id): JsonResponse
    {
        $file = File::query()
            ->where('account_id', $request->user()->account_id)
            ->with(['owner:id,name,email', 'tags:id,file_id,name'])
            ->findOrFail($id);

        return response()->json($file);
    }

    // POST /api/files
    // Fixed IDOR and Mass Assignment: Validates input and sets account_id & owner_id from session
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'external_id' => ['required', 'string', 'max:255'],
            'name'        => ['required', 'string', 'max:255'],
            'is_shared'   => ['sometimes', 'boolean'],
        ]);

        $file = File::create([
            'account_id'  => $request->user()->account_id,
            'owner_id'    => $request->user()->id,
            'external_id' => $validated['external_id'],
            'name'        => $validated['name'],
            'is_shared'   => $validated['is_shared'] ?? false,
        ]);

        return response()->json($file, 201);
    }
}
