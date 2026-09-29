<?php

namespace App\Domain\Source\Support;

final class Utterance
{
    public function __construct(
        public readonly int $id,
        public readonly float $start,
        public readonly float $end,
        public readonly string $text,
    ) {}

    public function duration(): float
    {
        return $this->end - $this->start;
    }

    /**
     * @return array{id:int,start:float,end:float,text:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'start' => $this->start,
            'end' => $this->end,
            'text' => $this->text,
        ];
    }

    /**
     * @param  array{id:int,start:float|int,end:float|int,text:string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            start: (float) $data['start'],
            end: (float) $data['end'],
            text: $data['text'],
        );
    }
}
