<?php

declare(strict_types=1);

namespace Demo\App\Http\Controllers;

use Demo\App\Models\Note;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SConcur\WaitGroup;
use Throwable;

/**
 * Eloquent over the non-blocking connection. Nothing here is written for the runtime —
 * this is the ordinary model code an application already has, and it is the point: the
 * connection is chosen in config/database.php and nothing above it changes.
 */
class NoteController
{
    protected const int MAX_BULK = 50;

    public function index(): JsonResponse
    {
        /** @var Collection<int, Note> $notes */
        $notes = Note::query()
            ->latest('id')
            ->limit(20)
            ->get(['id', 'title', 'body', 'created_at']);

        return response()->json([
            'connection' => new Note()->getConnectionName() ?? (string) config('database.default'),
            'total'      => Note::query()->count(),
            // Listed field by field: a row off sconcur_mysql carries no stable key order
            // (README, "Отличия от PDO"), so handing the models to json_encode would
            // reshuffle the keys of this answer from one request to the next.
            'notes'      => $notes->map(static fn(Note $note): array => [
                'id'         => $note->id,
                'title'      => $note->title,
                'body'       => $note->body,
                'created_at' => $note->created_at?->toDateTimeString(),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body'  => ['required', 'string', 'max:2000'],
        ]);

        $note = Note::query()->create($data);

        return response()->json([
            'note'      => [
                'id'    => $note->id,
                'title' => $note->title,
                'body'  => $note->body,
            ],
            'workerPid' => getmypid() ?: 0,
        ], 201);
    }

    /**
     * Several inserts at once, each in its own coroutine, and each in a transaction of
     * its own. On the sconcur_mysql connection that works: the nesting level lives in
     * the coroutine context and the extension pins the transaction to a physical
     * connection of its own, so the neighbours cannot join it. On the PDO `mysql`
     * connection the handle is one per process and this is exactly what would go wrong
     * — switch DB_CONNECTION to see it.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_BULK],
        ]);

        $count     = (int) ($data['count'] ?? 10);
        $startedAt = microtime(true);
        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < $count; $index++) {
            $waitGroup->add(static function () use ($index): int {
                return DB::transaction(static function () use ($index): int {
                    $note = Note::query()->create([
                        'title' => 'bulk #' . $index,
                        'body'  => 'written concurrently at ' . now()->toDateTimeString(),
                    ]);

                    return (int) $note->id;
                });
            });
        }

        try {
            $ids = array_values($waitGroup->waitResults());
        } catch (Throwable $exception) {
            return response()->json([
                'error' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'created'    => count($ids),
            'ids'        => $ids,
            'elapsedMs'  => (int) round((microtime(true) - $startedAt) * 1000),
            'connection' => new Note()->getConnectionName() ?? (string) config('database.default'),
            'workerPid'  => getmypid() ?: 0,
        ], 201);
    }
}
