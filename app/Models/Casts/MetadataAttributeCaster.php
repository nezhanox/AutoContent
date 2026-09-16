<?php

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class MetadataAttributeCaster implements CastsAttributes
{
    /**
     * Cast the stored value to the given type.
     */
    public function get($model, $key, $value, $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $data = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        // Ensure segments have consistent key order
        if (isset($data['segments']) && is_array($data['segments'])) {
            $data['segments'] = array_map(function ($segment) {
                return [
                    'start' => $segment['start'] ?? null,
                    'end' => $segment['end'] ?? null,
                    'text' => $segment['text'] ?? null,
                ];
            }, $data['segments']);
        }

        return $data;
    }

    /**
     * Prepare the given value for storage.
     */
    public function set($model, $key, $value, $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
