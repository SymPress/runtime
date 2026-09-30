<?php

declare(strict_types=1);

namespace SymPress\Runtime\Tests\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/** Static extension consumer audit, including typed chains and local aliases. */
final class PublicApiUsage
{
    private array $errors = [];
    private array $locals = [];

    public static function isRuntime(string $name): bool
    {
        $name = strtolower(ltrim($name, '\\'));

        return str_starts_with($name, 'sympress\\runtime\\') && !str_starts_with($name, 'sympress\\runtime\\tests\\');
    }

    public static function exposed(ReflectionClass $class): bool
    {
        if (str_contains($class->getDocComment() ?: '', '@api')) {
            return true;
        }
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_contains($method->getDocComment() ?: '', '@api')) {
                return true;
            }
        }

        return false;
    }

    public static function publicMethod(ReflectionMethod $method): bool
    {
        return $method->isPublic() && str_contains($method->getDocComment() ?: '', '@api');
    }

    /** @return list<string> */
    public static function types(?ReflectionType $type, string $self): array
    {
        if ($type instanceof ReflectionUnionType) {
            return array_merge(...array_map(static fn (ReflectionType $part): array => self::types($part, $self), $type->getTypes()));
        }
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return [];
        }

        return [in_array($type->getName(), ['self', 'static'], true) ? $self : $type->getName()];
    }

    /** @return list<string> */
    public function violations(string $source): array
    {
        $this->errors = [];
        $this->locals = [];
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
        $nodes = (new NodeTraverser(new NameResolver()))->traverse($nodes);
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($nodes, Stmt\ClassLike::class) as $class) {
            if ($class->name === null) {
                continue;
            }

            $this->locals[$class->namespacedName->toString()] = $class;
        }
        foreach ($finder->findInstanceOf($nodes, Name::class) as $name) {
            $class = $name->toString();
            if (!self::isRuntime($class) || (!(!class_exists($class) && !interface_exists($class) || !self::exposed(new ReflectionClass($class))))) {
                continue;
            }

            $this->errors[] = 'Internal symbol: ' . $class;
        }
        $variables = [];
        $this->walk($nodes, $variables, null);

        return array_values(array_unique($this->errors));
    }

    private function walk(mixed $node, array &$variables, ?string $class): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->walk($child, $variables, $class);
            }

            return;
        }
        if (!$node instanceof Node) {
            return;
        }
        if ($node instanceof Stmt\ClassLike) {
            $class = $node->name === null ? null : $node->namespacedName->toString();
        }
        if ($node instanceof Node\FunctionLike) {
            $scope = $variables;
            foreach ($node->getParams() as $param) {
                if (!($param->var instanceof Expr\Variable) || !is_string($param->var->name)) {
                    continue;
                }

                $scope[$param->var->name] = $this->nodeTypes($param->type, $class);
            }
            $this->walk($node->getStmts(), $scope, $class);
            if ($node instanceof Expr\ArrowFunction) {
                $this->walk($node->expr, $scope, $class);
            }

            return;
        }
        if ($node instanceof Expr\Array_ && count($node->items) === 2) {
            $receiver = $node->items[0]?->value;
            $member = $node->items[1]?->value;
            if ($receiver instanceof Expr && $member instanceof Node\Scalar\String_) {
                $this->methodTypes($this->expressionTypes($receiver, $variables, $class), $member->value);
            }
        }
        if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $variables[$node->var->name] = $this->expressionTypes($node->expr, $variables, $class);
        }
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall || $node instanceof Expr\New_ || $node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch || $node instanceof Expr\ClassConstFetch) {
            $this->expressionTypes($node, $variables, $class);
        }
        foreach ($node->getSubNodeNames() as $name) {
            $this->walk($node->$name, $variables, $class);
        }
    }

    private function nodeTypes(mixed $type, ?string $class): array
    {
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            return array_merge(...array_map(fn (Node $part): array => $this->nodeTypes($part, $class), $type->types));
        }
        if ($type instanceof Node\NullableType) {
            return $this->nodeTypes($type->type, $class);
        }
        if (!$type instanceof Name) {
            return [];
        }
        $name = $type->toString();

        return in_array($name, ['self', 'static'], true) ? ($class === null ? [] : [$class]) : [$name];
    }

    private function expressionTypes(Expr $expression, array $variables, ?string $class): array
    {
        if ($expression instanceof Expr\Variable) {
            return $expression->name === 'this' ? ($class === null ? [] : [$class]) : ($variables[$expression->name] ?? []);
        }
        if ($expression instanceof Expr\New_) {
            $types = $this->nodeTypes($expression->class, $class);
            $this->methodTypes($types, '__construct');

            return $types;
        }
        if ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall) {
            return $this->methodTypes($this->expressionTypes($expression->var, $variables, $class), $expression->name instanceof Node\Identifier ? $expression->name->toString() : null);
        }
        if ($expression instanceof Expr\StaticCall) {
            return $this->methodTypes($this->nodeTypes($expression->class, $class), $expression->name instanceof Node\Identifier ? $expression->name->toString() : null);
        }
        if ($expression instanceof Expr\ClassConstFetch) {
            if ($expression->name instanceof Node\Identifier && strtolower($expression->name->toString()) === 'class') {
                return [];
            }
            $this->members($this->nodeTypes($expression->class, $class), $expression->name instanceof Node\Identifier ? $expression->name->toString() : null, true);
        }
        if ($expression instanceof Expr\PropertyFetch || $expression instanceof Expr\NullsafePropertyFetch) {
            return $this->members($this->expressionTypes($expression->var, $variables, $class), $expression->name instanceof Node\Identifier ? $expression->name->toString() : null, false);
        }
        if ($expression instanceof Expr\ArrayDimFetch) {
            $types = $this->expressionTypes($expression->var, $variables, $class);

            return $this->methodTypes(array_filter($types, static fn (string $type): bool => self::isRuntime($type)), 'offsetGet');
        }

        return [];
    }

    private function methodTypes(array $types, ?string $name): array
    {
        $result = [];
        foreach ($types as $type) {
            if (!self::isRuntime($type)) {
                $local = $this->locals[$type] ?? null;
                $method = $local !== null && $name !== null ? $local->getMethod($name) : null;
                if ($method !== null) {
                    $result = [...$result, ...$this->nodeTypes($method->returnType, $type)];
                }
                continue;
            }
            $reflection = new ReflectionClass($type);
            $method = $name !== null && $reflection->hasMethod($name) ? $reflection->getMethod($name) : null;
            $external = $method !== null && $method->isPublic() && !self::isRuntime($method->getDeclaringClass()->getName());
            if ($method === null || (!self::publicMethod($method) && !$external)) {
                $this->errors[] = 'Internal method: ' . $type . '::' . ($name ?? '(dynamic)');
                continue;
            }
            $result = [...$result, ...self::types($reflection->getMethod($name)->getReturnType(), $type)];
        }

        return array_values(array_unique($result));
    }

    private function localPropertyTypes(string $type, ?string $name, bool $constant): array
    {
        $local = $this->locals[$type] ?? null;
        if ($local === null || $constant) {
            return [];
        }
        $result = [];
        foreach ($local->getProperties() as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() !== $name) {
                    continue;
                }

                $result = [...$result, ...$this->nodeTypes($property->type, $type)];
            }
        }
        foreach ($local->getMethod('__construct')?->params ?? [] as $param) {
            if ($param->flags === 0 || !($param->var instanceof Expr\Variable) || $param->var->name !== $name) {
                continue;
            }

            $result = [...$result, ...$this->nodeTypes($param->type, $type)];
        }

        return $result;
    }

    private function members(array $types, ?string $name, bool $constant): array
    {
        $result = [];
        foreach ($types as $type) {
            if (!self::isRuntime($type)) {
                $result = [...$result, ...$this->localPropertyTypes($type, $name, $constant)];
                continue;
            }
            $reflection = new ReflectionClass($type);
            $member = $name === null ? null : ($constant ? $reflection->getReflectionConstant($name) : ($reflection->hasProperty($name) ? $reflection->getProperty($name) : null));
            $doc = $member ? ($member->getDocComment() ?: '') : '';
            if (!$member || !$member->isPublic() || str_contains($doc, '@internal') || (!str_contains($reflection->getDocComment() ?: '', '@api') && !str_contains($doc, '@api'))) {
                $this->errors[] = 'Internal member: ' . $type . '::' . ($name ?? '(dynamic)');
                continue;
            }
            if ($constant) {
                continue;
            }

            $result = [...$result, ...self::types($member->getType(), $type)];
        }

        return array_values(array_unique($result));
    }
}
