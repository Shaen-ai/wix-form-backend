<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Align request JSON keys (often stringified field ids) with form field primary keys.
 */
final class SubmissionDataNormalizer
{
    /**
     * @param  Collection<int|string, \App\Models\FormField>  $fieldsById
     * @return array<int|string, mixed>
     */
    public static function rekeyToFormFieldIds(array $data, Collection $fieldsById): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_int($key) && $fieldsById->has($key)) {
                $out[$key] = $value;

                continue;
            }

            if (is_string($key) && ctype_digit($key)) {
                $id = (int) $key;
                if ($fieldsById->has($id)) {
                    $out[$id] = $value;

                    continue;
                }
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
