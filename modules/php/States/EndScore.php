<?php

declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\Games\kemetbloodandsands\Game;

const ST_END_GAME = 99;

class EndScore extends \Bga\GameFramework\States\GameState
{

    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 98,
            type: StateType::GAME,
        );
    }

    /**
     * Game state action, example content.
     *
     * The onEnteringState method of state `EndScore` is called just before the end of the game.
     */
    public function onEnteringState() {
        $winner = (int) $this->game->getActivePlayerId();
        $state = $this->game->loadState();
        foreach (array_keys($state['players']) as $id) {
            $this->bga->playerScoreAux->set((int) $id, (int) $id === $winner ? 1 : 0);
        }

        return ST_END_GAME;
    }
}
