<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands;

use Bga\GameFramework\UserException;
use Bga\Games\kemetbloodandsands\States\BattleChoice;
use Bga\Games\kemetbloodandsands\States\NextPlayer;
use Bga\Games\kemetbloodandsands\States\PlayerTurn;
use Bga\Games\kemetbloodandsands\States\StarterTile;

class CoreGame extends \Bga\GameFramework\Table
{
    /** Log events queued by rule methods and sent by notifyState(), in order. */
    private array $events = [];

    public function loadState(): array
    {
        $json = $this->getUniqueValueFromDB('SELECT state_json FROM kemet_state WHERE id = 1');
        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function saveState(array $state): void
    {
        foreach ($state['players'] as $id => $player) {
            $this->bga->playerScore->set((int) $id, $this->score($state, (string) $id));
        }
        $json = self::escapeStringForDB(json_encode($state, JSON_THROW_ON_ERROR));
        self::DbQuery("REPLACE INTO kemet_state (id, state_json) VALUES (1, '$json')");
    }

    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $colors = $gameinfos['player_colors'];
        $query = [];
        foreach ($players as $id => $player) {
            $name = self::escapeStringForDB($player['player_name']);
            $query[] = '(' . (int) $id . ", '" . array_shift($colors) . "', '$name')";
        }
        self::DbQuery('INSERT INTO player (player_id, player_color, player_name) VALUES ' . implode(',', $query));
        $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        $this->reloadPlayersBasicInfos();

        $this->bga->tableStats->init('rounds', 1);
        $this->bga->playerStats->init(['battles_fought', 'battles_won', 'battle_fp', 'units_recruited', 'pyramid_levels', 'tiles_bought'], 0);

        $order = array_map('strval', array_keys($players));
        shuffle($order);
        $count = count($order);
        // Setup 2.1 step 2: randomly select the Power tile colors for the player count, kept in catalog order.
        $picked = PrototypeRules::COLORS;
        shuffle($picked);
        $picked = array_slice($picked, 0, $count === 2 ? 2 : ($count === 5 ? 4 : 3));
        $activeColors = array_values(array_intersect(PrototypeRules::COLORS, $picked));
        $zones = PrototypeRules::board($order);
        $playerState = [];
        foreach ($order as $index => $id) {
            $zones["c{$id}_1"]['units'][$id] = 5;
            $zones["c{$id}_2"]['units'][$id] = 5;
            $zones["c{$id}_1"]['pyramid'] = ['owner' => $id, 'color' => $activeColors[$index % count($activeColors)], 'level' => 2];
            $zones["c{$id}_2"]['pyramid'] = ['owner' => $id, 'color' => $activeColors[($index + 1) % count($activeColors)], 'level' => 1];
            $playerState[$id] = [
                'pp' => 7, 'permanent' => 0, 'veterans' => 0, 'extraUnits' => 0,
                'slots' => [], 'bought' => [], 'tiles' => [],
                // Each player owns 1 gold Action token (rulebook v DEF); silver tokens come from Act of God tiles.
                'silverSlots' => [], 'goldUsed' => false, 'creatures' => [],
                'battleDeck' => PrototypeRules::BASE_BATTLE_CARDS,
                'battleHand' => PrototypeRules::BASE_BATTLE_CARDS,
                'battleDiscard' => [], 'battlePlayed' => [], 'diHand' => ['diversion'],
            ];
        }
        $deck = [];
        foreach (['blessing', 'reinforce', 'fury', 'wall', 'wound'] as $card) {
            for ($i = 0; $i < 7; $i++) $deck[] = $card;
        }
        shuffle($deck);
        $state = [
            'round' => 1, 'order' => $order, 'turnIndex' => 0, 'colors' => $activeColors,
            'zones' => $zones, 'players' => $playerState,
            'market' => array_fill_keys(PrototypeRules::tilesInPlay($activeColors, $count), true),
            'diDeck' => $deck, 'diDiscard' => [], 'battle' => null, 'night' => null, 'destiny' => null, 'nightPowers' => null, 'turn' => null,
            // Setup 2.3 step 4: in reverse turn order, each player chooses a Level 1 Power tile of one of their Pyramid colors.
            'starter' => ['queue' => array_reverse($order), 'index' => 0],
        ];
        foreach ($order as $id) {
            $this->drawDi($state, $id, 2);
        }
        $this->saveState($state);
        $this->gamestate->changeActivePlayer((int) $state['starter']['queue'][0]);
        return StarterTile::class;
    }

    /** Level 1 tiles the player may take at setup: in the market, of one of their Pyramid colors. */
    public function starterOptions(array $state, string $id): array
    {
        $colors = [];
        foreach ($state['zones'] as $zone) if (($zone['pyramid']['owner'] ?? null) === $id) $colors[] = $zone['pyramid']['color'];
        $owned = array_map(fn($t) => PrototypeRules::tiles()[$t]['name'], $state['players'][$id]['tiles']);
        $options = [];
        foreach (PrototypeRules::tiles() as $key => $tile) {
            if ($tile['level'] !== 1 || empty($state['market'][$key]) || !in_array($tile['color'], $colors, true) || in_array($tile['name'], $owned, true)) continue;
            // One option per market stack: the top copy of a duplicate pair.
            $options[$tile['stack']] ??= $key;
        }
        return array_values($options);
    }

    /** Records a setup tile choice. Returns true once every player has chosen. */
    public function chooseStarter(array &$state, string $id, string $tile): bool
    {
        $starter = $state['starter'] ?? null;
        if ($starter === null || $starter['queue'][$starter['index']] !== $id) throw new UserException(clienttranslate('It is not your setup choice.'));
        if (!in_array($tile, $this->starterOptions($state, $id), true)) throw new UserException(clienttranslate('Choose a Level 1 Power tile of one of your Pyramid colors.'));
        $state['market'][$tile] = false;
        $this->event('buy', clienttranslate('${player_name} takes ${tile_name}'), ['player_id' => $id, 'tile' => $tile, 'tile_name' => PrototypeRules::tiles()[$tile]['name'], 'cost' => 0, 'i18n' => ['tile_name']]);
        $this->acquireTile($state, $id, $tile);
        $state['starter']['index']++;
        if ($state['starter']['index'] < count($starter['queue'])) return false;
        $state['starter'] = null;
        return true;
    }

    // ---------------------------------------------------------------- turn structure

    /** The current Day turn: whether the colored, silver and gold Action tokens were played, and any pending choice. */
    public function turn(array &$state): array
    {
        $key = $state['round'] . ':' . $state['turnIndex'];
        if (($state['turn']['key'] ?? '') !== $key) $state['turn'] = ['key' => $key, 'main' => false, 'silver' => false, 'gold' => false, 'pending' => null];
        return $state['turn'];
    }
    public function turnPlayer(array $state): string
    {
        return $state['order'][$state['turnIndex'] % count($state['order'])];
    }
    public function silverAvailable(array $state, string $id): int
    {
        return $this->tileBonus($state, $id, 'silver') - count($state['players'][$id]['silverSlots'] ?? []);
    }
    /** Owned tiles with a Golden Action, usable once per round with the gold Action token. */
    public function goldenTiles(array $state, string $id): array
    {
        return array_values(array_filter($state['players'][$id]['tiles'], fn($t) => isset(PrototypeRules::tiles()[$t]['golden'])));
    }
    /** True while the turn player may still play a silver or gold Action token this turn. */
    public function bonusAvailable(array $state, string $id): bool
    {
        $turn = $this->turn($state);
        $silver = !$turn['silver'] && $this->silverAvailable($state, $id) > 0
            && (bool) array_filter(array_keys(PrototypeRules::SLOTS), fn($s) => $this->slotAllowed($state, $id, 'silver:' . $s));
        $gold = !$turn['gold'] && empty($state['players'][$id]['goldUsed']) && $this->goldenTiles($state, $id);
        return $silver || $gold;
    }
    /** State after a turn action resolved without a battle, or after its battle ended. */
    public function afterAction(array &$state): string
    {
        if ($state['battle'] !== null) return BattleChoice::class;
        $id = $this->turnPlayer($state);
        $turn = $this->turn($state);
        if ($turn['main'] && $turn['pending'] === null && !$this->bonusAvailable($state, $id)) return NextPlayer::class;
        $this->gamestate->changeActivePlayer((int) $id);
        return PlayerTurn::class;
    }
    public function endTurn(array &$state, string $id): void
    {
        $turn = $this->turn($state);
        if ($this->turnPlayer($state) !== $id || !$turn['main'] || $turn['pending'] !== null) throw new UserException(clienttranslate('Play one of your Action tokens before ending your turn.'));
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        $state = $this->loadState();
        $players = $this->getCollectionFromDb('SELECT player_id AS id, player_name AS name, player_color AS color, player_score AS score FROM player');
        return [
            'players' => $players,
            'kemet' => $this->viewState($state, (string) $currentPlayerId),
        ] + $this->definitions();
    }

