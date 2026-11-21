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
        parent::__construct($game, id: 30, type: StateType::ACTIVE_PLAYER);
    }
    public function onEnteringState(): void
    {
        $state = $this->game->loadState();
        $this->game->gamestate->changeActivePlayer((int) $this->game->aftermathActor($state));
    }
    public function getArgs(): array
    {
        $state = $this->game->loadState();
        $retreat = $state['battle'] !== null && $state['battle']['step'] === 'retreat';
        return [
            'kemet' => $this->game->viewState($state),
            'retreatOptions' => $retreat ? $this->game->retreatOptions($state) : [],
        ];
    }
    #[PossibleAction]
    public function actAftermath(string $choice, string $retreatTo): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        $step = $state['battle']['step'] ?? '';
        $done = $this->game->battleAftermath($state, $id, $choice, $retreatTo);
        $name = $this->game->getPlayerNameById((int) $id);
        $message = $step === 'retreat'
            ? $name . ' retreats the losing troop to ' . $state['zones'][$retreatTo]['name'] . '.'
            : $name . ' chooses to ' . $choice . '.';
        return $this->advance($state, $done, $message);
    }
    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $step = $state['battle']['step'];
        $choice = $step === 'loser' ? 'recall' : ($step === 'retreat' ? 'retreat' : 'stay');
        $retreatTo = $step === 'retreat' ? $this->game->retreatOptions($state)[0] : '';
        $done = $this->game->battleAftermath($state, (string) $playerId, $choice, $retreatTo);
        return $this->advance($state, $done, 'An aftermath choice is made automatically.');
    }
    private function advance(array $state, bool $done, string $message): string
    {
        $this->game->notifyState($state, $message);
        if ($done) return NextPlayer::class;
        $this->game->gamestate->changeActivePlayer((int) $this->game->aftermathActor($state));
        return BattleAftermath::class;
    }
}
