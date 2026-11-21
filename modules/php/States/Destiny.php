<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\kemetbloodandsands\Game;

/** Night step "Destiny": in ascending FP order, each player places their order marker on an empty space. */
class Destiny extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 50, type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must choose a turn order space'),
            descriptionMyTurn: clienttranslate('${you} must choose a turn order space'),
        );
    }
    public function onEnteringState(): void
    {
        $state = $this->game->loadState();
        $this->game->gamestate->changeActivePlayer((int) $state['destiny']['queue'][$state['destiny']['index']]);
    }
    public function getArgs(): array
    {
        return [];
    }
    #[PossibleAction]
    public function actDestiny(int $position): string
    {
        $state = $this->game->loadState();
        $done = $this->game->chooseDestiny($state, (string) $this->game->getCurrentPlayerId(), $position);
        return $this->advance($state, $done);
    }
    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $position = (int) array_search(null, $state['destiny']['positions'], true);
        $done = $this->game->chooseDestiny($state, (string) $playerId, $position);
        return $this->advance($state, $done);
    }
    private function advance(array $state, bool $done): string
    {
        $this->game->notifyState($state);
        if (!$done) {
            $this->game->gamestate->changeActivePlayer((int) $state['destiny']['queue'][$state['destiny']['index']]);
            return Destiny::class;
        }
        $this->game->gamestate->changeActivePlayer((int) $state['order'][0]);
        return PlayerTurn::class;
    }
}
