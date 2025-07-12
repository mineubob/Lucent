<?php

namespace Lucent\Database;

class Dataset
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get(string $key, $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, $value): Dataset
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function transform(array $transformations): Dataset
    {
        $newData = $this->data;

        foreach ($transformations as $key => $transformer) {
            if (array_key_exists($key, $newData) && is_callable($transformer)) {
                $newData[$key] = $transformer($newData[$key]);
            }
        }

        return new Dataset($newData);
    }

    public function with(array $additions): Dataset
    {
        return new Dataset(array_merge($this->data, $additions));
    }

    public function only(string|array $keys): Dataset
    {
        $keysArray = is_array($keys) ? $keys : [$keys];
        $filtered = array_intersect_key($this->data, array_flip($keysArray));
        return new Dataset($filtered);
    }

    public function except(array $keys): Dataset
    {
        $new = $this->data;
        foreach ($keys as $key) {
            unset($new[$key]);
        }
        return new Dataset($new);
    }

    public function integer(string $key, int $default = -1): int
    {
        return array_key_exists($key, $this->data) ? (int)$this->data[$key] : $default;
    }

    public function array(): array
    {
        return $this->data;
    }
}