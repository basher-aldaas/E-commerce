<?php

namespace App\Http\Middleware;

use App\Support\Database\ReadRouting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Keeps each API request on the primary (connections already start there, see
 * AppServiceProvider) unless its route opts in with `reads.replica`. After a request that changed rows, the user is marked so their next
 * reads also use the primary for a few seconds (read-your-writes across requests;
 * `sticky` in config/database.php only covers the same request).
 */
class PrimaryReadsByDefault
{
    public function __construct(private readonly ReadRouting $routing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->routing->usePrimary();
        $connection = DB::connection();
        $connection->forgetRecordModificationState();

        $response = $next($request);

        // Only a request that really changed rows counts as a write (signing in
        // issues a JWT but writes nothing, so it does not pin the user to the primary).
        if ($connection->hasModifiedRecords() && ($user = auth('api')->user())) {
            $this->markWrite((int) $user->getAuthIdentifier());
        }

        return $response;
    }

    /**
     * The request's changes are already committed when this runs. If the marker
     * can't be stored (Redis down), turning the response into a 500 would tell the
     * client that a committed write failed, so the response stands and the failure
     * is logged: this user may then read the replica, possibly behind, for up to
     * database.read_your_writes_seconds.
     */
    private function markWrite(int $userId): void
    {
        try {
            $this->routing->markWrite($userId);
        } catch (Throwable $e) {
            Log::error('Could not store the read-your-writes marker; the committed response is sent anyway', [
                'user_id' => $userId,
                'exception' => $e,
            ]);
        }
    }
}
