<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\orderlifecycle\controllers;

use Craft;

/**
 * A per-user cap on how often an action may reach the AI service.
 *
 * Both insight endpoints spend the site's Anthropic credit on request, and
 * permission to use one is not the same as permission to spend without limit.
 * A store manager holding the button down, or a dashboard left open with a
 * script in it, would otherwise run the account's budget down with nothing to
 * stop it.
 *
 * Counted in a fixed window rather than as a minimum interval between calls, so
 * somebody working through a run of orders one after another is let through
 * while the total stays bounded.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 2.0.0
 */
trait AiRateLimitTrait
{
    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Whether this user has used up the window's allowance, counting this call.
     *
     * A request with no user behind it is not counted: every action using this
     * checks a permission first, so there is always one by the time it is
     * reached, and a console context has no rate to limit.
     *
     * @param string $bucket A short name for the action being limited, so two
     *                       of them do not share one allowance.
     * @param int $limit The most calls allowed in a window.
     * @param int $window The window, in seconds.
     * @return bool True where the call should be refused.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 2.0.0
     */
    protected function aiRateLimitExceeded(string $bucket, int $limit, int $window): bool
    {
        $userId = Craft::$app->getUser()->getId();

        if ($userId === null) {
            return false;
        }

        $cache = Craft::$app->getCache();
        $mutex = Craft::$app->getMutex();

        // The window's own number, so the count expires with it rather than
        // sliding forward on every call.
        $slot = (int)floor(time() / $window);
        $key = "order-lifecycle:{$bucket}-rate:{$userId}:{$slot}";

        // Read and write under a lock, or requests sent together all read the
        // same count and all get through. One that can't get the lock in time
        // is refused rather than let through uncounted.
        if (!$mutex->acquire($key, 3)) {
            return true;
        }

        try {
            $count = (int)$cache->get($key);

            if ($count >= $limit) {
                return true;
            }

            $cache->set($key, $count + 1, $window);

            return false;
        } finally {
            $mutex->release($key);
        }
    }
}
