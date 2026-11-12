<?php

namespace App\Session;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Carbon;

/**
 * A row that names a user idles for $memberMinutes, any other row for the framework's minutes
 * (docs/internals/sessions.md, "Lifetimes"). Null $memberMinutes is the framework's handler.
 */
class RealmDatabaseSessionHandler extends DatabaseSessionHandler
{
    public function __construct(
        ConnectionInterface $connection,
        string $table,
        int $minutes,
        ?Container $container = null,
        private readonly ?int $memberMinutes = null,
    ) {
        parent::__construct($connection, $table, $minutes, $container);
    }

    protected function expired($session)
    {
        if ($this->memberMinutes === null || ! isset($session->user_id)) {
            return parent::expired($session);
        }

        return isset($session->last_activity)
            && $session->last_activity < Carbon::now()->subMinutes($this->memberMinutes)->getTimestamp();
    }

    public function gc($lifetime): int
    {
        if ($this->memberMinutes === null) {
            return parent::gc($lifetime);
        }

        $now = $this->currentTime();

        return $this->getQuery()->whereNull('user_id')->where('last_activity', '<=', $now - $lifetime)->delete()
            + $this->getQuery()->whereNotNull('user_id')->where('last_activity', '<=', $now - $this->memberMinutes * 60)->delete();
    }
}
