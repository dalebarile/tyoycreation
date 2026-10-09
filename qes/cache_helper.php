<?php
// ============================================================
// SYSTEM CACHING INFRASTRUCTURE FOR HIGH PERFORMANCE
// ============================================================

if (!class_exists('QesCache')) {
    class QesCache {
        private static array $memory = [];
        private static ?string $cache_dir = null;

        public static function getCacheDir(): string {
            if (self::$cache_dir === null) {
                $dir = __DIR__ . '/cache';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                if (!is_writable($dir)) {
                    $dir = sys_get_temp_dir() . '/tyoy_qes_cache';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                }
                self::$cache_dir = $dir;
            }
            return self::$cache_dir;
        }

        public static function get(string $key, mixed $default = null): mixed {
            if (array_key_exists($key, self::$memory)) {
                return self::$memory[$key];
            }

            $file = self::getCacheDir() . '/' . md5($key) . '.cache';
            if (is_file($file)) {
                $content = @file_get_contents($file);
                if ($content !== false) {
                    $data = @unserialize($content);
                    if (is_array($data) && isset($data['exp'], $data['val'])) {
                        if ($data['exp'] === 0 || $data['exp'] >= time()) {
                            self::$memory[$key] = $data['val'];
                            return $data['val'];
                        } else {
                            @unlink($file);
                        }
                    }
                }
            }

            return $default;
        }

        public static function set(string $key, mixed $val, int $ttl = 300): bool {
            self::$memory[$key] = $val;
            $file = self::getCacheDir() . '/' . md5($key) . '.cache';
            $exp = $ttl > 0 ? (time() + $ttl) : 0;
            $payload = serialize(['exp' => $exp, 'val' => $val]);
            return (bool)@file_put_contents($file, $payload, LOCK_EX);
        }

        public static function remember(string $key, int $ttl, callable $callback): mixed {
            $val = self::get($key);
            if ($val !== null) {
                return $val;
            }
            $val = $callback();
            self::set($key, $val, $ttl);
            return $val;
        }

        public static function delete(string $key): bool {
            unset(self::$memory[$key]);
            $file = self::getCacheDir() . '/' . md5($key) . '.cache';
            if (is_file($file)) {
                return @unlink($file);
            }
            return true;
        }

        public static function flush(): bool {
            self::$memory = [];
            $dir = self::getCacheDir();
            if (is_dir($dir)) {
                $files = glob($dir . '/*.cache');
                if ($files) {
                    foreach ($files as $f) {
                        @unlink($f);
                    }
                }
            }
            return true;
        }
    }
}
