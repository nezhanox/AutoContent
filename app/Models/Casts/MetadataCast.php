<?php

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class MetadataCast implements Castable
{
    /**
     * @return class-string<CastsAttributes>
     */
    public static function castUsing(array $arguments): string
    {
        return MetadataAttributeCaster::class;
    }
}
