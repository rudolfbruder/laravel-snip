<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use RudolfBruder\LaravelSnip\Support\QueueSnapshot;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class QueueController extends Controller
{
    public function __construct(
        protected QueueSnapshot $snapshot,
        protected ConfigRepository $config,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->snapshot->enabled()) {
            throw new NotFoundHttpException;
        }

        if (! Gate::allows('viewSnip')) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $state = (string) $request->query('state', 'failed');
        $search = (string) $request->query('q', '');
        $page = max(1, (int) $request->query('page', 1));
        $includeSilenced = filter_var($request->query('include_silenced', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = match ($state) {
                'all' => $this->snapshot->all($search, $page, $includeSilenced),
                'failed' => $this->snapshot->failed($search, $page),
                'pending' => $this->snapshot->pending($search, $page),
                'scheduled' => $this->snapshot->scheduled($search, $page),
                'completed' => $this->snapshot->completed($search, $page, $includeSilenced),
                default => null,
            };
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'lookup_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        if ($result === null) {
            return response()->json(['error' => 'invalid_state'], 400);
        }

        if (! isset($result['counts'])) {
            $result['counts'] = $this->snapshot->counts($includeSilenced);
        }

        return response()->json($result);
    }
}