    /** Static catalogs the client needs to render and validate choices. */
    public function definitions(): array
    {
        return [
            'slots' => PrototypeRules::SLOTS,
            'tiles' => PrototypeRules::tiles(),
            'battleCards' => PrototypeRules::battleCards(),
            'diCards' => PrototypeRules::di(),
            'colorNames' => PrototypeRules::colorNames(),
            'actionNames' => PrototypeRules::actionNames(),
        ];
    }

    /** Public view of the state; the viewer's own hands are added when $viewer is given. */
    public function viewState(array $state, string $viewer = ''): array
    {
        $view = $state;
        $view['citySites'] = PrototypeRules::citySites($state['order']);
        unset($view['diDeck'], $view['diDiscard']);
        foreach ($view['players'] as $id => &$player) {
            $player['score'] = $this->score($state, (string) $id);
            $player['diCount'] = count($player['diHand']);
            $player['battleCount'] = count($player['battleHand']);
            $player['battlePlayed'] ??= [];
            unset($player['diHand'], $player['battleHand'], $player['battleDiscard']);
            if ((string) $id === $viewer) {
                $player['diHand'] = $state['players'][$id]['diHand'];
                $player['battleHand'] = $state['players'][$id]['battleHand'];
            }
        }
        unset($player);
        if ($view['battle'] !== null) {
            if ($view['battle']['step'] === 'cards') {
                $spyCard = $this->spyCard($state, $viewer);
                $view['battle']['choices'] = array_fill_keys(array_keys($view['battle']['choices']), true);
                if ($spyCard !== null) $view['battle']['spyCard'] = $spyCard;
            } else {
                // The War Council discard stays facedown and is never revealed.
                foreach ($view['battle']['choices'] as &$choice) unset($choice['discarded']);
                unset($choice);
            }
        }
        return $view;
    }

    /** Queues a log notification. 'player_id' / 'player_id2' args get matching player names. */
    public function event(string $type, string $message, array $args = []): void
    {
        foreach (['player_id' => 'player_name', 'player_id2' => 'player_name2'] as $idKey => $nameKey) {
            if (isset($args[$idKey])) $args[$nameKey] = $this->getPlayerNameById((int) $args[$idKey]);
        }
        $this->events[] = [$type, $message, $args];
    }

    /**
     * Saves the state, sends queued log events, then one silent public state sync
     * and each player's private hands.
     */
    public function notifyState(array &$state): void
    {
        $this->syncCreatures($state);
        $this->saveState($state);
        $events = $this->events;
        $this->events = [];
        foreach ($events as [$type, $message, $args]) $this->bga->notify->all($type, $message, $args);
        $this->bga->notify->all('kemetState', '', ['kemet' => $this->viewState($state)]);
        foreach ($state['players'] as $id => $player) {
            $this->bga->notify->player((int) $id, 'hand', '', ['diHand' => $player['diHand'], 'battleHand' => $player['battleHand'], 'spyCard' => $this->spyCard($state, (string) $id)]);
        }
    }

    /** Spy: the Battle card the opponent committed, shown to the Spy owner before they choose. */
    public function spyCard(array $state, string $viewer): ?int
    {
        $battle = $state['battle'] ?? null;
        if ($battle === null || ($battle['spy'] ?? null) !== $viewer || $battle['step'] !== 'cards') return null;
        $opponent = $viewer === $battle['attacker'] ? $battle['defender'] : $battle['attacker'];
        return $battle['choices'][$opponent]['played'] ?? null;
    }

    // ---------------------------------------------------------------- creatures

    /** The Creature tile a player has tied to their Troop in a zone, if any. */
    public function creatureAt(array $state, string $id, string $zone): ?string
    {
        foreach ($state['players'][$id]['creatures'] ?? [] as $tile => $at) if ($at === $zone) return (string) $tile;
        return null;
    }
    public function creatureStats(array $state, string $id, string $zone): array
    {
        $tile = $this->creatureAt($state, $id, $zone);
        return $tile === null ? [] : PrototypeRules::tiles()[$tile]['creature'];
    }
    /** A Creature no longer tied to a Troop goes back to its owner's supply. */
    public function syncCreatures(array &$state): void
    {
        foreach ($state['players'] as $id => $player) {
            foreach ($player['creatures'] ?? [] as $tile => $at) {
                if ($at === null || ($state['zones'][$at]['units'][$id] ?? 0) > 0) continue;
                $state['players'][$id]['creatures'][$tile] = null;
                $this->event('log', clienttranslate('${tile_name} returns to ${player_name}\'s supply'), ['player_id' => (string) $id, 'tile_name' => PrototypeRules::tiles()[$tile]['name'], 'i18n' => ['tile_name']]);
            }
        }
    }
    /**
     * Before playing an Action token, a player may place a Creature from their supply in a Troop in one of their
     * Districts, exchange Creatures between those Troops, or send one back to their supply ($zone = '').
     */
    public function placeCreature(array &$state, string $id, string $tile, string $zone): void
    {
        if ($this->turnPlayer($state) !== $id || $this->turn($state)['pending'] !== null || $state['battle'] !== null) throw new UserException(clienttranslate('You can only arrange your Creatures during your turn.'));
        if (!array_key_exists($tile, $state['players'][$id]['creatures'] ?? [])) throw new UserException(clienttranslate('You do not own that Creature.'));
        $from = $state['players'][$id]['creatures'][$tile];
        if ($zone !== '') {
            $target = $state['zones'][$zone] ?? null;
            if ($target === null || $target['kind'] !== 'district' || $target['city'] !== $id || ($target['units'][$id] ?? 0) < 1) throw new UserException(clienttranslate('Place Creatures with one of your Troops in your Districts.'));
            $other = $this->creatureAt($state, $id, $zone);
            if ($other !== null && $other !== $tile) $state['players'][$id]['creatures'][$other] = $from;
        }
        $state['players'][$id]['creatures'][$tile] = $zone === '' ? null : $zone;
        $this->event('creature', $zone === '' ? clienttranslate('${player_name} returns ${tile_name} to their supply')
            : clienttranslate('${player_name} ties ${tile_name} to their Troop in ${zone_name}'), [
            'player_id' => $id, 'tile_name' => PrototypeRules::tiles()[$tile]['name'], 'zone' => $zone, 'i18n' => ['tile_name'],
            'zone_name' => $zone === '' ? '' : $this->zoneArg($state, $zone),
        ]);
    }

    /** Log argument for a zone: a nested, translatable "<name>" or "<city> <district>". */
    public function zoneArg(array $state, string $key): array
    {
        $zone = $state['zones'][$key];
        return $zone['district']
            ? ['log' => '${city_name} ${district}', 'args' => ['city_name' => $zone['name'], 'district' => $zone['district'], 'i18n' => ['city_name']]]
            : ['log' => '${name}', 'args' => ['name' => $zone['name'], 'i18n' => ['name']]];
    }

    public function score(array $state, string $id): int
    {
        $score = $state['players'][$id]['permanent'];
        foreach ($state['zones'] as $zone) {
            if ($this->controller($zone) !== $id) continue;
            if ($zone['kind'] === 'temple') $score++;
            if (($zone['pyramid']['level'] ?? 0) === 4) $score++;
        }
        return $score;
    }

    public function controller(array $zone): ?string
    {
        foreach ($zone['units'] as $id => $units) if ($units > 0) return (string) $id;
        return $zone['kind'] === 'district' ? $zone['city'] : null;
    }

