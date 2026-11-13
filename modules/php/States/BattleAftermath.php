<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\kemetbloodandsands\Game;

class BattleAftermath extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 30, type: StateType::ACTIVE_PLAYER);
    }
    public function onEnteringState(): void
    {
        $state = $this->game->loadState();
        $this->game->gamestate->changeActivePlayer((int) $state['battle'][$state['battle']['step']]);
    }
    public function getArgs(): array
    {
        $state = $this->game->loadState();
        return ['kemet' => $this->game->viewState($state)];
    }
    #[PossibleAction]
    public function actAftermath(string $choice, string $retreatTo): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        $done = $this->game->battleAftermath($state, $id, $choice, $retreatTo);
        $this->game->notifyState($state, $this->game->getPlayerNameById((int) $id) . ' chooses to ' . $choice . '.');
        if (!$done) $this->game->gamestate->changeActivePlayer((int) $state['battle']['winner']);
        return $done ? NextPlayer::class : BattleAftermath::class;
    }
    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $done = $this->game->battleAftermath($state, (string) $playerId, 'recall', '');
        $this->game->notifyState($state, 'A troop is recalled automatically.');
        if (!$done) $this->game->gamestate->changeActivePlayer((int) $state['battle']['winner']);
        return $done ? NextPlayer::class : BattleAftermath::class;
    }
}
