<?php

/** Synthetic code is analysed, never executed; unused cases must remain in the report. */
namespace Fabrement\Api\Fields {
    final class Probe {
        public function generatedOnly(): void { $this->helper(); }
        private function helper(): void {}
        private function deadPrivate(): void {}
        /** @internal */
        public function internalOnly(): void {}
        private function __clone() {}
    }
}

namespace Fabrement\Sdk {
    // Lite removed the SDK contract; its protected methods must no longer be roots.
    abstract class ProbePage {
        protected function generatedSubclassOnly(): void { $this->helper(); }
        private function helper(): void {}
        private function deadPrivate(): void {}
    }
}

namespace Fabrement\Modules\Mcp\Ability {
    interface AbilityInterface { public function execute(array $input); }
    final class ProbeAbility implements AbilityInterface {
        public function execute(array $input) { return $this->helper(); }
        private function helper(): array { return []; }
    }
}

namespace Fabrement\Route {
    final class InternalPostsRestController {
        public function get_items_permissions_check(): bool { return $this->readPermission(); }
        public function get_item_permissions_check(): bool { return $this->readPermission(); }
        public function check_read_permission(): bool { return $this->readPermission(); }
        private function readPermission(): bool { return true; }
        private function deadPrivate(): void {}
    }
}

namespace Fabrement\Modules\Seo\Integration {
    final class YoastSchemaPiece {
        public function is_needed(): bool { return $this->nodes() !== []; }
        public function generate(): array { return $this->nodes(); }
        private function nodes(): array { return []; }
    }
}

namespace WP\MCP\Infrastructure\ErrorHandling\Contracts {
    interface McpErrorHandlerInterface { public function log(string $message): void; }
}

namespace DeadCodeFixture {
    final class ErrorHandler implements \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface {
        public function log(string $message): void { $this->write($message); }
        private function write(string $message): void {}
    }
    function add_action(string $hook, callable $callback): void {}
    function add_filter(string $hook, callable $callback): void {}
    function register_rest_route(string $namespace, string $route, array $args): void {}
    final class Worker {
        public static function onHook(): void { self::hookHelper(); }
        private static function hookHelper(): void {}
        public function onFilter(): void {}
        public function endpoint(): void {}
        public function permission(): bool { return true; }
        public static function unused(): void {}
    }
    add_action('init', [Worker::class, 'onHook']);
    add_filter('example', [new Worker(), 'onFilter']);
    register_rest_route('example/v1', '/test', [
        'callback' => [new Worker(), 'endpoint'],
        'permission_callback' => [new Worker(), 'permission'],
    ]);
}
