<?php

/*
 * This file is part of Laravel Love.
 *
 * (c) Anton Komarev <anton@komarev.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Cog\Laravel\Love\Console\Commands;

use Cog\Contracts\Love\Reactable\Exceptions\ReactableInvalid;
use Cog\Contracts\Love\Reactable\Models\Reactable as ReactableContract;
use Cog\Contracts\Love\Reactant\Models\Reactant as ReactantContract;
use Cog\Laravel\Love\Reactant\Models\Reactant;
use Cog\Laravel\Love\Reactant\ReactionCounter\Models\ReactionCounter;
use Cog\Laravel\Love\Reactant\ReactionCounter\Services\ReactionCounterService;
use Cog\Laravel\Love\Reactant\ReactionTotal\Models\ReactionTotal;
use Cog\Laravel\Love\ReactionType\Models\ReactionType;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use Symfony\Component\Console\Input\InputOption;

final class Recount extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'love:recount';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recount reactions of the reactable models';

    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws \Cog\Contracts\Love\Reactable\Exceptions\ReactableInvalid
     */
    public function handle(): void
    {
        if ($reactableType = $this->option('model')) {
            $reactableType = $this->normalizeReactableModelType($reactableType);
        }

        if ($reactionType = $this->option('type')) {
            $reactionType = ReactionType::fromName($reactionType);
        }

        $reactantsQuery = Reactant::query();

        if ($reactableType) {
            $reactantsQuery->where('type', $reactableType);
        }

        $reactants = $reactantsQuery->get();
        $this->getOutput()->progressStart($reactants->count());
        foreach ($reactants as $reactant) {
            /** @var \Illuminate\Database\Eloquent\Builder $query */
            $query = $reactant->reactions();

            if ($reactionType) {
                $query->where('reaction_type_id', $reactionType->getId());
            }

            $counters = $reactant->getReactionCounters();

            /** @var \Cog\Laravel\Love\Reactant\ReactionCounter\Models\ReactionCounter $counter */
            foreach ($counters as $counter) {
                if ($reactionType && $counter->isNotReactionOfType($reactionType)) {
                    continue;
                }

                $counter->update([
                    'count' => ReactionCounter::COUNT_DEFAULT,
                    'weight' => ReactionCounter::WEIGHT_DEFAULT,
                ]);
            }

            $reactions = $query->get();
            $this->recountCounters($reactant, $reactions);
            $this->recountTotal($reactant);
            $this->getOutput()->progressAdvance();
        }
        $this->getOutput()->progressFinish();
    }

    protected function getOptions(): array
    {
        return [
            ['model', null, InputOption::VALUE_OPTIONAL, 'The name of the reactable model'],
            ['type', null, InputOption::VALUE_OPTIONAL, 'The name of the reaction type'],
        ];
    }

    /**
     * Normalize reactable model type.
     *
     * @param string $modelType
     * @return string
     *
     * @throws \Cog\Contracts\Love\Reactable\Exceptions\ReactableInvalid
     */
    private function normalizeReactableModelType(
        string $modelType
    ): string {
        return $this
            ->reactableModelFromType($modelType)
            ->getMorphClass();
    }

    /**
     * Instantiate model from type or morph map value.
     *
     * @param string $modelType
     * @return \Cog\Contracts\Love\Reactable\Models\Reactable|\Illuminate\Database\Eloquent\Model
     *
     * @throws \Cog\Contracts\Love\Reactable\Exceptions\ReactableInvalid
     */
    private function reactableModelFromType(
        string $modelType
    ): ReactableContract {
        if (!class_exists($modelType)) {
            $modelType = $this->findModelTypeInMorphMap($modelType);
        }

        $model = new $modelType;

        if (!$model instanceof ReactableContract) {
            throw ReactableInvalid::notImplementInterface($modelType);
        }

        return $model;
    }

    /**
     * Find model type in morph mappings registry.
     *
     * @param string $modelType
     * @return string
     *
     * @throws \Cog\Contracts\Love\Reactable\Exceptions\ReactableInvalid
     */
    private function findModelTypeInMorphMap(
        string $modelType
    ): string {
        $morphMap = Relation::morphMap();

        if (!isset($morphMap[$modelType])) {
            throw ReactableInvalid::classNotExists($modelType);
        }

        return $morphMap[$modelType];
    }

    private function recountTotal(
        ReactantContract $reactant
    ): void {
        $counters = $reactant->getReactionCounters();

        if (count($counters) === 0) {
            return;
        }

        $totalCount = ReactionTotal::COUNT_DEFAULT;
        $totalWeight = ReactionTotal::WEIGHT_DEFAULT;

        foreach ($counters as $counter) {
            $totalCount += $counter->getCount();
            $totalWeight += $counter->getWeight();
        }

        /** @var \Cog\Laravel\Love\Reactant\ReactionTotal\Models\ReactionTotal $total */
        $total = $reactant->getReactionTotal();
        $total->update([
            'count' => $totalCount,
            'weight' => $totalWeight,
        ]);
    }

    private function recountCounters(
        ReactantContract $reactant,
        iterable $reactions
    ): void {
        $service = new ReactionCounterService($reactant);

        foreach ($reactions as $reaction) {
            $service->addReaction($reaction);
        }
    }
}
