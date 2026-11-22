<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\kemetbloodandsands\Game;

/** Divine Wound, end of the Confrontation step: attacker then defender may discard DI cards for +1 Strength each. */
class BattleWound extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 25, type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} may discard DI cards for Divine Wound'),
            descriptionMyTurn: clienttranslate('Divine Wound: ${you} may discard DI cards for +1 Strength each'),
        );
    }

    public function onEnteringState(): void
    {
        $state = $this->game->loadState();
        $this->game->gamestate->changeActivePlayer((int) $state['battle']['woundQueue'][0]);
    }

    public function getArgs(): array
    {
        return [];
    }

    #[PossibleAction]
    public function actWound(string $cards): string
    {
        return $this->resolve((string) $this->game->getCurrentPlayerId(), $cards);
    }

    public function zombie(int $playerId): string
    {
        return $this->resolve((string) $playerId, '');
    }

    private function resolve(string $id, string $cards): string
    {
        $state = $this->game->loadState();
        $result = $this->game->woundChoice($state, $id, $cards);
        $this->game->notifyState($state);
        if ($result === 'wound') {
            $this->game->gamestate->changeActivePlayer((int) $state['battle']['woundQueue'][0]);
            return BattleWound::class;
        }
        if ($state['battle'] === null) return $this->game->afterAction($state);
        return BattleAftermath::class;
    }
}
