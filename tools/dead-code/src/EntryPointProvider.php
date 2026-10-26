<?php

namespace Fabrement\Dev\DeadCode;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/** Keep external public contracts alive without hiding private implementation methods. */
final class EntryPointProvider extends ReflectionBasedMemberUsageProvider {
    private array $entries;

    public function __construct() {
        $this->entries = require dirname(__DIR__) . '/entry-points.php';
    }

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData {
        if ($method->getName() === '__clone' && !$method->isPublic()) {
            return VirtualUsageData::withNote('Non-public __clone prevents callers from cloning the object');
        }
        if ($method->isProtected()) {
            foreach ($this->entries['protectedPrefixes'] as $prefix) {
                if (str_starts_with($method->getDeclaringClass()->getName(), $prefix)
                    && !str_contains((string) $method->getDocComment(), '@internal')) {
                    return VirtualUsageData::withNote('Protected SDK helper called by generated page subclasses');
                }
            }
        }
        if (!$method->isPublic()) {
            return null;
        }
        $class = $method->getDeclaringClass();
        if (in_array($method->getName(), $this->entries['methods'][$class->getName()] ?? [], true)) {
            return VirtualUsageData::withNote('WordPress or integration dispatches this callback externally');
        }
        if ($this->isPublicContract($class) && !str_contains((string) $method->getDocComment(), '@internal')) {
            return VirtualUsageData::withNote('Public PHP contract used by AI-generated site code or integrations');
        }
        foreach ($this->entries['interfaces'] as $interface => $methods) {
            if (($class->getName() === $interface || $class->implementsInterface($interface))
                && in_array($method->getName(), $methods, true)) {
                return VirtualUsageData::withNote('External interface contract invoked through a registry');
            }
        }
        return null;
    }

    protected function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): ?VirtualUsageData {
        return $constant->isPublic() && $this->isPublicContract($constant->getDeclaringClass())
            ? VirtualUsageData::withNote('Public contract constant') : null;
    }

    protected function shouldMarkPropertyAsRead(ReflectionProperty $property): ?VirtualUsageData {
        return $property->isPublic() && $this->isPublicContract($property->getDeclaringClass())
            ? VirtualUsageData::withNote('Public contract property') : null;
    }

    protected function shouldMarkPropertyAsWritten(ReflectionProperty $property): ?VirtualUsageData {
        return $this->shouldMarkPropertyAsRead($property);
    }

    private function isPublicContract(ReflectionClass $class): bool {
        if (in_array($class->getName(), $this->entries['classes'], true)) {
            return true;
        }
        foreach ($this->entries['prefixes'] as $prefix) {
            if (str_starts_with($class->getName(), $prefix)) {
                return true;
            }
        }
        return false;
    }
}
