<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\kemetbloodandsands\Game;
use Bga\Games\kemetbloodandsands\PrototypeRules;

class PlayerTurn extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 10, type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must take an action'),
            descriptionMyTurn: clienttranslate('${you} must choose an action space'),
        );
    }

    public function onEnteringState(): ?string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getActivePlayerId();
        $score = $this->game->score($state, $id);
        if ($score >= 9) {
            foreach (array_keys($state['players']) as $other) {
                if ((string) $other !== $id && $this->game->score($state, (string) $other) > $score) return null;
            }
            $this->game->event('log', clienttranslate('${player_name} starts their turn with ${score} FP and wins the game'), ['player_id' => $id, 'score' => $score]);
            $this->game->notifyState($state);
            return EndScore::class;
        }
        return null;
    }

    public function getArgs(): array
    {
        return [];
    }

    private function finish(array $state): string
    {
        $this->game->notifyState($state);
        return $state['battle'] === null ? NextPlayer::class : BattleChoice::class;
    }

    #[PossibleAction]
    public function actPray(string $slot): string
    {
        $state = $this->game->loadState();
        $this->game->doPray($state, (string) $this->game->getCurrentPlayerId(), $slot);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actRaise(string $slot, string $zone, string $color, int $level): string
    {
        $state = $this->game->loadState();
        $this->game->doRaise($state, (string) $this->game->getCurrentPlayerId(), $slot, $zone, $color, $level);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actBuy(string $slot, string $tile): string
    {
        $state = $this->game->loadState();
        $this->game->doBuy($state, (string) $this->game->getCurrentPlayerId(), $slot, $tile);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actRecruit(string $slot, string $zone, int $units): string
    {
        $state = $this->game->loadState();
        $this->game->doRecruit($state, (string) $this->game->getCurrentPlayerId(), $slot, $zone, $units);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actMove(string $slot, string $from, string $to, int $units, bool $teleport, string $via): string
    {
        $state = $this->game->loadState();
        $this->game->doMove($state, (string) $this->game->getCurrentPlayerId(), $slot, $from, $to, $units, $teleport, $via);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actDayCard(string $card, string $zone): string
    {
        $state = $this->game->loadState();
        $this->game->playDayCard($state, (string) $this->game->getCurrentPlayerId(), $card, $zone);
        $this->game->notifyState($state);
        return PlayerTurn::class;
    }

    /** Prays when possible; otherwise forfeits a slot that still respects the Equilibrium rule. */
    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $id = (string) $playerId;
        foreach (['pray_c', 'pray_b'] as $slot) {
            if (!$this->game->slotAllowed($state, $id, $slot)) continue;
            try {
                $this->game->doPray($state, $id, $slot);
                return $this->finish($state);
            } catch (UserException) {
            }
        }
        foreach (array_keys(PrototypeRules::SLOTS) as $slot) {
            if (!$this->game->slotAllowed($state, $id, $slot)) continue;
            $state['players'][$id]['slots'][] = $slot;
            $this->game->event('log', clienttranslate('${player_name} forfeits an action'), ['player_id' => $id]);
            return $this->finish($state);
        }
        return NextPlayer::class;
    }
}
