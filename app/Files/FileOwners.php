<?php

declare(strict_types=1);

namespace App\Files;

use Illuminate\Database\Eloquent\Model;

/**
 * The owners a request has read, so FilePolicy asks the database for none of them twice; a caller
 * notes only a full row whose loaded relations match the database, since the gate decides on what
 * the instance holds. Scoped to the request or job (a console command is one scope), never static.
 */
final class FileOwners
{
    /** @var array<string, Model> by "class:id" */
    private array $owners = [];

    public function note(Model $owner): void
    {
        $this->owners[$owner::class.':'.$owner->getKey()] = $owner;
    }

    /**
     * Only a found owner is kept: one that is gone answers null and is asked for again.
     *
     * @param  class-string<Model>  $class
     */
    public function find(string $class, int $id): ?Model
    {
        $key = $class.':'.$id;

        if (! isset($this->owners[$key])) {
            $owner = $class::find($id);

            if ($owner === null) {
                return null;
            }

            $this->owners[$key] = $owner;
        }

        return $this->owners[$key];
    }
}
