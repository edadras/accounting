<?php

declare(strict_types=1);

namespace Modules\Capture\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Capture\Actions\CaptureEmail;
use Modules\Capture\Http\Resources\CaptureMessageResource;

/**
 * The inbound-mail webhook.
 *
 * The provider has already parsed the MIME; what arrives is the pieces. The
 * `to` address is the routing decision and the shared secret checked by
 * VerifyCaptureSecret is the authentication — there is no session here and no
 * X-Workspace-Id to trust.
 */
final class EmailWebhookController
{
    public function __invoke(Request $request, CaptureEmail $capture): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:191'],
            'from' => ['sometimes', 'nullable', 'string', 'max:191'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:500'],
            'text' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'message_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'received_at' => ['sometimes', 'nullable', 'date'],
            'attachments' => ['sometimes', 'array', 'max:50'],
            'attachments.*.filename' => ['required', 'string', 'max:191'],
            'attachments.*.content_type' => ['sometimes', 'nullable', 'string', 'max:191'],
            'attachments.*.content' => ['required', 'string'],
        ]);

        return (new CaptureMessageResource($capture->handle($data)->load('draft')))
            ->response()
            ->setStatusCode(201);
    }
}
