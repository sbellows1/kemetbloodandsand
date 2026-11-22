<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\kemetbloodandsands\Game;

/** Night Power choices after the night is resolved: Vision (discard 4 DI cards), then Hand of God (free Build). */
class NightPowers extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 45, type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must resolve a Night Power'),
            descriptionMyTurn: clienttranslate('${you} must resolve a Night Power'),
        );
    }

    public function onEnteringState(): void
    {
        $state = $this->game->loadState();
        $this->game->gamestate->changeActivePlayer((int) $state['nightPowers'][0]['player']);
    }

    public function getArgs(): array
    {
        $state = $this->game->loadState();
        $task = $state['nightPowers'][0] ?? null;
        return [
            'task' => $task,
            'options' => ($task['task'] ?? '') === 'handOfGod' ? $this->game->handOfGodOptions($state, $task['player']) : [],
        ];
    }

    #[PossibleAction]
    public function actNightPower(string $choice): string
    {
        return $this->resolve((string) $this->game->getCurrentPlayerId(), $choice);
    }

    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $task = $state['nightPowers'][0];
        $choice = $task['task'] === 'vision' ? implode(',', array_slice($this->game->woundCards($state, (string) $playerId), 0, $task['count'])) : '';
        return $this->resolve((string) $playerId, $choice);
    }

    private function resolve(string $id, string $choice): string
    {
        $state = $this->game->loadState();
        $done = $this->game->nightPower($state, $id, $choice);
        if (!$done) {
            $this->game->notifyState($state);
            $this->game->gamestate->changeActivePlayer((int) $state['nightPowers'][0]['player']);
            return NightPowers::class;
        }
        $this->game->startDestiny($state);
        $this->game->notifyState($state);
        return Destiny::class;
    }
}
