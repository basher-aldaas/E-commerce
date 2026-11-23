<?php

namespace App\Http\Middleware;

use App\Constants\HttpStatusConstants;
use App\Http\Responses\Response;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A request that says its body is JSON but sends something that is not valid JSON
 * gets 400 Bad Request. Laravel would otherwise read it as an empty body and
 * answer 422 "field is required", which hides the real problem. An empty body is
 * not malformed (it simply has no fields), so it still reaches validation.
 */
class RejectMalformedJson
{
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if ($request->isJson() && trim($request->getContent()) !== '' && ! json_validate($request->getContent())) {
            return Response::Error(
                data: [],
                message: 'Malformed JSON body.',
                code: HttpStatusConstants::HTTP_400_BAD_REQUEST
            );
        }

        return $next($request);
    }
}
