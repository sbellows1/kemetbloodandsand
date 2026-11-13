<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\kemetbloodandsands\Game;

class NightChoice extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 40, type: StateType::ACTIVE_PLAYER);
    }
    public function onEnteringState(): void
    {
        $state = $this->game->loadState();
        $this->game->gamestate->changeActivePlayer((int) $state['order'][$state['night']['index']]);
    }
    public function getArgs(): array
    {
        $state = $this->game->loadState();
        return ['kemet' => $this->game->viewState($state)];
    }
    #[PossibleAction]
    public function actNightChoice(bool $offer, bool $delta, int $pray, int $draw, int $recruit, string $district): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        if ($state['order'][$state['night']['index']] !== $id) throw new UserException('It is not your night decision.');
        if ($pray < 0 || $draw < 0 || $recruit < 0 || $pray + $draw * 2 + $recruit > $state['players'][$id]['veterans']) throw new UserException('You do not have enough veteran tokens.');
        if ($offer && ($this->game->controller($state['zones']['sanctuary']) !== $id || ($state['zones']['sanctuary']['units'][$id] ?? 0) < 2)) throw new UserException('You cannot make the sanctuary offering.');
        if ($delta && ($this->game->controller($state['zones']['delta']) !== $id || ($state['zones']['delta']['units'][$id] ?? 0) < 1)) throw new UserException('You do not control the Delta temple.');
        if ($recruit > 0 && (!isset($state['zones'][$district]) || $state['zones'][$district]['city'] !== $id || $this->game->controller($state['zones'][$district]) !== $id)) throw new UserException('Recruit veterans in a free home district.');
        $state['night']['choices'][$id] = compact('offer', 'delta', 'pray', 'draw', 'recruit', 'district');
        $state['night']['index']++;
        if ($state['night']['index'] >= count($state['order'])) {
            $this->game->resolveNight($state);
            $this->game->notifyState($state, 'Night ends. Day ' . $state['round'] . ' begins.');
            $this->game->gamestate->changeActivePlayer((int) $state['order'][0]);
            return PlayerTurn::class;
        }
        $this->game->notifyState($state, $this->game->getPlayerNameById((int) $id) . ' completes night decisions.');
        $this->game->gamestate->changeActivePlayer((int) $state['order'][$state['night']['index']]);
        return NightChoice::class;
    }
    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $id = (string) $playerId;
        $state['night']['choices'][$id] = ['offer' => false, 'delta' => false, 'pray' => 0, 'draw' => 0, 'recruit' => 0, 'district' => ''];
        $state['night']['index']++;
        if ($state['night']['index'] >= count($state['order'])) {
            $this->game->resolveNight($state);
            $this->game->notifyState($state, 'Night ends. Day ' . $state['round'] . ' begins.');
            $this->game->gamestate->changeActivePlayer((int) $state['order'][0]);
            return PlayerTurn::class;
        }
        $this->game->notifyState($state, 'Night decision completed automatically.');
        $this->game->gamestate->changeActivePlayer((int) $state['order'][$state['night']['index']]);
        return NightChoice::class;
    }
}
