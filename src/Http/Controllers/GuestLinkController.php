<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Http\Controllers;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use RudolfBruder\LaravelSnip\Support\CapturingDecision;
use RudolfBruder\LaravelSnip\Support\GuestAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manages the guest link from the panel's settings, and turns the link into a cookie when a guest
 * opens it.
 *
 * Managing the link needs the gate itself: a guest who got in through a link cannot create,
 * replace or revoke one.
 *
 * Creating, revoking and opening a link are written to the default log channel with who did it
 * and from where. The token itself is never logged, only a short fingerprint of it.
 */
class GuestLinkController extends Controller
{
    public function __construct(
        protected GuestAccess $guestAccess,
        protected CapturingDecision $decision,
        protected AuthFactory $auth,
        protected ConfigRepository $config,
    ) {}

    public function show(): JsonResponse
    {
        $this->authorizeManagement();

        return $this->linkResponse($this->guestAccess->current());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManagement();

        $previous = $this->guestAccess->current();
        $link = $this->guestAccess->create($this->actor());

        Log::info('[laravel-snip] guest link created', $this->logContext($request, $link) + [
            'expires_at' => date(DATE_ATOM, $link['expires_at']),
            'replaced_link' => $previous === null ? null : $this->guestAccess->fingerprint($previous['token']),
        ]);

        return $this->linkResponse($link);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->authorizeManagement();

        $previous = $this->guestAccess->current();
        $this->guestAccess->revoke();

        if ($previous !== null) {
            Log::info('[laravel-snip] guest link revoked', $this->logContext($request, $previous) + [
                'revoked_by' => $this->actor(),
                'created_by' => $previous['created_by'] ?? null,
            ]);
        }

        return $this->linkResponse(null);
    }

    /**
     * Unknown, replaced and expired tokens all answer 404, so the route says nothing about whether a
     * link exists.
     */
    public function enter(Request $request, string $token): RedirectResponse
    {
        $link = $this->guestAccess->current();

        if ($link === null || ! $this->guestAccess->accepts($token)) {
            throw new NotFoundHttpException;
        }

        Log::info('[laravel-snip] guest link opened', $this->logContext($request, $link) + [
            'created_by' => $link['created_by'] ?? null,
            'user_agent' => (string) $request->userAgent(),
        ]);

        return redirect('/')->withCookie($this->guestAccess->cookie($link, $request));
    }

    protected function authorizeManagement(): void
    {
        if (! $this->guestAccess->enabled()) {
            throw new NotFoundHttpException;
        }

        abort_unless($this->decision->allowsByGate(), 403);
    }

    /**
     * The logged-in user managing the link, by email when the model has one.
     */
    protected function actor(): string
    {
        $user = $this->auth->guard($this->config->get('snip.guard'))->user();

        if ($user === null) {
            return 'unknown';
        }

        $email = $user->email ?? null;

        return is_string($email) && $email !== '' ? $email : 'user #'.$user->getAuthIdentifier();
    }

    /**
     * @param  array{token: string}  $link
     * @return array<string, mixed>
     */
    protected function logContext(Request $request, array $link): array
    {
        return [
            'link' => $this->guestAccess->fingerprint($link['token']),
            'ip' => $request->ip(),
        ];
    }

    /** @param  array{token: string, expires_at: int, created_at?: int, created_by?: string}|null  $link */
    protected function linkResponse(?array $link): JsonResponse
    {
        return response()->json([
            'url' => $link === null ? null : route('snip.guest.enter', ['token' => $link['token']]),
            'expires_at' => $link['expires_at'] ?? null,
            'created_at' => $link['created_at'] ?? null,
            'created_by' => $link['created_by'] ?? null,
        ])->header('Cache-Control', 'private, no-store');
    }
}
