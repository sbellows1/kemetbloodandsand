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
        parent::__construct($game, id: 10, type: StateType::ACTIVE_PLAYER);
    }

    public function onEnteringState(): ?string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getActivePlayerId();
        if ($this->game->score($state, $id) >= 9) {
            $score = $this->game->score($state, $id);
            $ahead = false;
            foreach (array_keys($state['players']) as $other) {
                if ((string) $other !== $id && $this->game->score($state, (string) $other) > $score) $ahead = true;
            }
            if (!$ahead) return EndScore::class;
        }
        return null;
    }

    public function getArgs(): array
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getActivePlayerId();
        return [
            'kemet' => $this->game->viewState($state),
            'slots' => PrototypeRules::SLOTS,
            'tiles' => PrototypeRules::TILES,
            'battleCards' => PrototypeRules::BATTLE_CARDS,
            'diCards' => PrototypeRules::DI,
            '_private' => [$id => ['kemet' => $this->game->viewState($state, $id)]],
            '_merge_private' => true,
        ];
    }

    private function finish(array $state, string $id, string $description): string
    {
        $this->game->notifyState($state, $this->game->getPlayerNameById((int) $id) . ' ' . $description . '.');
        return $state['battle'] === null ? NextPlayer::class : BattleChoice::class;
    }

    #[PossibleAction]
    public function actPray(string $slot): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        $description = $this->game->doPray($state, $id, $slot);
        return $this->finish($state, $id, $description);
    }

    #[PossibleAction]
    public function actRaise(string $slot, string $zone, string $color, int $level): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        $description = $this->game->doRaise($state, $id, $slot, $zone, $color, $level);
        return $this->finish($state, $id, $description);
    }

    #[PossibleAction]
    public function actBuy(string $slot, string $tile): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        $description = $this->game->doBuy($state, $id, $slot, $tile);
        return $this->finish($state, $id, $description);
    }

    #[PossibleAction]
    public function actRecruit(string $slot, string $zone, int $units): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        $description = $this->game->doRecruit($state, $id, $slot, $zone, $units);
        return $this->finish($state, $id, $description);
    }

    #[PossibleAction]
    public function actMove(string $slot, string $from, string $to, int $units, bool $teleport, string $via): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        $description = $this->game->doMove($state, $id, $slot, $from, $to, $units, $teleport, $via);
        return $this->finish($state, $id, $description);
    }

    #[PossibleAction]
    public function actDayCard(string $card, string $zone): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        $description = $this->game->playDayCard($state, $id, $card, $zone);
        $this->game->notifyState($state, $this->game->getPlayerNameById((int) $id) . ' ' . $description . '.');
        return PlayerTurn::class;
    }

    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $id = (string) $playerId;
        foreach (['pray_b', 'pray_c'] as $slot) if (!in_array($slot, $state['players'][$id]['slots'], true)) {
            try { return $this->finish($state, $id, $this->game->doPray($state, $id, $slot)); } catch (UserException $e) {}
        }
        foreach (['move_a', 'recruit_a', 'raise'] as $slot) if (!in_array($slot, $state['players'][$id]['slots'], true)) {
            // A zombie may forfeit an unusable action while preserving round progress.
            $state['players'][$id]['slots'][] = $slot;
            return $this->finish($state, $id, 'forfeits an action');
        }
        return NextPlayer::class;
    }
}
