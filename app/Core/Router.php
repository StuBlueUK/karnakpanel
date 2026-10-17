<?php
declare(strict_types=1);

class Router {
    private array $routes = [];
    public function get(string $path, callable|array $h): void { $this->routes['GET'][$path] = $h; }
    public function post(string $path, callable|array $h): void { $this->routes['POST'][$path] = $h; }
    public function any(string $path, callable|array $h): void {
        $this->routes['GET'][$path] = $h; $this->routes['POST'][$path] = $h;
    }
    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        // normalize trailing slash
        if ($uri !== '/' ) $uri = rtrim($uri, '/');
        $handler = $this->routes[$method][$uri] ?? null;
        // dynamic /file-manager/browse? handled via query, keep simple exact match
        if (!$handler) {
            // try prefix match for /api/*
            foreach ($this->routes[$method] as $route => $h) {
                if (str_ends_with($route, '*')) {
                    $prefix = rtrim($route, '*');
                    if (str_starts_with($uri, $prefix)) { $handler = $h; break; }
                }
            }
        }
        if (!$handler) { http_response_code(404); echo "404 Not Found: ".Helpers::e($uri); return; }
        if (is_array($handler)) {
            [$class, $m] = $handler;
            // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation -- class from static route table, never request data (front-controller dispatch)
            (new $class)->$m();
        } else { $handler(); }
    }
}
