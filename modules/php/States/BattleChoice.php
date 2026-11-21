<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\kemetbloodandsands\Game;
use Bga\Games\kemetbloodandsands\PrototypeRules;

class BattleChoice extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 20, type: StateType::ACTIVE_PLAYER);
    }
    public function onEnteringState(): void
    {
        $state = $this->game->loadState();
        $battle = $state['battle'];
        $id = isset($battle['choices'][$battle['attacker']]) ? $battle['defender'] : $battle['attacker'];
        $this->game->gamestate->changeActivePlayer((int) $id);
    }
    public function getArgs(): array
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getActivePlayerId();
        return [
            'kemet' => $this->game->viewState($state),
            'battleCards' => PrototypeRules::BATTLE_CARDS,
            'diCards' => PrototypeRules::DI,
            '_private' => [$id => ['kemet' => $this->game->viewState($state, $id)]],
            '_merge_private' => true,
        ];
    }
    #[PossibleAction]
    public function actBattleChoice(int $played, int $discarded, string $di): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        $winner = $this->game->battleChoice($state, $id, $played, $discarded, $di);
        $message = $winner !== '' ? 'The battle is resolved. ' . $this->game->getPlayerNameById((int) $winner) . ' wins.' : $this->game->getPlayerNameById((int) $id) . ' has chosen battle cards.';
        return $this->advance($state, $winner, $message);
    }
    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $hand = $state['players'][(string) $playerId]['battleHand'];
        $winner = $this->game->battleChoice($state, (string) $playerId, $hand[0], $hand[1], '');
        return $this->advance($state, $winner, 'Battle cards selected automatically.');
    }
    private function advance(array $state, string $winner, string $message): string
    {
        $this->game->notifyState($state, $message);
        if ($winner === '') {
            $this->game->gamestate->changeActivePlayer((int) $state['battle']['defender']);
            return BattleChoice::class;
        }
        // Both troops can be wiped out, leaving no aftermath choice.
        return $state['battle'] === null ? NextPlayer::class : BattleAftermath::class;
    }
}
