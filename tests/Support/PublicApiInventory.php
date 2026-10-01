<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Support;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;

final class PublicApiInventory
{
    /** @return list<ReflectionClass<object>> */
    public static function classes(): array
    {
        $classes = [];
        $root = dirname(__DIR__, 2) . '/src/';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse((string) file_get_contents($file->getPathname())) ?? [];
            $nodes = (new NodeTraverser(new NameResolver()))->traverse($nodes);
            foreach ((new NodeFinder())->findInstanceOf($nodes, ClassLike::class) as $declaration) {
                if ($declaration->name === null) {
                    continue;
                }
                $name = $declaration->namespacedName->toString();
                if (!class_exists($name) && !interface_exists($name) && !trait_exists($name)) {
                    throw new RuntimeException('Source declaration is not autoloadable: ' . $name);
                }
                $classes[] = new ReflectionClass($name);
            }
        }

        return $classes;
    }

    private static function parameter(ReflectionParameter $parameter): string
    {
        $result = ($parameter->hasType() ? $parameter->getType() . ' ' : '')
            . ($parameter->isPassedByReference() ? '&' : '')
            . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();
        if (!$parameter->isDefaultValueAvailable()) {
            return $result;
        }
        $default = $parameter->isDefaultValueConstant()
            ? $parameter->getDefaultValueConstantName()
            : ($parameter->getDefaultValue() === [] ? '[]' : var_export($parameter->getDefaultValue(), true));

        return $result . ' = ' . $default;
    }

    /** @return list<string> */
    public static function members(): array
    {
        $members = [];
        foreach (self::classes() as $class) {
            if (!PublicApiUsage::exposed($class)) {
                continue;
            }
            $members[] = $class->getName() . (str_contains($class->getDocComment() ?: '', '@api') ? ' [public type]' : ' [service handle]');
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!PublicApiUsage::publicMethod($method)) {
                    continue;
                }

                $parameters = array_map(self::parameter(...), $method->getParameters());
                    $members[] = $class->getName() . '::' . $method->getName() . '(' . implode(', ', $parameters) . ')' . ($method->getReturnType() !== null ? ': ' . $method->getReturnType() : '') . ($method->isStatic() ? ' [static]' : '');
            }
            foreach ([...$class->getReflectionConstants(), ...$class->getProperties(ReflectionProperty::IS_PUBLIC)] as $member) {
                $doc = $member->getDocComment() ?: '';
                if (!$member->isPublic() || str_contains($doc, '@internal') || (!str_contains($class->getDocComment() ?: '', '@api') && !str_contains($doc, '@api'))) {
                    continue;
                }
                $signature = $class->getName() . '::' . ($member instanceof ReflectionProperty ? '$' : '') . $member->getName();
                $signature .= $member->getType() !== null ? ': ' . $member->getType() : '';
                $signature .= $member instanceof ReflectionProperty
                    ? ($member->isReadOnly() ? ' [readonly]' : '')
                    : ' = ' . json_encode($member->getValue(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $members[] = $signature;
            }
        }
        sort($members);

        return $members;
    }
}
