<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\kemetbloodandsands\Game;
use Bga\Games\kemetbloodandsands\PrototypeRules;

/**
 * A Day turn: the player plays 1 of their colored Action tokens and may also play 1 silver and/or the gold token,
 * in any order. Slots: '<space>', 'silver:<space>', 'gold:<tile>' (see CoreGame::requireSlot).
 * The turn stays here until the colored token is played and no bonus token is left (or the player ends it).
 */
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
        $turn = $this->game->turn($state);
        if ($turn['main'] || $turn['silver'] || $turn['gold']) return null;
        // Game end is checked at the start of a turn, before any Action token is played.
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
        $state = $this->game->loadState();
        $id = $this->game->turnPlayer($state);
        $turn = $this->game->turn($state);
        return [
            'turn' => $turn,
            'silver' => $turn['silver'] ? 0 : max(0, $this->game->silverAvailable($state, $id)),
            'golden' => $turn['gold'] || !empty($state['players'][$id]['goldUsed']) ? [] : $this->game->goldenTiles($state, $id),
        ];
    }

    private function finish(array $state): string
    {
        $this->game->notifyState($state);
        return $this->game->afterAction($state);
    }

    private function me(): string
    {
        return (string) $this->game->getCurrentPlayerId();
    }

    #[PossibleAction]
    public function actPray(string $slot, string $reinforce): string
    {
        $state = $this->game->loadState();
        $this->game->doPray($state, $this->me(), $slot, $reinforce);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actRaise(string $slot, string $zone, string $color, int $level): string
    {
        $state = $this->game->loadState();
        $this->game->doRaise($state, $this->me(), $slot, $zone, $color, $level);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actBuy(string $slot, string $tile): string
    {
        $state = $this->game->loadState();
        $this->game->doBuy($state, $this->me(), $slot, $tile);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actRecruit(string $slot, string $zone, int $units, string $creature): string
    {
        $state = $this->game->loadState();
        $this->game->doRecruit($state, $this->me(), $slot, $zone, $units, $creature);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actMove(string $slot, string $from, string $path, int $units, bool $creature): string
    {
        $state = $this->game->loadState();
        $this->game->doMove($state, $this->me(), $slot, $from, $path, $units, $creature);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actDayCard(string $card, string $zone): string
    {
        $state = $this->game->loadState();
        $this->game->playDayCard($state, $this->me(), $card, $zone);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actPlaceCreature(string $tile, string $zone): string
    {
        $state = $this->game->loadState();
        $this->game->placeCreature($state, $this->me(), $tile, $zone);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actRemoveCard(int $card): string
    {
        $state = $this->game->loadState();
        $this->game->removeBattleCard($state, $this->me(), $card);
        return $this->finish($state);
    }

    #[PossibleAction]
    public function actEndTurn(): string
    {
        $state = $this->game->loadState();
        $this->game->endTurn($state, $this->me());
        $this->game->notifyState($state);
        return NextPlayer::class;
    }

    /** Resolves a pending choice, ends a turn whose token was played, else prays or forfeits a legal space. */
    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $id = (string) $playerId;
        $turn = $this->game->turn($state);
        if (($turn['pending']['type'] ?? '') === 'removeCard') {
            $this->game->removeBattleCard($state, $id, $state['players'][$id]['battleDeck'][0]);
            return $this->finish($state);
        }
        if ($turn['main']) {
            $this->game->notifyState($state);
            return NextPlayer::class;
        }
        foreach (['pray_c', 'pray_b'] as $slot) {
            if (!$this->game->slotAllowed($state, $id, $slot)) continue;
            try {
                $this->game->doPray($state, $id, $slot);
                $this->game->notifyState($state);
                return NextPlayer::class;
            } catch (UserException) {
            }
        }
        foreach (array_keys(PrototypeRules::SLOTS) as $slot) {
            if (!$this->game->slotAllowed($state, $id, $slot)) continue;
            $this->game->useSlot($state, $id, ['token' => 'color', 'slot' => $slot, 'tile' => null, 'golden' => '']);
            $this->game->event('log', clienttranslate('${player_name} forfeits an action'), ['player_id' => $id]);
            $this->game->notifyState($state);
            return NextPlayer::class;
        }
        return NextPlayer::class;
    }
}
