<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\kemetbloodandsands\Game;

/**
 * Steps: 'loser' (recall or retreat), 'retreat' (winner picks the losing troop's zone),
 * 'winner' (stay or recall).
 */
class BattleAftermath extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 30, type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must resolve the battle aftermath'),
            descriptionMyTurn: clienttranslate('${you} must resolve the battle aftermath'),
        );
    }
    public function onEnteringState(): void
    {
        $state = $this->game->loadState();
        $this->game->gamestate->changeActivePlayer((int) $this->game->aftermathActor($state));
    }
    public function getArgs(): array
    {
        $state = $this->game->loadState();
        $battle = $state['battle'];
        return [
            'step' => $battle['step'] ?? '',
            'retreatOptions' => ($battle['step'] ?? '') === 'retreat' ? $this->game->retreatOptions($state) : [],
            'canRetreat' => ($battle['step'] ?? '') === 'loser' && ($state['zones'][$battle['zone']]['units'][$battle['winner']] ?? 0) > 0 && $this->game->retreatOptions($state),
        ];
    }
    #[PossibleAction]
    public function actAftermath(string $choice, string $retreatTo): string
    {
        $state = $this->game->loadState();
        $done = $this->game->battleAftermath($state, (string) $this->game->getCurrentPlayerId(), $choice, $retreatTo);
        return $this->advance($state, $done);
    }
    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $step = $state['battle']['step'];
        $choice = $step === 'loser' ? 'recall' : ($step === 'retreat' ? 'retreat' : 'stay');
        $retreatTo = $step === 'retreat' ? $this->game->retreatOptions($state)[0] : '';
        $done = $this->game->battleAftermath($state, (string) $playerId, $choice, $retreatTo);
        return $this->advance($state, $done);
    }
    private function advance(array $state, bool $done): string
    {
        $this->game->notifyState($state);
        if ($done) return NextPlayer::class;
        $this->game->gamestate->changeActivePlayer((int) $this->game->aftermathActor($state));
        return BattleAftermath::class;
    }
}
