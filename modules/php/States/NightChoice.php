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
        parent::__construct($game, id: 40, type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must make night decisions'),
            descriptionMyTurn: clienttranslate('${you} must make your night decisions'),
        );
    }
    public function onEnteringState(): void
    {
        $state = $this->game->loadState();
        $this->game->gamestate->changeActivePlayer((int) $state['order'][$state['night']['index']]);
    }
    public function getArgs(): array
    {
        $state = $this->game->loadState();
        $id = $state['order'][$state['night']['index']];
        return [
            'canOffer' => $this->game->controller($state['zones']['sanctuary']) === $id && ($state['zones']['sanctuary']['units'][$id] ?? 0) >= 2,
            'canDelta' => $this->game->controller($state['zones']['delta']) === $id && ($state['zones']['delta']['units'][$id] ?? 0) >= 1,
            'veterans' => $state['players'][$id]['veterans'],
        ];
    }
    #[PossibleAction]
    public function actNightChoice(bool $offer, bool $delta, int $pray, int $draw, int $recruit, string $district): string
    {
        $state = $this->game->loadState();
        $id = (string) $this->game->getCurrentPlayerId();
        if ($state['order'][$state['night']['index']] !== $id) throw new UserException(clienttranslate('It is not your night decision.'));
        // Each Veteran token buys 1 PP, 1 extra DI card, or 1 Unit.
        if ($pray < 0 || $draw < 0 || $recruit < 0 || $pray + $draw + $recruit > $state['players'][$id]['veterans']) throw new UserException(clienttranslate('You do not have enough veteran tokens.'));
        if ($offer && ($this->game->controller($state['zones']['sanctuary']) !== $id || ($state['zones']['sanctuary']['units'][$id] ?? 0) < 2)) throw new UserException(clienttranslate('You cannot make the sanctuary offering.'));
        if ($delta && ($this->game->controller($state['zones']['delta']) !== $id || ($state['zones']['delta']['units'][$id] ?? 0) < 1)) throw new UserException(clienttranslate('You do not control the Delta temple.'));
        if ($recruit > 0 && (!isset($state['zones'][$district]) || $state['zones'][$district]['city'] !== $id || $this->game->controller($state['zones'][$district]) !== $id)) throw new UserException(clienttranslate('Recruit veterans in a free home district.'));
        $state['night']['choices'][$id] = compact('offer', 'delta', 'pray', 'draw', 'recruit', 'district');
        $this->game->event('log', clienttranslate('${player_name} completes night decisions'), ['player_id' => $id]);
        return $this->advance($state);
    }
    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        $state['night']['choices'][(string) $playerId] = ['offer' => false, 'delta' => false, 'pray' => 0, 'draw' => 0, 'recruit' => 0, 'district' => ''];
        return $this->advance($state);
    }
    private function advance(array $state): string
    {
        $state['night']['index']++;
        if ($state['night']['index'] >= count($state['order'])) {
            $this->game->resolveNight($state);
            if ($state['nightPowers']) {
                $this->game->notifyState($state);
                return NightPowers::class;
            }
            $this->game->startDestiny($state);
            $this->game->notifyState($state);
            return Destiny::class;
        }
        $this->game->notifyState($state);
        $this->game->gamestate->changeActivePlayer((int) $state['order'][$state['night']['index']]);
        return NightChoice::class;
    }
}
