<?php

declare(strict_types=1);

namespace RudolfBruder\LaravelSnip\Http\Controllers;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class SnipAssetController
{
    public function __construct(protected AuthFactory $auth) {}

    public function __invoke(Request $request): Response
    {
        $user = $this->auth->guard()->user();

        if (! Gate::forUser($user)->allows('viewSnip')) {
            return new Response('Not Found', 404);
        }

        $path = realpath(__DIR__.'/../../../dist/snip.js');

        if ($path === false || ! is_file($path)) {
            return new Response('Snip bundle not found. Run `npm run build` inside the package.', 500);
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setEtag((string) filemtime($path));
        $response->isNotModified($request);

        return $response;
    }
}