    public function drawDi(array &$state, string $id, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            if (!$state['diDeck']) {
                $state['diDeck'] = $state['diDiscard'];
                $state['diDiscard'] = [];
                shuffle($state['diDeck']);
            }
            if ($state['diDeck']) $state['players'][$id]['diHand'][] = array_pop($state['diDeck']);
        }
    }

    /**
     * Validates the Action token a player plays for an action and returns what it is:
     *   '<space>'         one of their 5 colored tokens (one per turn),
     *   'silver:<space>'  a silver token on another empty space (Act of God, one per turn),
     *   'gold:<tile>'     the gold token on the Divine Power space, resolving that tile's Golden Action (once per round).
     * Equilibrium: the remaining colored tokens must still be able to cover every pyramid level.
     */
    public function requireSlot(array &$state, string $id, string $slot, string $action): array
    {
        $turn = $this->turn($state);
        if ($this->turnPlayer($state) !== $id || $state['battle'] !== null) throw new UserException(clienttranslate('It is not your turn.'));
        if ($turn['pending'] !== null) throw new UserException(clienttranslate('Finish your current choice first.'));
        $player = $state['players'][$id];
        if (str_starts_with($slot, 'gold:')) {
            $tile = substr($slot, 5);
            $golden = in_array($tile, $player['tiles'], true) ? (PrototypeRules::tiles()[$tile]['golden'] ?? '') : '';
            $actions = ['move_recruit' => ['move', 'recruit'], 'acquire' => ['buy'], 'move_noteleport' => ['move'], 'move_surprise' => ['move']][$golden] ?? [];
            if (!in_array($action, $actions, true)) throw new UserException(clienttranslate('That Golden Action cannot take this action.'));
            if ($turn['gold'] || !empty($player['goldUsed'])) throw new UserException(clienttranslate('Your gold Action token was already played this round.'));
            return ['token' => 'gold', 'slot' => 'gold', 'tile' => $tile, 'golden' => $golden];
        }
        $silver = str_starts_with($slot, 'silver:');
        $space = $silver ? substr($slot, 7) : $slot;
        if (!isset(PrototypeRules::SLOTS[$space]) || PrototypeRules::SLOTS[$space]['action'] !== $action) throw new UserException(clienttranslate('Choose an action space of the right kind.'));
        $occupied = array_merge($player['slots'], $player['silverSlots'] ?? []);
        if (in_array($space, $occupied, true)) throw new UserException(clienttranslate('That action space is unavailable.'));
        if ($silver) {
            if ($turn['silver'] || $this->silverAvailable($state, $id) < 1) throw new UserException(clienttranslate('You have no silver Action token to play this turn.'));
            $left = 5 - count($player['slots']);
        } else {
            if ($turn['main'] || count($player['slots']) >= 5) throw new UserException(clienttranslate('You already played one of your Action tokens this turn.'));
            $left = 5 - count($player['slots']) - 1;
        }
        $tiers = array_unique(array_map(fn($s) => PrototypeRules::SLOTS[$s]['tier'], array_merge($occupied, [$space])));
        if ($left < 3 - count($tiers)) throw new UserException(clienttranslate('Leave enough tokens to cover all three action tiers.'));
        return ['token' => $silver ? 'silver' : 'color', 'slot' => $space, 'tile' => null, 'golden' => ''];
    }

    /** Places the validated Action token. */
    public function useSlot(array &$state, string $id, array $info): void
    {
        $this->turn($state);
        if ($info['token'] === 'gold') {
            $state['players'][$id]['goldUsed'] = true;
            $state['turn']['gold'] = true;
        } elseif ($info['token'] === 'silver') {
            $state['players'][$id]['silverSlots'][] = $info['slot'];
            $state['turn']['silver'] = true;
        } else {
            $state['players'][$id]['slots'][] = $info['slot'];
            $state['turn']['main'] = true;
        }
    }

    /** True when the token can be played there now and the Equilibrium rule stays satisfiable. */
    public function slotAllowed(array $state, string $id, string $slot): bool
    {
        $space = str_starts_with($slot, 'silver:') ? substr($slot, 7) : $slot;
        if (!isset(PrototypeRules::SLOTS[$space])) return false;
        try {
            $this->requireSlot($state, $id, $slot, PrototypeRules::SLOTS[$space]['action']);
            return true;
        } catch (UserException) {
            return false;
        }
    }

    /** Final PP cost of one effect after Priest of Ra (every cost of at least 1 PP is reduced by 1). */
    public function cost(array $state, string $id, int $cost): int
    {
        return $cost >= 1 ? max(0, $cost - $this->tileBonus($state, $id, 'spend_discount')) : max(0, $cost);
    }
    /** Pays one effect's PP cost (already reduced by the caller's own discounts); returns what was paid. */
    public function spend(array &$state, string $id, int $cost): int
    {
        $cost = $this->cost($state, $id, $cost);
        if ($state['players'][$id]['pp'] < $cost) throw new UserException(clienttranslate('Not enough prayer points.'));
        $state['players'][$id]['pp'] -= $cost;
        return $cost;
    }
    /** Gains PP up to the cap of 11 and returns the amount actually gained. Divine Might adds +2 to each gain. */
    public function gain(array &$state, string $id, int $amount): int
    {
        if ($amount <= 0) return 0;
        $amount += $this->tileBonus($state, $id, 'pp_gain_bonus');
        $before = $state['players'][$id]['pp'];
        $state['players'][$id]['pp'] = min(11, $before + $amount);
        return $state['players'][$id]['pp'] - $before;
    }
    public function unitCount(array $state, string $id): int
    {
        $total = 0;
        foreach ($state['zones'] as $zone) $total += $zone['units'][$id] ?? 0;
        return $total;
    }
    /** Units a player owns in total: 12 miniatures plus any Mercenaries that joined them. */
    public function supply(array $state, string $id): int
    {
        return 12 + ($state['players'][$id]['extraUnits'] ?? 0);
    }
    /** Maximum units in one Troop: 5, or 7 with Legion. */
    public function troopLimit(array $state, string $id): int
    {
        return max(5, $this->tileBonus($state, $id, 'troop_limit'));
    }
    /** Sum of one modifier over the player's Power tiles (troop_limit takes the highest value instead). */
    public function tileBonus(array $state, string $id, string $mod): int
    {
        $n = 0;
        $tiles = PrototypeRules::tiles();
        foreach ($state['players'][$id]['tiles'] as $key) {
            $value = $tiles[$key]['mods'][$mod] ?? 0;
            $n = $mod === 'troop_limit' ? max($n, $value) : $n + $value;
        }
        return $n;
    }

    /**
     * Pray. $reinforce lists the Districts (comma-separated, repeats allowed) receiving free Units from
     * Reinforcement Prayer: up to 2 Units in total in the player's own City; a District held by an opponent
     * triggers a Battle as for Recruit (the prototype resolves one Battle per action, so at most one such District).
     */
    public function doPray(array &$state, string $id, string $slot, string $reinforce = ''): void
    {
        $info = $this->requireSlot($state, $id, $slot, 'pray');
        $pp = $this->gain($state, $id, 2 + $this->tileBonus($state, $id, 'pray'));
        $this->useSlot($state, $id, $info);
        $this->event('pray', clienttranslate('${player_name} prays and gains ${pp} PP'), ['player_id' => $id, 'pp' => $pp]);
        $targets = $reinforce === '' ? [] : explode(',', $reinforce);
        if (!$targets) return;
        if (count($targets) > $this->tileBonus($state, $id, 'pray_units')) throw new UserException(clienttranslate('Reinforcement Prayer adds at most 2 Units.'));
        $battleZone = null;
        foreach (array_count_values($targets) as $zoneKey => $count) {
            $zoneKey = (string) $zoneKey;
            $zone = $state['zones'][$zoneKey] ?? null;
            if ($zone === null || $zone['kind'] !== 'district' || $zone['city'] !== $id) throw new UserException(clienttranslate('Add the Units in your own Districts.'));
            if (($zone['units'][$id] ?? 0) + $count > $this->troopLimit($state, $id) || $this->unitCount($state, $id) + $count > $this->supply($state, $id)) throw new UserException(clienttranslate('Troop limit or supply exceeded.'));
            if ($this->controller($zone) !== $id) {
                if ($battleZone !== null) throw new UserException(clienttranslate('Add Units to at most one District held by an opponent.'));
                $battleZone = $zoneKey;
            }
            $state['zones'][$zoneKey]['units'][$id] = ($zone['units'][$id] ?? 0) + $count;
            $this->event('recruit', clienttranslate('${player_name} adds ${units} unit(s) in ${zone_name} (Reinforcement Prayer)'), [
                'player_id' => $id, 'units' => $count, 'zone' => $zoneKey, 'zone_name' => $this->zoneArg($state, $zoneKey),
            ]);
        }
        if ($battleZone !== null) $this->battleIfNeeded($state, $id, $battleZone);
    }

    public function doRaise(array &$state, string $id, string $slot, string $zoneKey, string $color, int $level): void
    {
        $info = $this->requireSlot($state, $id, $slot, 'raise');
        if (!isset($state['zones'][$zoneKey]) || $state['zones'][$zoneKey]['city'] !== $id || !in_array($color, $state['colors'], true) || $level < 1 || $level > 4) throw new UserException(clienttranslate('Invalid pyramid choice.'));
        $old = $state['zones'][$zoneKey]['pyramid'];
        if ($old !== null && ($old['owner'] !== $id || $old['color'] !== $color || $level <= $old['level'])) throw new UserException(clienttranslate('Choose your pyramid and a higher level.'));
        if ($old === null) {
            foreach ($state['zones'] as $other) if (($other['pyramid']['owner'] ?? null) === $id && ($other['pyramid']['color'] ?? null) === $color) throw new UserException(clienttranslate('You already have a pyramid of that color.'));
        }
        $cost = 0;
        $discount = $this->tileBonus($state, $id, 'raise_discount');
        for ($i = ($old['level'] ?? 0) + 1; $i <= $level; $i++) $cost += max(0, $i - $discount);
        $cost = $this->spend($state, $id, $cost);
        $state['zones'][$zoneKey]['pyramid'] = ['owner' => $id, 'color' => $color, 'level' => $level];
        $this->useSlot($state, $id, $info);
        $this->bga->playerStats->inc('pyramid_levels', $level - ($old['level'] ?? 0), (int) $id);
        $this->event('raise', clienttranslate('${player_name} raises a ${color_name} pyramid to level ${level} in ${zone_name} for ${cost} PP'), [
            'player_id' => $id, 'color_name' => PrototypeRules::colorNames()[$color], 'level' => $level, 'cost' => $cost,
            'zone_name' => $this->zoneArg($state, $zoneKey), 'zone' => $zoneKey, 'i18n' => ['color_name'],
        ]);
    }

    public function doBuy(array &$state, string $id, string $slot, string $tile): void
    {
        $info = $this->requireSlot($state, $id, $slot, 'buy');
        // Twin Ceremony (Golden Action): no Acquire space needed, +1 PP, and the color may repeat this Round.
        $twin = $info['golden'] === 'acquire';
        $slot = $info['slot'];
        $tiles = PrototypeRules::tiles();
        if (!isset($tiles[$tile]) || empty($state['market'][$tile])) throw new UserException(clienttranslate('That power tile is unavailable.'));
        $def = $tiles[$tile];
        if (!$twin && in_array($def['color'], $state['players'][$id]['bought'], true)) throw new UserException(clienttranslate('You already bought a tile of that color today.'));
        foreach ($state['players'][$id]['tiles'] as $owned) if ($tiles[$owned]['name'] === $def['name']) throw new UserException(clienttranslate('You already own that Power tile.'));
        $ownPyramid = false;
        $foreignPyramid = false;
        foreach ($state['zones'] as $zone) {
            if ($this->controller($zone) !== $id || ($zone['pyramid']['color'] ?? null) !== $def['color'] || ($zone['pyramid']['level'] ?? 0) < $def['level']) continue;
            if ($zone['city'] === $id) $ownPyramid = true;
            else $foreignPyramid = true;
        }
        if (!$ownPyramid && !$foreignPyramid) throw new UserException(clienttranslate('Control a pyramid of this color and level first.'));
        if (!$twin && $ownPyramid && $slot !== 'buy_' . $def['color'] && !$foreignPyramid) throw new UserException(clienttranslate('Use the matching buy action space.'));
        if (!$twin && $foreignPyramid) {
            $slotColor = substr($slot, 4);
            $hasToken = false;
            foreach ($state['zones'] as $zone) if ($zone['city'] === $id && ($zone['pyramid']['color'] ?? null) === $slotColor) $hasToken = true;
            if (!$hasToken) throw new UserException(clienttranslate('Use a buy space with one of your pyramid tokens.'));
        }
        $cost = $this->spend($state, $id, max(0, $def['level'] - $this->tileBonus($state, $id, 'buy_discount')) + ($twin ? 1 : 0));
        $state['market'][$tile] = false;
        $state['players'][$id]['bought'][] = $def['color'];
        $this->useSlot($state, $id, $info);
        $this->bga->playerStats->inc('tiles_bought', 1, (int) $id);
        $this->event('buy', clienttranslate('${player_name} buys ${tile_name} for ${cost} PP'), [
            'player_id' => $id, 'tile' => $tile, 'tile_name' => $def['name'], 'cost' => $cost, 'i18n' => ['tile_name'],
        ]);
        $this->acquireTile($state, $id, $tile);
    }

    /** Gives a Power tile to a player and applies its "When acquired" effects. */
    public function acquireTile(array &$state, string $id, string $tile): void
    {
        $def = PrototypeRules::tiles()[$tile];
        $state['players'][$id]['tiles'][] = $tile;
        if (isset($def['creature'])) {
            // The Creature starts in the supply; the owner may tie it to a Troop in their Districts (placeCreature).
            $state['players'][$id]['creatures'][$tile] = null;
        }
        if (!empty($def['mods']['silver'])) {
            $this->event('log', clienttranslate('${player_name} gains 1 silver Action token'), ['player_id' => $id]);
        }
        if (isset($def['card'])) {
            // Take back every Battle card, then remove 1 from the game in secret (removeBattleCard) for the Special card.
            $player = &$state['players'][$id];
            $player['battleDeck'] ??= PrototypeRules::BASE_BATTLE_CARDS;
            $player['battleHand'] = $player['battleDeck'];
            $player['battleDiscard'] = [];
            $player['battlePlayed'] = [];
            unset($player);
            $this->turn($state);
            $state['turn']['pending'] = ['type' => 'removeCard', 'card' => $def['card']];
        }
        if (!empty($def['mods']['divine_fp'])) {
            $state['players'][$id]['permanent'] += $def['mods']['divine_fp'];
            $this->event('log', clienttranslate('${player_name} gains 1 Divine FP from ${tile_name}'), ['player_id' => $id, 'tile_name' => $def['name'], 'i18n' => ['tile_name']]);
        }
        $extra = $def['mods']['acquire_units'] ?? 0;
        if ($extra > 0) {
            // Mercenaries join the faction: they raise the player's supply and go to their controlled home Districts, fullest-room first.
            $state['players'][$id]['extraUnits'] = ($state['players'][$id]['extraUnits'] ?? 0) + $extra;
            $limit = $this->troopLimit($state, $id);
            $placed = 0;
            while ($placed < $extra) {
                $best = null;
                foreach ($state['zones'] as $key => $zone) {
                    if ($zone['kind'] !== 'district' || $zone['city'] !== $id || $this->controller($zone) !== $id || ($zone['units'][$id] ?? 0) >= $limit) continue;
                    if ($best === null || ($zone['units'][$id] ?? 0) < ($state['zones'][$best]['units'][$id] ?? 0)) $best = $key;
                }
                if ($best === null) break;
                $state['zones'][$best]['units'][$id] = ($state['zones'][$best]['units'][$id] ?? 0) + 1;
                $placed++;
            }
            $this->event('log', clienttranslate('${player_name}\'s ${tile_name} join their faction: ${units} unit(s) added to their Districts'), [
                'player_id' => $id, 'tile_name' => $def['name'], 'units' => $placed, 'i18n' => ['tile_name'],
            ]);
        }
    }

    /** Wrryt Storm / Shetyw Barrier: the player removes 1 Battle card from the game (kept secret) for the Special card. */
    public function removeBattleCard(array &$state, string $id, int $card): void
    {
        $pending = $this->turn($state)['pending'];
        if ($this->turnPlayer($state) !== $id || ($pending['type'] ?? '') !== 'removeCard') throw new UserException(clienttranslate('There is no Battle card to remove.'));
        $player = &$state['players'][$id];
        if (!in_array($card, $player['battleDeck'], true)) throw new UserException(clienttranslate('Choose one of your Battle cards.'));
        $player['battleDeck'] = array_values(array_diff($player['battleDeck'], [$card]));
        $player['battleDeck'][] = $pending['card'];
        $player['battleHand'] = $player['battleDeck'];
        unset($player);
        $state['turn']['pending'] = null;
        $this->event('log', clienttranslate('${player_name} removes a Battle card from the game in secret and adds ${card_name}'), [
            'player_id' => $id, 'card_name' => PrototypeRules::battleCards()[$pending['card']]['name'], 'i18n' => ['card_name'],
        ]);
    }

    /**
     * Recruit into a District of the player's City, or with Local Recruitment into any of their Troops.
     * $creature optionally ties a Creature from the supply to the Units added in a District.
     */
    public function doRecruit(array &$state, string $id, string $slot, string $zoneKey, int $units, string $creature = ''): void
    {
        $info = $this->requireSlot($state, $id, $slot, 'recruit');
        $zone = $state['zones'][$zoneKey] ?? null;
        $home = $zone !== null && $zone['kind'] === 'district' && $zone['city'] === $id;
        $troop = $zone !== null && ($zone['units'][$id] ?? 0) > 0 && $this->tileBonus($state, $id, 'recruit_troops') > 0;
        if ((!$home && !$troop) || $units < 1) throw new UserException(clienttranslate('Choose one of your Districts (or Troops with Local Recruitment) and at least one unit.'));
        $room = min($this->troopLimit($state, $id) - ($zone['units'][$id] ?? 0), $this->supply($state, $id) - $this->unitCount($state, $id));
        if ($units > $room) throw new UserException(clienttranslate('Troop limit or supply exceeded.'));
        if ($creature !== '' && (!$home || !array_key_exists($creature, $state['players'][$id]['creatures'] ?? []) || $state['players'][$id]['creatures'][$creature] !== null || $this->creatureAt($state, $id, $zoneKey) !== null)) {
            throw new UserException(clienttranslate('That Creature cannot join these Units.'));
        }
        // Each unit costs 1 PP; Recruiting Scribe, Local Recruitment and Reinforcement make some or all of them free.
        $this->spend($state, $id, max(0, $units - $this->tileBonus($state, $id, 'recruit_free')));
        $number = $units;
        $state['zones'][$zoneKey]['units'][$id] = ($zone['units'][$id] ?? 0) + $number;
        if ($creature !== '') $state['players'][$id]['creatures'][$creature] = $zoneKey;
        $this->useSlot($state, $id, $info);
        $this->bga->playerStats->inc('units_recruited', $number, (int) $id);
        $this->event('recruit', clienttranslate('${player_name} recruits ${units} unit(s) in ${zone_name}'), [
            'player_id' => $id, 'units' => $number, 'zone' => $zoneKey, 'zone_name' => $this->zoneArg($state, $zoneKey),
        ]);
        $this->battleIfNeeded($state, $id, $zoneKey);
    }

    /**
     * Move action. $path lists the zones entered in order (comma-separated); a zone prefixed with '*' is reached by
     * Teleportation (at most 1 per Move action, from a District with a controlled Pyramid, to an Obelisk zone).
     * Land moves: 1 + Power tiles + the Creature tied to the moving Troop at the start of the action.
     * $creature: the tied Creature moves along with these Units.
     */
    public function doMove(array &$state, string $id, string $slot, string $from, string $path, int $units, bool $creature = false): void
    {
        $info = $this->requireSlot($state, $id, $slot, 'move');
        $steps = $path === '' ? [] : explode(',', $path);
        if (!isset($state['zones'][$from]) || !$steps || $units < 1) throw new UserException(clienttranslate('Choose a valid move.'));
        if (($state['zones'][$from]['units'][$id] ?? 0) < $units) throw new UserException(clienttranslate('Those units are not in the source zone.'));
        $tiles = PrototypeRules::tiles();
        $tied = $this->creatureAt($state, $id, $from);
        $moving = $creature && $tied !== null ? $tiles[$tied]['creature'] : null;
        if ($creature && $moving === null) throw new UserException(clienttranslate('There is no Creature tied to that Troop.'));
        $landMoves = 1 + $this->tileBonus($state, $id, 'move') + ($tied !== null ? $tiles[$tied]['creature']['move'] : 0);
        $noTeleport = $info['golden'] === 'move_noteleport';
        // Phoenix (moving with the Troop) and Surprise Attack lift the Wall adjacency requirement for 1 opposing District.
        $anyWall = !empty($moving['phoenix']) || $info['golden'] === 'move_surprise';
        $costs = [];
        $previous = $from;
        $land = 0;
        $teleports = 0;
        $foreign = 0;
        $zones = [];
        foreach ($steps as $index => $step) {
            $teleport = str_starts_with($step, '*');
            $key = $teleport ? substr($step, 1) : $step;
            $next = $state['zones'][$key] ?? null;
            if ($next === null || $key === $previous) throw new UserException(clienttranslate('The destination is unavailable.'));
            $prev = $state['zones'][$previous];
            if ($teleport) {
                $obeliskStart = $prev['obelisk'] && ($this->tileBonus($state, $id, 'teleport_obelisk') > 0 || !empty($moving['griffin']));
                $pyramidStart = $prev['kind'] === 'district' && $prev['pyramid'] !== null && $this->controller($prev) === $id;
                if ($noTeleport || ++$teleports > 1) throw new UserException(clienttranslate('Only 1 Teleportation per Move action (none with Forced March).'));
                if ((!$pyramidStart && !$obeliskStart) || !$next['obelisk']) throw new UserException(clienttranslate('Teleport from a controlled pyramid to an obelisk.'));
                // Griffin Sphinx teleporting with its Troop makes the Teleportation free.
                $costs[] = !empty($moving['griffin']) ? 0 : max(0, 2 - $this->tileBonus($state, $id, 'teleport_discount'));
            } else {
                if (++$land > $landMoves) throw new UserException(clienttranslate('Your Troop has no Land move left.'));
                $byLand = in_array($key, $prev['neighbors'], true);
                $harbor = !empty($prev['harbor']) && ($next['harbor'] === 'trade' || ($next['harbor'] === 'military' && $next['harborOwner'] === $id));
                if (!$byLand && !$harbor) throw new UserException(clienttranslate('The route is not connected by land or a legal harbor.'));
            }
            if ($next['kind'] === 'district' && $next['city'] !== $id) {
                // Walls: one opposing District per move, entered by land and only if the move started adjacent to it.
                $foreign++;
                if ($foreign > 1 || $teleport || (!$anyWall && !in_array($key, $state['zones'][$from]['neighbors'], true))) throw new UserException(clienttranslate('Enter one opponent district per move, starting the move adjacent to its walls.'));
            }
            // Khnum's Sphinx: an opposing Troop pays 1 PP to enter the zone the Sphinx controls.
            foreach (array_keys($state['players']) as $other) {
                $other = (string) $other;
                $sphinx = $other === $id ? null : $this->creatureAt($state, $other, $key);
                if ($sphinx !== null && !empty($tiles[$sphinx]['creature']['khnum'])) $costs[] = 1;
            }
            $enemy = (bool) array_filter($next['units'], fn($n, $p) => (string) $p !== $id && $n > 0, ARRAY_FILTER_USE_BOTH);
            if ($enemy && $index < count($steps) - 1) throw new UserException(clienttranslate('A battle stops movement.'));
            $zones[] = $key;
            $previous = $key;
        }
        $to = $previous;
        if ($to === $from) throw new UserException(clienttranslate('Choose a valid move.'));
        if (($state['zones'][$to]['units'][$id] ?? 0) + $units > $this->troopLimit($state, $id)) throw new UserException(clienttranslate('A troop cannot exceed your Troop Limit.'));
        if ($moving !== null && $this->creatureAt($state, $id, $to) !== null) throw new UserException(clienttranslate('A Troop can have only 1 Creature.'));
        foreach ($costs as $cost) $this->spend($state, $id, $cost);
        $state['zones'][$from]['units'][$id] -= $units;
        if ($state['zones'][$from]['units'][$id] === 0) unset($state['zones'][$from]['units'][$id]);
        $state['zones'][$to]['units'][$id] = ($state['zones'][$to]['units'][$id] ?? 0) + $units;
        if ($moving !== null) $state['players'][$id]['creatures'][$tied] = $to;
        $this->useSlot($state, $id, $info);
        $this->event('move', $teleports
            ? clienttranslate('${player_name} moves ${units} unit(s) from ${from_name} to ${zone_name}, teleporting')
            : clienttranslate('${player_name} moves ${units} unit(s) from ${from_name} to ${zone_name}'), [
            'player_id' => $id, 'units' => $units, 'from' => $from, 'zone' => $to, 'path' => $zones, 'via' => '',
            'from_name' => $this->zoneArg($state, $from), 'zone_name' => $this->zoneArg($state, $to),
        ]);
        $this->battleIfNeeded($state, $id, $to);
    }

    public function playDayCard(array &$state, string $id, string $card, string $zoneKey): void
    {
        $di = PrototypeRules::di();
        if (!isset($di[$card]) || $di[$card]['phase'] !== 'day') throw new UserException(clienttranslate('Choose a Day Divine Intervention card.'));
        $index = array_search($card, $state['players'][$id]['diHand'], true);
        if ($index === false) throw new UserException(clienttranslate('You do not have that card.'));
        if ($card === 'reinforce') {
            if (!isset($state['zones'][$zoneKey]) || $state['zones'][$zoneKey]['city'] !== $id || $this->controller($state['zones'][$zoneKey]) !== $id || ($state['zones'][$zoneKey]['units'][$id] ?? 0) >= $this->troopLimit($state, $id) || $this->unitCount($state, $id) >= $this->supply($state, $id)) throw new UserException(clienttranslate('Choose a free home district with room for a unit.'));
            $state['zones'][$zoneKey]['units'][$id] = ($state['zones'][$zoneKey]['units'][$id] ?? 0) + 1;
        } else {
            $this->gain($state, $id, 2);
        }
        array_splice($state['players'][$id]['diHand'], $index, 1);
        $state['diDiscard'][] = $card;
        $this->event('dayCard', clienttranslate('${player_name} plays ${card_name}'), ['player_id' => $id, 'card' => $card, 'card_name' => $di[$card]['name'], 'zone' => $zoneKey, 'i18n' => ['card_name']]);
    }

    private function battleIfNeeded(array &$state, string $attacker, string $zoneKey): void
    {
        foreach ($state['zones'][$zoneKey]['units'] as $defender => $units) {
            if ((string) $defender !== $attacker && $units > 0) {
                $defender = (string) $defender;
                // Spy: the opponent of the Spy owner commits first and shows their Battle card.
                $spy = $this->tileBonus($state, $attacker, 'spy') > 0 ? $attacker : ($this->tileBonus($state, $defender, 'spy') > 0 ? $defender : null);
                $state['battle'] = ['zone' => $zoneKey, 'attacker' => $attacker, 'defender' => $defender, 'choices' => [], 'winner' => null, 'loser' => null,
                    'step' => 'cards', 'spy' => $spy, 'wound' => [], 'woundQueue' => []];
                foreach ([$attacker, $defender] as $player) $this->bga->playerStats->inc('battles_fought', 1, (int) $player);
                $this->event('battleStart', clienttranslate('${player_name} attacks ${player_name2} in ${zone_name}'), [
                    'player_id' => $attacker, 'player_id2' => $defender, 'zone' => $zoneKey, 'zone_name' => $this->zoneArg($state, $zoneKey),
                ]);
                // Start of the War Council step, attacker only: Dedication to Battle and Priest of Thoeris.
                $pp = $this->gain($state, $attacker, $this->tileBonus($state, $attacker, 'attack_pp'));
                if ($pp > 0) $this->event('log', clienttranslate('${player_name} gains ${pp} PP from Dedication to Battle'), ['player_id' => $attacker, 'pp' => $pp]);
                $draw = $this->tileBonus($state, $attacker, 'attack_draw');
                if ($draw > 0) {
                    $this->drawDi($state, $attacker, $draw);
                    $this->event('log', clienttranslate('${player_name} draws ${n} DI card(s) from Priest of Thoeris'), ['player_id' => $attacker, 'n' => $draw]);
                }
                if ($spy !== null) $this->event('log', clienttranslate('${player_name}\'s Spy will see the opposing Battle card first'), ['player_id' => $spy]);
                break;
            }
        }
    }

    /** Players who must still make their War Council choice now (the Spy owner waits for their opponent). */
    public function battleChoosers(array $state): array
    {
        $battle = $state['battle'];
        $out = [];
        foreach ([$battle['attacker'], $battle['defender']] as $id) {
            if (isset($battle['choices'][$id])) continue;
            $opponent = $id === $battle['attacker'] ? $battle['defender'] : $battle['attacker'];
            if (($battle['spy'] ?? null) === $id && !isset($battle['choices'][$opponent])) continue;
            $out[] = $id;
        }
        return $out;
    }

    /**
     * Records a War Council choice. $di is a comma-separated list of battle DI cards.
     * Returns '' while waiting for the other player, 'wound' when a Divine Wound choice follows the Confrontation,
     * otherwise the winner's id. After resolution $state['battle'] is null when no aftermath choice remains.
     */
    public function battleChoice(array &$state, string $id, int $played, int $discarded, string $di): string
    {
        $battle = $state['battle'];
        if ($battle === null || $battle['step'] !== 'cards' || !in_array($id, $this->battleChoosers($state), true)) throw new UserException(clienttranslate('It is not your battle choice.'));
        $hand = $state['players'][$id]['battleHand'];
        if ($played === $discarded || !in_array($played, $hand, true) || !in_array($discarded, $hand, true)) throw new UserException(clienttranslate('Choose two different battle cards from your hand.'));
        $cards = $di === '' ? [] : explode(',', $di);
        $available = array_count_values($state['players'][$id]['diHand']);
        $defs = PrototypeRules::di();
        $cost = 0;
        foreach (array_count_values($cards) as $card => $count) {
            $card = (string) $card;
            if (!isset($defs[$card]) || $defs[$card]['phase'] !== 'battle' || ($available[$card] ?? 0) < $count) throw new UserException(clienttranslate('Choose battle DI cards from your hand.'));
            $cost += $this->cost($state, $id, $defs[$card]['cost']) * $count;
        }
        if ($state['players'][$id]['pp'] < $cost) throw new UserException(clienttranslate('Not enough prayer for those DI cards.'));
        $state['battle']['choices'][$id] = ['played' => $played, 'discarded' => $discarded, 'di' => $cards];
        if (count($state['battle']['choices']) < 2) return '';
        $this->confront($state);
        if ($state['battle']['woundQueue']) {
            $state['battle']['step'] = 'wound';
            return 'wound';
        }
        return $this->resolveBattle($state);
    }

    /** Confrontation: both players reveal their Battle card and DI cards and pay for the DI cards. */
    private function confront(array &$state): void
    {
        $battle = &$state['battle'];
        $cardsDef = PrototypeRules::battleCards();
        $diDef = PrototypeRules::di();
        foreach ([$battle['attacker'], $battle['defender']] as $id) {
            $choice = $battle['choices'][$id];
            foreach ($choice['di'] as $di) {
                $this->spend($state, $id, $diDef[$di]['cost']);
                $key = array_search($di, $state['players'][$id]['diHand'], true);
                array_splice($state['players'][$id]['diHand'], $key, 1);
                $state['diDiscard'][] = $di;
            }
            $this->event('log', clienttranslate('${player_name} reveals ${card_name} and ${n} DI card(s)'), [
                'player_id' => $id, 'card_name' => $cardsDef[$choice['played']]['name'], 'n' => count($choice['di']), 'i18n' => ['card_name'],
            ]);
        }
        // Divine Wound, end of Confrontation step: attacker first, then defender, if they hold DI cards they may discard.
        $battle['step'] = 'confront';
        $battle['woundQueue'] = array_values(array_filter([$battle['attacker'], $battle['defender']],
            fn($id) => $this->tileBonus($state, $id, 'divine_wound') > 0 && $this->woundCards($state, $id)));
        unset($battle);
    }

    /** DI cards that can be discarded for an effect (Diversion never can). */
    public function woundCards(array $state, string $id): array
    {
        return array_values(array_filter($state['players'][$id]['diHand'], fn($c) => $c !== 'diversion'));
    }

    /** Divine Wound: discard DI cards ($cards comma-separated, possibly none) for +1 Strength each. */
    public function woundChoice(array &$state, string $id, string $cards): string
    {
        $battle = $state['battle'];
        if ($battle === null || $battle['step'] !== 'wound' || ($battle['woundQueue'][0] ?? null) !== $id) throw new UserException(clienttranslate('It is not your Divine Wound choice.'));
        $list = $cards === '' ? [] : explode(',', $cards);
        $hand = $state['players'][$id]['diHand'];
        foreach ($list as $card) {
            $key = array_search($card, $hand, true);
            if ($card === 'diversion' || $key === false) throw new UserException(clienttranslate('Choose DI cards from your hand (not Diversion).'));
            array_splice($hand, $key, 1);
            $state['diDiscard'][] = $card;
        }
        $state['players'][$id]['diHand'] = $hand;
        $state['battle']['wound'][$id] = count($list);
        array_shift($state['battle']['woundQueue']);
        if ($list) $this->event('log', clienttranslate('${player_name} discards ${n} DI card(s) for Divine Wound: +${n} Strength'), ['player_id' => $id, 'n' => count($list)]);
        if ($state['battle']['woundQueue']) return 'wound';
        return $this->resolveBattle($state);
    }

    /** Battle bonuses of the Creature tied to a player's Troop in the battle zone (cancelled by an opposing Deep Desert Snake). */
    private function battleCreature(array $state, string $id, string $opponent): array
    {
        $zone = $state['battle']['zone'];
        if (!empty($this->creatureStats($state, $opponent, $zone)['snake'])) return [];
        return $this->creatureStats($state, $id, $zone);
    }

    private function resolveBattle(array &$state): string
    {
        $battle = &$state['battle'];
        $zone = &$state['zones'][$battle['zone']];
        $cardsDef = PrototypeRules::battleCards();
        $totals = [];
        foreach ([$battle['attacker'], $battle['defender']] as $id) {
            $choice = $battle['choices'][$id];
            $card = $cardsDef[$choice['played']];
            $attacking = $id === $battle['attacker'];
            $opponent = $attacking ? $battle['defender'] : $battle['attacker'];
            $creature = $this->battleCreature($state, $id, $opponent);
            $di = array_count_values($choice['di']);
            // Power tile bonuses: any battle, plus attack_* or defend_* depending on the side.
            $side = $attacking ? 'attack_' : 'defend_';
            $bonus = fn(string $stat) => $this->tileBonus($state, $id, $stat) + $this->tileBonus($state, $id, $side . $stat) + ($creature[$stat] ?? 0);
            $totals[$id] = [
                'strength' => ($zone['units'][$id] ?? 0) + $card['strength'] + $bonus('strength') + (int) ($zone['city'] === $id) + ($di['fury'] ?? 0) * 2 + ($battle['wound'][$id] ?? 0),
                'damage' => $card['damage'] + $bonus('damage'),
                'pierce' => $card['pierce'] + $bonus('pierce') + ($di['wound'] ?? 0),
                'shield' => $card['shield'] + $bonus('shield') + ($di['wall'] ?? 0) * 2,
            ];
            $player = &$state['players'][$id];
            $player['battleHand'] = array_values(array_diff($player['battleHand'], [$choice['played'], $choice['discarded']]));
            $player['battleDiscard'][] = $choice['played'];
            $player['battleDiscard'][] = $choice['discarded'];
            $player['battlePlayed'][] = $choice['played'];
            if (!$player['battleHand']) {
                $player['battleHand'] = $player['battleDeck'] ?? PrototypeRules::BASE_BATTLE_CARDS;
                $player['battleDiscard'] = [];
                $player['battlePlayed'] = [];
            }
            unset($player);
        }

        $a = $battle['attacker'];
        $d = $battle['defender'];
        $battle['winner'] = $totals[$a]['strength'] > $totals[$d]['strength'] ? $a : $d;
        $battle['loser'] = $battle['winner'] === $a ? $d : $a;
        $losses = [];
        foreach ([[$a, $d], [$d, $a]] as [$victim, $opponent]) {
            $loss = min($zone['units'][$victim] ?? 0, $totals[$opponent]['pierce'] + max(0, $totals[$opponent]['damage'] - $totals[$victim]['shield']));
            $losses[$victim] = $loss;
            $zone['units'][$victim] = ($zone['units'][$victim] ?? 0) - $loss;
            if ($zone['units'][$victim] === 0) unset($zone['units'][$victim]);
        }
        $winner = $battle['winner'];
        $loser = $battle['loser'];
        $this->bga->playerStats->inc('battles_won', 1, (int) $winner);
        $this->event('battleResolved', clienttranslate('${player_name} wins the battle in ${zone_name}: ${card_w} (strength ${strength_w}) against ${player_name2}\'s ${card_l} (strength ${strength_l})'), [
            'player_id' => $winner, 'player_id2' => $loser, 'zone' => $battle['zone'], 'zone_name' => $this->zoneArg($state, $battle['zone']),
            'card_w' => $cardsDef[$battle['choices'][$winner]['played']]['name'], 'card_l' => $cardsDef[$battle['choices'][$loser]['played']]['name'],
            'strength_w' => $totals[$winner]['strength'], 'strength_l' => $totals[$loser]['strength'], 'i18n' => ['card_w', 'card_l'],
        ]);
        $this->event('log', clienttranslate('Casualties: ${player_name} loses ${loss} unit(s), ${player_name2} loses ${loss2} unit(s)'), [
            'player_id' => $winner, 'player_id2' => $loser, 'loss' => $losses[$winner], 'loss2' => $losses[$loser],
        ]);
        // End of Casualties step: Honor in Battle (PP per own unit lost) and Divine Hunger (PP per opposing unit removed).
        foreach ([[$a, $d], [$d, $a]] as [$id, $opponent]) {
            $pp = $this->gain($state, $id, $losses[$id] * $this->tileBonus($state, $id, 'pp_per_loss') + $losses[$opponent] * $this->tileBonus($state, $id, 'pp_per_kill'));
            if ($pp > 0) $this->event('log', clienttranslate('${player_name} gains ${pp} PP from casualties'), ['player_id' => $id, 'pp' => $pp]);
        }
        // Rewards: an attacker who wins and keeps a troop gains 1 Battle FP. A winning defender
        // gains 1 Veteran, and every player who gained no FP gains 1 Veteran (so 2 for a winning defender).
        $battleFp = [$a => 0, $d => 0];
        if ($winner === $a && ($zone['units'][$a] ?? 0) > 0) $battleFp[$a]++;
        if ($winner === $d && ($zone['units'][$d] ?? 0) > 0) $battleFp[$d] += $this->tileBonus($state, $d, 'defend_win_fp');
        if ($losses[$loser] >= 2) $battleFp[$winner] += $this->tileBonus($state, $winner, 'kill2_win_fp');
        foreach ($battleFp as $id => $fp) {
            if ($fp < 1) continue;
            $state['players'][$id]['permanent'] += $fp;
            $this->bga->playerStats->inc('battle_fp', $fp, (int) $id);
            $this->event('log', clienttranslate('${player_name} gains ${fp} Battle FP'), ['player_id' => (string) $id, 'fp' => $fp]);
        }
        if ($battleFp[$a] === 0) $state['players'][$a]['veterans']++;
        $state['players'][$d]['veterans'] += $winner === $d ? 2 : 1;
        $battle['totals'] = $totals;
        unset($battle, $zone);
        $this->setAftermathStep($state, 'loser');
        return $winner;
    }

    /** Moves to the given aftermath step, skipping players without a troop. Returns true when the battle is over. */
    private function setAftermathStep(array &$state, string $step): bool
    {
        $battle = $state['battle'];
        $units = $state['zones'][$battle['zone']]['units'];
        if ($step === 'loser' && ($units[$battle['loser']] ?? 0) === 0) $step = 'winner';
        if ($step === 'winner' && ($units[$battle['winner']] ?? 0) === 0) {
            $state['battle'] = null;
            return true;
        }
        $state['battle']['step'] = $step;
        return false;
    }

    /** The loser decides in the loser step; the winner picks the retreat zone and makes the winner's choice. */
    public function aftermathActor(array $state): string
    {
        $battle = $state['battle'];
        return $battle['step'] === 'loser' ? $battle['loser'] : $battle['winner'];
    }

    /** Adjacent free zones the losing troop may retreat to, never another player's city district. */
    public function retreatOptions(array $state): array
    {
        $battle = $state['battle'];
        $loser = $battle['loser'];
        $units = $state['zones'][$battle['zone']]['units'][$loser] ?? 0;
        $options = [];
        foreach ($state['zones'][$battle['zone']]['neighbors'] as $key) {
            $target = $state['zones'][$key] ?? null;
            if ($target === null || ($target['kind'] === 'district' && $target['city'] !== $loser)) continue;
            foreach ($target['units'] as $other => $count) if ((string) $other !== $loser && $count > 0) continue 2;
            if (($target['units'][$loser] ?? 0) + $units > $this->troopLimit($state, $loser)) continue;
            $options[] = $key;
        }
        return $options;
    }

    public function battleAftermath(array &$state, string $id, string $choice, string $retreatTo): bool
    {
        $battle = $state['battle'];
        if ($battle === null || !in_array($battle['step'], ['loser', 'retreat', 'winner'], true)) throw new UserException(clienttranslate('There is no battle aftermath.'));
        if ($id !== $this->aftermathActor($state)) throw new UserException(clienttranslate('It is not your aftermath choice.'));
        $zoneKey = $battle['zone'];
        if ($battle['step'] === 'retreat') {
            if ($choice !== 'retreat' || !in_array($retreatTo, $this->retreatOptions($state), true)) throw new UserException(clienttranslate('Choose an adjacent free zone for the retreating troop.'));
            $loser = $battle['loser'];
            $units = $state['zones'][$zoneKey]['units'][$loser];
            $state['zones'][$retreatTo]['units'][$loser] = ($state['zones'][$retreatTo]['units'][$loser] ?? 0) + $units;
            unset($state['zones'][$zoneKey]['units'][$loser]);
            $creature = $this->creatureAt($state, $loser, $zoneKey);
            if ($creature !== null && $this->creatureAt($state, $loser, $retreatTo) === null) $state['players'][$loser]['creatures'][$creature] = $retreatTo;
            $this->event('move', clienttranslate('${player_name} sends ${player_name2}\'s troop retreating to ${zone_name}'), [
                'player_id' => $id, 'player_id2' => $loser, 'mover' => $loser, 'units' => $units, 'from' => $zoneKey, 'zone' => $retreatTo,
                'zone_name' => $this->zoneArg($state, $retreatTo),
            ]);
            return $this->setAftermathStep($state, 'winner');
        }
        if ($choice === 'recall') {
            $units = $state['zones'][$zoneKey]['units'][$id] ?? 0;
            $pp = $this->gain($state, $id, max(0, $units - 1));
            unset($state['zones'][$zoneKey]['units'][$id]);
            $this->event('recall', clienttranslate('${player_name} recalls ${units} unit(s) from ${zone_name} and gains ${pp} PP'), [
                'player_id' => $id, 'units' => $units, 'pp' => $pp, 'zone' => $zoneKey, 'zone_name' => $this->zoneArg($state, $zoneKey),
            ]);
        } elseif ($choice === 'retreat' && $battle['step'] === 'loser') {
            if (($state['zones'][$zoneKey]['units'][$battle['winner']] ?? 0) === 0) throw new UserException(clienttranslate('The winner has no troop left, so you must recall.'));
            if (!$this->retreatOptions($state)) throw new UserException(clienttranslate('No adjacent free zone is available, so you must recall.'));
            $state['battle']['step'] = 'retreat';
            $this->event('log', clienttranslate('${player_name} retreats; ${player_name2} chooses where'), ['player_id' => $id, 'player_id2' => $battle['winner']]);
            return false;
        } elseif ($choice === 'stay' && $battle['step'] === 'winner') {
            $this->event('log', clienttranslate('${player_name} keeps their troop in ${zone_name}'), ['player_id' => $id, 'zone_name' => $this->zoneArg($state, $zoneKey)]);
        } else {
            throw new UserException(clienttranslate('Invalid aftermath choice.'));
        }
        if ($battle['step'] === 'loser') return $this->setAftermathStep($state, 'winner');
        $state['battle'] = null;
        return true;
    }

    public function resolveNight(array &$state): void
    {
        $this->event('log', clienttranslate('Night ${round}: offerings, temples and prayers are resolved'), ['round' => $state['round']]);
        foreach ($state['order'] as $id) {
            $choice = $state['night']['choices'][$id];
            if ($choice['offer'] && $this->controller($state['zones']['sanctuary']) === $id && ($state['zones']['sanctuary']['units'][$id] ?? 0) >= 2) {
                $state['zones']['sanctuary']['units'][$id] -= 2;
                if ($state['zones']['sanctuary']['units'][$id] === 0) unset($state['zones']['sanctuary']['units'][$id]);
                $state['players'][$id]['permanent']++;
                $this->event('log', clienttranslate('${player_name} sacrifices 2 units at the Sanctuary of All Gods and gains 1 Divine FP'), ['player_id' => $id]);
            }
            if ($choice['delta'] && $this->controller($state['zones']['delta']) === $id && ($state['zones']['delta']['units'][$id] ?? 0) >= 1) {
                $state['zones']['delta']['units'][$id]--;
                if ($state['zones']['delta']['units'][$id] === 0) unset($state['zones']['delta']['units'][$id]);
                $pp = $this->gain($state, $id, 5);
                $this->event('log', clienttranslate('${player_name} sacrifices 1 unit at the Delta Temple and gains ${pp} PP'), ['player_id' => $id, 'pp' => $pp]);
            }
        }
        foreach ($state['order'] as $id) {
            $temples = 0;
            foreach ($state['zones'] as $zone) if ($zone['kind'] === 'temple' && $this->controller($zone) === $id) {
                $temples++;
                $this->gain($state, $id, $zone['prayer']);
            }
            if ($temples >= 2) {
                $state['players'][$id]['permanent']++;
                $this->event('log', clienttranslate('${player_name} controls ${temples} Temples and gains 1 Divine FP'), ['player_id' => $id, 'temples' => $temples]);
            }
            $this->gain($state, $id, 2 + $this->tileBonus($state, $id, 'night_pp'));
        }
        foreach ($state['order'] as $id) {
            $choice = $state['night']['choices'][$id];
            $state['players'][$id]['veterans'] -= $choice['pray'];
            $this->gain($state, $id, $choice['pray']);
            $draw = min($choice['draw'], $state['players'][$id]['veterans']);
            $state['players'][$id]['veterans'] -= $draw;
            // Divine Boon and The Mummy draw 1 more; Vision draws 4 more, discarded again in the NightPowers state.
            $this->drawDi($state, $id, 1 + $draw + $this->tileBonus($state, $id, 'night_di') + $this->tileBonus($state, $id, 'vision'));
            $recruit = min($choice['recruit'], $state['players'][$id]['veterans'], $this->supply($state, $id) - $this->unitCount($state, $id));
            $district = $choice['district'];
            if ($recruit > 0 && isset($state['zones'][$district]) && $state['zones'][$district]['city'] === $id && $this->controller($state['zones'][$district]) === $id) {
                $recruit = min($recruit, $this->troopLimit($state, $id) - ($state['zones'][$district]['units'][$id] ?? 0));
                $state['zones'][$district]['units'][$id] = ($state['zones'][$district]['units'][$id] ?? 0) + $recruit;
                if ($recruit > 0) $this->event('recruit', clienttranslate('${player_name} recruits ${units} unit(s) in ${zone_name}'), [
                    'player_id' => $id, 'units' => $recruit, 'zone' => $district, 'zone_name' => $this->zoneArg($state, $district),
                ]);
            }
            $state['players'][$id]['veterans'] = 0;
            // Awakening: Action tokens (colored, silver and gold) return to their owner.
            $state['players'][$id]['slots'] = [];
            $state['players'][$id]['silverSlots'] = [];
            $state['players'][$id]['goldUsed'] = false;
            $state['players'][$id]['bought'] = [];
        }
        $state['night'] = null;
        // Night Power choices, in turn order: Vision (discard 4 DI cards), then Hand of God (free Build) at Awakening.
        $queue = [];
        foreach ($state['order'] as $id) {
            $discard = min($this->tileBonus($state, $id, 'vision'), count($this->woundCards($state, $id)));
            if ($discard > 0) $queue[] = ['player' => $id, 'task' => 'vision', 'count' => $discard];
        }
        foreach ($state['order'] as $id) {
            if ($this->tileBonus($state, $id, 'hand_of_god') > 0 && $this->handOfGodOptions($state, $id)) $queue[] = ['player' => $id, 'task' => 'handOfGod', 'count' => 1];
        }
        $state['nightPowers'] = $queue ?: null;
    }

    /** Destiny: fewest FP chooses a turn-order space first; ties go to the player who was leftmost. */
    public function startDestiny(array &$state): void
    {
        $old = array_flip($state['order']);
        $queue = $state['order'];
        usort($queue, fn($a, $b) => $this->score($state, $a) <=> $this->score($state, $b) ?: $old[$a] <=> $old[$b]);
        $state['nightPowers'] = null;
        $state['destiny'] = ['queue' => $queue, 'index' => 0, 'positions' => array_fill(0, count($queue), null)];
    }

    /** Hand of God targets: own Pyramids below Level 4, and empty own Districts for a new Level 1 Pyramid (zone => colors). */
    public function handOfGodOptions(array $state, string $id): array
    {
        $owned = [];
        foreach ($state['zones'] as $zone) if (($zone['pyramid']['owner'] ?? null) === $id) $owned[] = $zone['pyramid']['color'];
        $free = array_values(array_diff($state['colors'], $owned));
        $options = [];
        foreach ($state['zones'] as $key => $zone) {
            if (($zone['pyramid']['owner'] ?? null) === $id && $zone['pyramid']['level'] < 4) $options[$key] = [$zone['pyramid']['color']];
            elseif ($zone['kind'] === 'district' && $zone['city'] === $id && $zone['pyramid'] === null && $free) $options[$key] = $free;
        }
        return $options;
    }

    /**
     * Resolves the first Night Power choice. Vision: $choice lists the DI cards to discard (comma-separated).
     * Hand of God: $choice is '<zone>:<color>', or '' to skip. Returns true when no choice remains.
     */
    public function nightPower(array &$state, string $id, string $choice): bool
    {
        $task = $state['nightPowers'][0] ?? null;
        if ($task === null || $task['player'] !== $id) throw new UserException(clienttranslate('It is not your Night Power choice.'));
        if ($task['task'] === 'vision') {
            $cards = $choice === '' ? [] : explode(',', $choice);
            if (count($cards) !== $task['count']) throw new UserException(clienttranslate('Discard exactly the required number of DI cards.'));
            $hand = $state['players'][$id]['diHand'];
            foreach ($cards as $card) {
                $key = array_search($card, $hand, true);
                if ($card === 'diversion' || $key === false) throw new UserException(clienttranslate('Choose DI cards from your hand (not Diversion).'));
                array_splice($hand, $key, 1);
                $state['diDiscard'][] = $card;
            }
            $state['players'][$id]['diHand'] = $hand;
            $this->event('log', clienttranslate('${player_name} discards ${n} DI cards (Vision)'), ['player_id' => $id, 'n' => count($cards)]);
        } elseif ($choice !== '') {
            [$zoneKey, $color] = array_pad(explode(':', $choice), 2, '');
            $options = $this->handOfGodOptions($state, $id);
            if (!in_array($color, $options[$zoneKey] ?? [], true)) throw new UserException(clienttranslate('Choose one of your Pyramids, or an empty District for a new one.'));
            $level = ($state['zones'][$zoneKey]['pyramid']['level'] ?? 0) + 1;
            $state['zones'][$zoneKey]['pyramid'] = ['owner' => $id, 'color' => $color, 'level' => $level];
            $this->bga->playerStats->inc('pyramid_levels', 1, (int) $id);
            $this->event('raise', clienttranslate('${player_name} raises a ${color_name} pyramid to level ${level} in ${zone_name} for free (Hand of God)'), [
                'player_id' => $id, 'color_name' => PrototypeRules::colorNames()[$color], 'level' => $level, 'cost' => 0,
                'zone_name' => $this->zoneArg($state, $zoneKey), 'zone' => $zoneKey, 'i18n' => ['color_name'],
            ]);
        }
        array_shift($state['nightPowers']);
        if ($state['nightPowers']) return false;
        $state['nightPowers'] = null;
        return true;
    }

    /** Places the chooser's order marker. Returns true once every marker is placed and the new Day can start. */
    public function chooseDestiny(array &$state, string $id, int $position): bool
    {
        $destiny = $state['destiny'] ?? null;
        if ($destiny === null || $destiny['queue'][$destiny['index']] !== $id) throw new UserException(clienttranslate('It is not your turn order choice.'));
        if (!array_key_exists($position, $destiny['positions']) || $destiny['positions'][$position] !== null) throw new UserException(clienttranslate('Choose an empty turn order space.'));
        $destiny['positions'][$position] = $id;
        $destiny['index']++;
        $this->event('log', clienttranslate('${player_name} takes turn order space ${position}'), ['player_id' => $id, 'position' => $position + 1]);
        if ($destiny['index'] === count($destiny['queue']) - 1) {
            // The last player takes the only remaining space.
            $last = $destiny['queue'][$destiny['index']];
            $free = array_search(null, $destiny['positions'], true);
            $destiny['positions'][$free] = $last;
            $destiny['index']++;
            $this->event('log', clienttranslate('${player_name} takes turn order space ${position}'), ['player_id' => $last, 'position' => $free + 1]);
        }
        if ($destiny['index'] < count($destiny['queue'])) {
            $state['destiny'] = $destiny;
            return false;
        }
        $state['order'] = array_values($destiny['positions']);
        $state['destiny'] = null;
        $state['round']++;
        $state['turnIndex'] = 0;
        $this->bga->tableStats->inc('rounds', 1);
        $this->event('log', clienttranslate('Day ${round} begins'), ['round' => $state['round']]);
        return true;
    }

    public function getGameProgression(): int
    {
        $state = $this->loadState();
        $best = 0;
        foreach (array_keys($state['players']) as $id) $best = max($best, $this->score($state, (string) $id));
        return min(99, (int) floor(100 * $best / 9));
    }

    public function upgradeTableDb($from_version): void {}
}
