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
        $state['turnIndex']++;
        if ($state['turnIndex'] >= count($state['order']) * 5) {
            $state['night'] = ['index' => 0, 'choices' => []];
            $this->game->event('log', clienttranslate('Day ${round} ends. Night begins.'), ['round' => $state['round']]);
            $this->game->notifyState($state);
            $this->game->giveExtraTime((int) $state['order'][0]);
            $this->game->gamestate->changeActivePlayer((int) $state['order'][0]);
            return NightChoice::class;
        }
        $next = $state['order'][$state['turnIndex'] % count($state['order'])];
        $this->game->saveState($state);
        $this->bga->notify->all('kemetState', '', ['kemet' => $this->game->viewState($state)]);
        $this->game->giveExtraTime((int) $next);
        $this->game->gamestate->changeActivePlayer((int) $next);
        return PlayerTurn::class;
    }
}
