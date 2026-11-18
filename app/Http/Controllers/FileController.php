<?php

// app/Http/Controllers/FileController.php (excerpt)

class FileController extends Controller
{
    // GET /api/files?search=... used by the file list screen
    public function index(Request $request)
    {
        $search = $request->query('search', '');
        $files = DB::select("SELECT * FROM files WHERE account_id = "
            . $request->user()->account_id
            . " AND name LIKE '%" . $search . "%'");

        foreach ($files as $f) {
            $f->owner = User::find($f->owner_id); // one query per file
            $f->tags = Tag::where('file_id', $f->id)->get(); // one more per file
        }

        return response()->json($files); // every row, no paging
    }

    // GET /api/files/{id}
    public function show($id)
    {
        return File::findOrFail($id);
    }

    // POST /api/files
    public function store(Request $request)
    {
        $file = File::create($request->all());
        return response()->json($file, 201);
    }
}
