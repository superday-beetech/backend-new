public function clearCache(
    ?string $endpoint = null,
    ?int $routeId = null,
    ?string $module = null
): void {
    if ($endpoint === null) {
        // Tanpa identitas endpoint, invalidasi seluruh cache route.
        $this->routeCache = [];
        $this->routeInfoCache = [];
    } else {
        $endpoint = trim($endpoint);

        if ($module !== null) {
            $module = trim($module);

            $prefix = $module . '|' . $endpoint . '|';

            foreach (array_keys($this->routeCache) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->routeCache[$key]);
                }
            }

            unset($this->routeInfoCache[$module . '|' . $endpoint]);
        } else {
            foreach (array_keys($this->routeCache) as $key) {
                $parts = explode('|', $key, 3);

                if (count($parts) === 3 && $parts[1] === $endpoint) {
                    unset($this->routeCache[$key]);
                }
            }

            foreach (array_keys($this->routeInfoCache) as $key) {
                $parts = explode('|', $key, 2);

                if (count($parts) === 2 && $parts[1] === $endpoint) {
                    unset($this->routeInfoCache[$key]);
                }
            }
        }
    }

    if ($routeId !== null) {
        unset(
            $this->routePermissionsCache[$routeId],
            $this->verbCache[$routeId]
        );
    } else {
        $this->routePermissionsCache = [];
        $this->verbCache = [];
    }
}
