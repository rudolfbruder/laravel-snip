<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use RudolfBruder\LaravelSnip\Support\CapturingDecision;
use RudolfBruder\LaravelSnip\Support\MailInbox;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the mail the `snip` transport caught to the panel's Mail tab: the newest mails as a list,
 * one mail in full, and emptying the inbox. Everything behind the same check as the panel itself,
 * since caught mail holds other people's password-reset links and the like.
 */
class MailInboxController extends Controller
{
    public function __construct(
        protected MailInbox $inbox,
        protected CapturingDecision $decision,
    ) {}

    public function index(): JsonResponse
    {
        $this->ensureAllowed();

        return response()->json(['mails' => $this->inbox->summaries()]);
    }

    public function show(string $id): JsonResponse
    {
        $this->ensureAllowed();

        $mail = $this->inbox->find($id);

        if ($mail === null) {
            return response()->json(['error' => 'not_found', 'message' => 'This mail is no longer in the inbox.'], 404);
        }

        return response()->json($mail);
    }

    public function destroy(): JsonResponse
    {
        $this->ensureAllowed();

        $this->inbox->clear();

        return response()->json(['mails' => []]);
    }

    protected function ensureAllowed(): void
    {
        if (! $this->inbox->enabled()) {
            throw new NotFoundHttpException;
        }

        abort_unless($this->decision->allows(), 403);
    }
}
