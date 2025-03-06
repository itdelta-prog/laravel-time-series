<?php

namespace TimothePearce\TimeSeries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use TimothePearce\TimeSeries\Models\Projection;

class Projector
{
    public function __construct(
        protected Model  $projectedModel,
        protected string $projectionName,
        protected string $eventName,
    ) {
    }

    /**
     * Handles the projection.
     */
    public function handle(): void
    {
        if (! $this->hasCallableMethod()) {
            return;
        }

        $this->parsePeriods();
    }

    /**
     * Parses the periods defined as class attribute.
     */
    public function parsePeriods(): void
    {
        $periods = (new $this->projectionName())->periods;

        collect($periods)->each(function ($period) {
            $this->isGlobalPeriod($period) ?
                $this->createOrUpdateGlobalPeriod() :
                $this->parsePeriod($period);
        });
    }

    /**
     * The key used to query the projection.
     */
    public function key(): bool|int|string
    {
        return (new $this->projectionName())->key($this->projectedModel);
    }

    /**
     * Is the given period a global one or not.
     */
    protected function isGlobalPeriod($period): bool
    {
        return $period === '*';
    }

    /**
     * Handles the global period case.
     */
    protected function createOrUpdateGlobalPeriod(): void
    {
        $projection = $this->findGlobalProjection();

        is_null($projection) ?
            $this->createGlobalProjection() :
            $this->updateProjection($projection, '*');
    }

    /**
     * Parses the given period.
     */
    public function parsePeriod(string $period): void
    {
        $projection = $this->findProjection($period);

        is_null($projection) ?
            $this->createProjection($period) :
            $this->updateProjection($projection, $period);
    }

    /**
     * Finds the global projection if it exists.
     */
    protected function findGlobalProjection(): Projection|null
    {
        return Projection::firstWhere([
            ['projection_name', $this->projectionName],
            ['key', $this->hasKey() ? $this->key() : null],
            ['period', '*'],
            ['start_date', null],
        ]);
    }

    /**
     * Finds the projection if it exists.
     */
    protected function findProjection(string $period): Projection|null
    {
        return Projection::firstWhere([
            ['projection_name', $this->projectionName],
            ['key', $this->hasKey() ? $this->key() : null],
            ['period', $period],
            ['start_date', app(TimeSeries::class)->resolveFloorDate($this->projectedModel->created_at, $period)],
        ]);
    }

    /**
     * Creates the projection.
     */
    protected function createProjection(string $period): void
    {
        $content =  $this->mergeProjectedContent((new $this->projectionName())->defaultContent(), $period);
        $projection = Projection::firstWhere([
           'projection_name' => $this->projectionName,
            'key' => $this->hasKey() ? $this->key() : null,
            'period' => $period,
            'start_date' => app(TimeSeries::class)->resolveFloorDate($this->projectedModel->created_at, $period),
        ]);

        if(is_null($projection))
        $this->projectedModel->projections()->create([
            'projection_name' => $this->projectionName,
            'key' => $this->hasKey() ? $this->key() : null,
            'period' => $period,
            'start_date' => app(TimeSeries::class)->resolveFloorDate($this->projectedModel->created_at, $period),
            'content' => $content,
        ]);
    }

    /**
     * Creates the global projection.
     */
    protected function createGlobalProjection()
    {
        $this->projectedModel->projections()->create([
            'projection_name' => $this->projectionName,
            'key' => $this->hasKey() ? $this->key() : null,
            'period' => '*',
            'start_date' => null,
            'content' => $this->mergeProjectedContent((new $this->projectionName())->defaultContent(), '*'),
        ]);
    }

    /**
     * Updates the projection.
     */
    protected function updateProjection(Projection $projection, string $period): void
    {
        $projection->content = $this->mergeProjectedContent($projection->content, $period);

        $projection->save();
    }

    /**
     * Determines whether the class has a key.
     */
    protected function hasKey(): bool
    {
        return method_exists($this->projectionName, 'key');
    }

    /**
     * Merges the projected content with the given one.
     */
    protected function mergeProjectedContent(array $content, string $period): array
    {
        return array_merge($content, $this->resolveCallableMethod($content, $period));
    }

    /**
     * Asserts the projection has a callable method for the event name.
     */
    protected function hasCallableMethod(): bool
    {
        $modelName = Str::of($this->projectedModel::class)->explode('\\')->last();
        $callableMethod = lcfirst($modelName) . ucfirst($this->eventName);
        $defaultCallable = 'projectable' . ucfirst($this->eventName);

        return method_exists($this->projectionName, $callableMethod) || method_exists($this->projectionName, $defaultCallable);
    }

    /**
     * Resolves the callable method.
     */
    protected function resolveCallableMethod(array $content, string $period): array
    {
        $modelName = Str::of($this->projectedModel::class)->explode('\\')->last();
        $callableMethod = lcfirst($modelName) . ucfirst($this->eventName);
        $defaultCallable = 'projectable' . ucfirst($this->eventName);

        return method_exists($this->projectionName, $callableMethod) ?
            (new $this->projectionName())->$callableMethod($content, $this->projectedModel, $period) :
            (new $this->projectionName())->$defaultCallable($content, $this->projectedModel, $period);
    }

    /**
     * Resolves the projection start date.
     */
    protected function resolveStartDate(string $periodType, int $quantity): Carbon
    {
        $startDate = $this->projectedModel->created_at->floorUnit($periodType, $quantity);

        if (in_array($periodType, ['week', 'weeks'])) {
            $startDate->startOfWeek((int)app(TimeSeries::class)->getFirstWorkingDayOfWeek());
        }

        return $startDate;
    }
}
