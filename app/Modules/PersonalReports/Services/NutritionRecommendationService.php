<?php

namespace App\Modules\PersonalReports\Services;

use App\Modules\NutritionEngine\Services\NutritionPlanEngineService;

final class NutritionRecommendationService
{
    public const ALGORITHM_VERSION = NutritionPlanEngineService::ALGORITHM_VERSION;

    public function __construct(
        private readonly NutritionPlanEngineService $engine,
    ) {}

    /** @return array<string,mixed> */
    public function build(object $report, ?object $nutrition, array $raw, array $calculated): array
    {
        return $this->engine->buildForReport($report, $nutrition, $raw, $calculated);
    }

    /** @param array<string,mixed> $plan */
    public function persistVersion(object $report, array $plan): string
    {
        return $this->engine->persistForReport($report, $plan);
    }
}
