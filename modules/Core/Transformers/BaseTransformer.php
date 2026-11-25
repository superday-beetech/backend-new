<?php

namespace Modules\Core\Transformers;

use DateTimeInterface;
use JsonSerializable;
use InvalidArgumentException;

abstract class BaseTransformer
{
    /**
     * Mengubah satu record menjadi array API.
     */
    abstract public function transform(array|object $item): array;

    /**
     * Mengubah banyak record.
     */
    public function collection(iterable $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $result[] = $this->transform($item);
        }

        return $result;
    }

    /**
     * Mengubah data menjadi array.
     */
    protected function toArray(array|object $item): array
    {
        if (is_array($item)) {
            return $item;
        }

        if ($item instanceof JsonSerializable) {
            $data = $item->jsonSerialize();

            if (is_array($data)) {
                return $data;
            }
        }

        return get_object_vars($item);
    }

    /**
     * Mengambil nilai field dengan aman.
     */
    protected function get(
        array|object $item,
        string $key,
        mixed $default = null
    ): mixed {
        $data = $this->toArray($item);

        return $data[$key] ?? $default;
    }

    /**
     * Mengubah nilai menjadi integer.
     */
    protected function toInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * Mengubah nilai menjadi float.
     */
    protected function toFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * Mengubah nilai menjadi boolean.
     */
    protected function toBool(mixed $value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }

    /**
     * Mengubah tanggal menjadi ISO 8601.
     */
    protected function toDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        try {
            return (new \DateTimeImmutable((string) $value))
                ->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Memastikan nilai berupa string.
     */
    protected function toString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * Memilih field tertentu dari data.
     */
    protected function only(
        array|object $item,
        array $fields
    ): array {
        $data = $this->toArray($item);

        return array_intersect_key(
            $data,
            array_flip($fields)
        );
    }

    /**
     * Menghapus field yang tidak diperlukan.
     */
    protected function except(
        array|object $item,
        array $fields
    ): array {
        $data = $this->toArray($item);

        return array_diff_key(
            $data,
            array_flip($fields)
        );
    }

    /**
     * Memastikan hasil transformasi berupa array.
     */
    protected function ensureArray(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                'Transformer output must be an array.'
            );
        }

        return $value;
    }
}
