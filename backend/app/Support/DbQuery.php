<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Log;

class DbQuery
{
    /**
     * Run a database query, providing automatic logging on failure and returning a QueryResult.
     * 
     * @param Closure $queryFn The closure executing the query.
     * @param string $context Description of the query for logging.
     * @return QueryResult
     */
    public static function run(Closure $queryFn, string $context): QueryResult
    {
        try {
            $result = $queryFn();
            $isFailed = empty($result);
            if ($isFailed) {
                Log::warning("DbQuery failed or returned empty result", ['context' => $context]);
            }

            return new QueryResult($result, $isFailed);
        } catch (\Exception $e) {
            Log::error("DbQuery Exception", [
                'context' => $context,
                'error' => $e->getMessage()
            ]);

            return new QueryResult(null, true);
        }
    }
}
