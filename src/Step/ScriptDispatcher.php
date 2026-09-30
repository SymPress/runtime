<?php

declare(strict_types=1);

namespace SymPress\Runtime\Step;

use SymPress\Runtime\Config\Config;
use SymPress\Runtime\Event\LifecycleEvent;
use SymPress\Runtime\Event\PostRunEvent;
use SymPress\Runtime\Event\PostStepEvent;
use SymPress\Runtime\Event\PreRunEvent;
use SymPress\Runtime\Event\PreStepEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final readonly class ScriptDispatcher implements EventSubscriberInterface
{
    public function __construct(private Config $config)
    {
    }

    /** @return array<class-string<LifecycleEvent>, string> */
    public static function getSubscribedEvents(): array
    {
        return [PreRunEvent::class => 'dispatch', PostRunEvent::class => 'dispatch', PreStepEvent::class => 'dispatch', PostStepEvent::class => 'dispatch'];
    }

    public function dispatch(LifecycleEvent $event): void
    {
        foreach ($this->callbacks($event) as $callback) {
            if ($event->isPropagationStopped()) {
                break;
            }
            if (!is_callable($callback)) {
                $event->fail();
                $event->services->io()->error('Unavailable runtime script callback: ' . $callback);
                continue;
            }
            try {
                $signal = $callback($event->result, $event->subject, $event->services, $event->services->runContext());
                if ($signal instanceof ScriptHaltSignal) {
                    $event->applySignal($signal);
                }
            } catch (Throwable) {
                $event->fail();
                $event->services->io()->error('Runtime script callback failed: ' . (is_string($callback) ? $callback : 'PHP callback'));
            }
        }
    }

    /** @return list<string|\Closure> */
    private function callbacks(LifecycleEvent $event): array
    {
        $scripts = $this->config['scripts']->unwrapOrFallback([]);
        if (!is_array($scripts)) {
            return [];
        }
        $prefix = $event->isPre() ? 'pre-' : 'post-';
        $callbacks = [];
        $run = $event->subject instanceof Runner;
        $legacyKey = $prefix . 'wpstarter';
        if ($run && isset($scripts[$legacyKey])) {
            $this->legacyScript($scripts, $legacyKey, $event);
        }
        $names = $run ? ['wpstarter', '', 'runtime', 'sympress-runtime'] : [$event->subject->name()];
        foreach ($names as $name) {
            foreach ($scripts as $key => $value) {
                if (!is_string($key) || !str_starts_with($key, $prefix) || !is_array($value)) {
                    continue;
                }
                $match = $run ? substr($key, strlen($prefix)) === $name : $this->normalize(substr($key, strlen($prefix))) === $this->normalize($name);
                if (!$match) {
                    continue;
                }
                array_push($callbacks, ...array_values(array_filter($value, static fn (mixed $callback): bool => is_string($callback) || $callback instanceof \Closure)));
                break;
            }
        }

        return $callbacks;
    }

    /** @param array<array-key, mixed> $scripts */
    private function legacyScript(array &$scripts, string $key, LifecycleEvent $event): void
    {
        if ($this->config['compatibility']->is(false)) {
            unset($scripts[$key]);

            return;
        }
        $event->services->io()->error('Deprecated runtime script: ' . $key . '; use ' . str_replace('wpstarter', 'sympress-runtime', $key) . '.');
    }

    private function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', preg_replace('/^build/', '', strtolower($name)) ?? $name) ?? $name;
    }
}
