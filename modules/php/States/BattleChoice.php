<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\kemetbloodandsands\Game;

/**
 * War Council: attacker and defender choose their battle cards simultaneously and secretly.
 * With Spy, the Spy owner's opponent chooses first and the Spy owner sees their Battle card before choosing.
 */
class BattleChoice extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 20, type: StateType::MULTIPLE_ACTIVE_PLAYER,
            description: clienttranslate('War Council: players are secretly choosing battle cards'),
            descriptionMyTurn: clienttranslate('War Council: ${you} must choose your battle cards'),
        );
    }

    public function onEnteringState(): void
    {
        $choosers = array_map('intval', $this->game->battleChoosers($this->game->loadState()));
        $this->game->gamestate->setPlayersMultiactive($choosers, BattleAftermath::class, true);
    }

    public function getArgs(): array
    {
        return [];
    }

    #[PossibleAction]
    public function actBattleChoice(int $played, int $discarded, string $di): void
    {
        $this->choose((int) $this->game->getCurrentPlayerId(), $played, $discarded, $di);
    }

    public function zombie(int $playerId): void
    {
        $hand = $this->game->loadState()['players'][(string) $playerId]['battleHand'];
        $this->choose($playerId, $hand[0], $hand[1], '');
    }

    private function choose(int $playerId, int $played, int $discarded, string $di): void
    {
        $state = $this->game->loadState();
        $result = $this->game->battleChoice($state, (string) $playerId, $played, $discarded, $di);
        if ($result === '') {
            $this->game->event('log', clienttranslate('${player_name} has chosen battle cards'), ['player_id' => $playerId]);
            $this->game->notifyState($state);
            // A Spy owner waiting for this choice becomes active now.
            $waiting = array_map('intval', $this->game->battleChoosers($state));
            if ($waiting) $this->game->gamestate->setPlayersMultiactive($waiting, BattleAftermath::class, false);
            $this->game->gamestate->setPlayerNonMultiactive($playerId, BattleAftermath::class);
            return;
        }
        $this->game->notifyState($state);
        if ($result === 'wound') {
            $next = BattleWound::class;
        } elseif ($state['battle'] === null) {
            // Both troops can be wiped out, leaving no aftermath choice.
            $next = $this->game->afterAction($state);
        } else {
            $next = BattleAftermath::class;
        }
        $this->game->gamestate->setPlayerNonMultiactive($playerId, $next);
    }
}
