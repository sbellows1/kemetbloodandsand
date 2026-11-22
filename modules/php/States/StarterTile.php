<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\kemetbloodandsands\Game;

/** Setup 2.3 step 4: in reverse turn order, each player chooses a Level 1 Power tile of one of their Pyramid colors. */
class StarterTile extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct($game, id: 5, type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must choose a Level 1 Power tile'),
            descriptionMyTurn: clienttranslate('${you} must choose a Level 1 Power tile of one of your Pyramid colors'),
        );
    }

    public function getArgs(): array
    {
        $state = $this->game->loadState();
        $id = $state['starter']['queue'][$state['starter']['index']] ?? '';
        return ['options' => $id === '' ? [] : $this->game->starterOptions($state, $id)];
    }

    #[PossibleAction]
    public function actStarterTile(string $tile): string
    {
        return $this->resolve((string) $this->game->getCurrentPlayerId(), $tile);
    }

    public function zombie(int $playerId): string
    {
        $state = $this->game->loadState();
        return $this->resolve((string) $playerId, $this->game->starterOptions($state, (string) $playerId)[0]);
    }

    private function resolve(string $id, string $tile): string
    {
        $state = $this->game->loadState();
        $done = $this->game->chooseStarter($state, $id, $tile);
        $this->game->notifyState($state);
        if (!$done) {
            $this->game->gamestate->changeActivePlayer((int) $state['starter']['queue'][$state['starter']['index']]);
            return StarterTile::class;
        }
        $this->game->gamestate->changeActivePlayer((int) $state['order'][0]);
        return PlayerTurn::class;
    }
}
