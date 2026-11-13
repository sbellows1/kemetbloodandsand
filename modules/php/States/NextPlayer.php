<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\kemetbloodandsands\Game;

class NextPlayer extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 90, type: StateType::GAME, updateGameProgression: true);
    }

    public function onEnteringState(): string
    {
        $state = $this->game->loadState();
        $current = $state['order'][$state['turnIndex'] % count($state['order'])];
        $this->game->giveExtraTime((int) $current);
        $state['turnIndex']++;
        if ($state['turnIndex'] >= count($state['order']) * 5) {
            $state['night'] = ['index' => 0, 'choices' => []];
            $this->game->notifyState($state, 'Day ' . $state['round'] . ' ends. Night decisions begin.');
            $this->game->gamestate->changeActivePlayer((int) $state['order'][0]);
            return NightChoice::class;
        }
        $next = $state['order'][$state['turnIndex'] % count($state['order'])];
        $this->game->saveState($state);
        $this->game->gamestate->changeActivePlayer((int) $next);
        return PlayerTurn::class;
    }
}
