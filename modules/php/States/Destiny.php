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
        parent::__construct($game, id: 50, type: StateType::ACTIVE_PLAYER);
    }
    public function onEnteringState(): void
    {
        $state = $this->game->loadState();
        $this->game->gamestate->changeActivePlayer((int) $state['destiny']['queue'][$state['destiny']['index']]);
    }
    public function getArgs(): array
    {
        $state = $this->game->loadState();
        return ['kemet' => $this->game->viewState($state)];
    }
    #[PossibleAction]
    public function actDestiny(int $position): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        $done = $this->game->chooseDestiny($state, $id, $position);
        return $this->advance($state, $done, $this->game->getPlayerNameById((int) $id) . ' takes turn order space ' . ($position + 1) . '.');
    }
    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $position = (int) array_search(null, $state['destiny']['positions'], true);
        $done = $this->game->chooseDestiny($state, (string) $playerId, $position);
        return $this->advance($state, $done, 'A turn order space is chosen automatically.');
    }
    private function advance(array $state, bool $done, string $message): string
    {
        if (!$done) {
            $this->game->notifyState($state, $message);
            $this->game->gamestate->changeActivePlayer((int) $state['destiny']['queue'][$state['destiny']['index']]);
            return Destiny::class;
        }
        $this->game->notifyState($state, $message . ' Day ' . $state['round'] . ' begins.');
        $this->game->gamestate->changeActivePlayer((int) $state['order'][0]);
        return PlayerTurn::class;
    }
}
