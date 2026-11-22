<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\DTOs\UserContextDTO;
use Illuminate\Contracts\Container\Container;

final class TenantContextRunner
{
    public function __construct(private readonly Container $container) {}

    public function runAsSystem(string $partnerId, callable $callback): mixed
    {
        return $this->runWithContext(UserContextDTO::asSystem($partnerId), $callback);
    }

    public function runAsWebhook(callable $callback): mixed
    {
        return $this->runWithContext(UserContextDTO::asWebhook(), $callback);
    }

    public function runWithContext(UserContextDTO $context, callable $callback): mixed
    {
        $hadPrevious = $this->container->bound(UserContextDTO::class);
        $previous = $hadPrevious ? $this->container->make(UserContextDTO::class) : null;

        $this->container->forgetInstance(UserContextDTO::class);
        $this->container->instance(UserContextDTO::class, $context);

        try {
            return $callback($context);
        } finally {
            $this->container->forgetInstance(UserContextDTO::class);
            if ($previous instanceof UserContextDTO) {
                $this->container->instance(UserContextDTO::class, $previous);
            }
        }
    }
}
