<?php

namespace App\Support\Stream;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** @template TModel of Model */
final readonly class StreamPage
{
    /**
     * @param  Collection<int, TModel>  $rows  newest first
     * @param  bool  $hasOlder  whether rows lie beyond the last one here
     */
    public function __construct(
        public Collection $rows,
        public bool $hasOlder,
        private string $column = 'created_at',
    ) {}

    public function olderCursor(): ?StreamCursor
    {
        return $this->hasOlder ? StreamCursor::of($this->rows->last(), $this->column) : null;
    }

    /**
     * @param  callable(TModel): mixed  $row
     * @return array{data: list<mixed>}
     */
    public function payload(callable $row): array
    {
        return ['data' => $this->rows->map($row)->values()->all()];
    }
}
