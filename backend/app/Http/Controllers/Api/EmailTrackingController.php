<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BroadcastService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Public open pixel and signed click redirects for campaign emails. */
class EmailTrackingController extends Controller
{
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(string $token, BroadcastService $broadcasts): Response
    {
        $broadcasts->trackOpen($token);

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, string $token, string $signature, BroadcastService $broadcasts): RedirectResponse
    {
        $url = $broadcasts->trackClick($token, $signature, (string) $request->query('u', ''));
        abort_unless($url, 404);

        return redirect()->away($url);
    }
}
