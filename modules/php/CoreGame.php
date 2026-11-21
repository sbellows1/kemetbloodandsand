<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands;

use Bga\GameFramework\UserException;
use Bga\Games\kemetbloodandsands\States\PlayerTurn;

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
        $activeColors = array_slice(PrototypeRules::COLORS, 0, $count === 2 ? 2 : ($count === 5 ? 4 : 3));
        $zones = PrototypeRules::board($order);
        $playerState = [];
        foreach ($order as $index => $id) {
            $zones["c{$id}_1"]['units'][$id] = 5;
            $zones["c{$id}_2"]['units'][$id] = 5;
            $zones["c{$id}_1"]['pyramid'] = ['owner' => $id, 'color' => $activeColors[$index % count($activeColors)], 'level' => 2];
            $zones["c{$id}_2"]['pyramid'] = ['owner' => $id, 'color' => $activeColors[($index + 1) % count($activeColors)], 'level' => 1];
            $playerState[$id] = [
                'pp' => 7, 'permanent' => 0, 'veterans' => 0,
                'slots' => [], 'bought' => [], 'tiles' => [],
                'battleHand' => array_keys(PrototypeRules::battleCards()),
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
            'market' => array_fill_keys(array_keys(PrototypeRules::tiles()), true),
            'diDeck' => $deck, 'diDiscard' => [], 'battle' => null, 'night' => null, 'destiny' => null,
        ];
        foreach ($order as $id) {
            $this->drawDi($state, $id, 2);
            $starterColor = $state['zones']["c{$id}_1"]['pyramid']['color'];
            foreach (PrototypeRules::tiles() as $key => $tile) {
                if ($tile['color'] === $starterColor && $tile['level'] === 1 && !empty($state['market'][$key])) {
                    $state['market'][$key] = false;
                    $state['players'][$id]['tiles'][] = $key;
                    break;
                }
            }
        }
        $this->saveState($state);
        $this->gamestate->changeActivePlayer((int) $order[0]);
        return PlayerTurn::class;
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
            if ($view['battle']['winner'] === null) {
                $view['battle']['choices'] = array_fill_keys(array_keys($view['battle']['choices']), true);
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
    public function notifyState(array $state): void
    {
        $this->saveState($state);
        $events = $this->events;
        $this->events = [];
        foreach ($events as [$type, $message, $args]) $this->bga->notify->all($type, $message, $args);
        $this->bga->notify->all('kemetState', '', ['kemet' => $this->viewState($state)]);
        foreach ($state['players'] as $id => $player) {
            $this->bga->notify->player((int) $id, 'hand', '', ['diHand' => $player['diHand'], 'battleHand' => $player['battleHand']]);
        }
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

    public function requireSlot(array $state, string $id, string $slot, string $action): void
    {
        if (!isset(PrototypeRules::SLOTS[$slot]) || PrototypeRules::SLOTS[$slot]['action'] !== $action) throw new UserException(clienttranslate('Choose an action space of the right kind.'));
        $used = $state['players'][$id]['slots'];
        if (in_array($slot, $used, true) || count($used) >= 5) throw new UserException(clienttranslate('That action space is unavailable.'));
        $tiers = array_unique(array_map(fn($s) => PrototypeRules::SLOTS[$s]['tier'], array_merge($used, [$slot])));
        if (5 - count($used) - 1 < 3 - count($tiers)) throw new UserException(clienttranslate('Leave enough tokens to cover all three action tiers.'));
    }

    /** True when the slot is free and placing a token there keeps the Equilibrium rule satisfiable. */
    public function slotAllowed(array $state, string $id, string $slot): bool
    {
        try {
            $this->requireSlot($state, $id, $slot, PrototypeRules::SLOTS[$slot]['action']);
            return true;
        } catch (UserException) {
            return false;
        }
    }

    public function spend(array &$state, string $id, int $cost): void
    {
        if ($cost < 0 || $state['players'][$id]['pp'] < $cost) throw new UserException(clienttranslate('Not enough prayer points.'));
        $state['players'][$id]['pp'] -= $cost;
    }
    /** Gains PP up to the cap of 11 and returns the amount actually gained. */
    public function gain(array &$state, string $id, int $amount): int
    {
        $before = $state['players'][$id]['pp'];
        $state['players'][$id]['pp'] = min(11, $before + max(0, $amount));
        return $state['players'][$id]['pp'] - $before;
    }
    public function unitCount(array $state, string $id): int
    {
        $total = 0;
        foreach ($state['zones'] as $zone) $total += $zone['units'][$id] ?? 0;
        return $total;
    }
    public function tileBonus(array $state, string $id, string $effect): int
    {
        $n = 0;
        $tiles = PrototypeRules::tiles();
        foreach ($state['players'][$id]['tiles'] as $key) if ($tiles[$key]['effect'] === $effect) $n++;
        return $n;
    }

    public function doPray(array &$state, string $id, string $slot): void
    {
        $this->requireSlot($state, $id, $slot, 'pray');
        $pp = $this->gain($state, $id, 2 + $this->tileBonus($state, $id, 'pray'));
        $state['players'][$id]['slots'][] = $slot;
        $this->event('pray', clienttranslate('${player_name} prays and gains ${pp} PP'), ['player_id' => $id, 'pp' => $pp]);
    }

    public function doRaise(array &$state, string $id, string $slot, string $zoneKey, string $color, int $level): void
    {
        $this->requireSlot($state, $id, $slot, 'raise');
        if (!isset($state['zones'][$zoneKey]) || $state['zones'][$zoneKey]['city'] !== $id || !in_array($color, $state['colors'], true) || $level < 1 || $level > 4) throw new UserException(clienttranslate('Invalid pyramid choice.'));
        $old = $state['zones'][$zoneKey]['pyramid'];
        if ($old !== null && ($old['owner'] !== $id || $old['color'] !== $color || $level <= $old['level'])) throw new UserException(clienttranslate('Choose your pyramid and a higher level.'));
        if ($old === null) {
            foreach ($state['zones'] as $other) if (($other['pyramid']['owner'] ?? null) === $id && ($other['pyramid']['color'] ?? null) === $color) throw new UserException(clienttranslate('You already have a pyramid of that color.'));
        }
        $cost = 0;
        for ($i = ($old['level'] ?? 0) + 1; $i <= $level; $i++) $cost += $i;
        $this->spend($state, $id, $cost);
        $state['zones'][$zoneKey]['pyramid'] = ['owner' => $id, 'color' => $color, 'level' => $level];
        $state['players'][$id]['slots'][] = $slot;
        $this->bga->playerStats->inc('pyramid_levels', $level - ($old['level'] ?? 0), (int) $id);
        $this->event('raise', clienttranslate('${player_name} raises a ${color_name} pyramid to level ${level} in ${zone_name} for ${cost} PP'), [
            'player_id' => $id, 'color_name' => PrototypeRules::colorNames()[$color], 'level' => $level, 'cost' => $cost,
            'zone_name' => $this->zoneArg($state, $zoneKey), 'zone' => $zoneKey, 'i18n' => ['color_name'],
        ]);
    }

    public function doBuy(array &$state, string $id, string $slot, string $tile): void
    {
        $this->requireSlot($state, $id, $slot, 'buy');
        $tiles = PrototypeRules::tiles();
        if (!isset($tiles[$tile]) || empty($state['market'][$tile])) throw new UserException(clienttranslate('That power tile is unavailable.'));
        $def = $tiles[$tile];
        if (in_array($def['color'], $state['players'][$id]['bought'], true)) throw new UserException(clienttranslate('You already bought a tile of that color today.'));
        $ownPyramid = false;
        $foreignPyramid = false;
        foreach ($state['zones'] as $zone) {
            if ($this->controller($zone) !== $id || ($zone['pyramid']['color'] ?? null) !== $def['color'] || ($zone['pyramid']['level'] ?? 0) < $def['level']) continue;
            if ($zone['city'] === $id) $ownPyramid = true;
            else $foreignPyramid = true;
        }
        if (!$ownPyramid && !$foreignPyramid) throw new UserException(clienttranslate('Control a pyramid of this color and level first.'));
        if ($ownPyramid && $slot !== 'buy_' . $def['color'] && !$foreignPyramid) throw new UserException(clienttranslate('Use the matching buy action space.'));
        if ($foreignPyramid) {
            $slotColor = substr($slot, 4);
            $hasToken = false;
            foreach ($state['zones'] as $zone) if ($zone['city'] === $id && ($zone['pyramid']['color'] ?? null) === $slotColor) $hasToken = true;
            if (!$hasToken) throw new UserException(clienttranslate('Use a buy space with one of your pyramid tokens.'));
        }
        $this->spend($state, $id, $def['level']);
        $state['market'][$tile] = false;
        $state['players'][$id]['tiles'][] = $tile;
        $state['players'][$id]['bought'][] = $def['color'];
        $state['players'][$id]['slots'][] = $slot;
        if ($def['effect'] === 'point') $state['players'][$id]['permanent']++;
        if ($def['effect'] === 'draw') $this->drawDi($state, $id, 1);
        $this->bga->playerStats->inc('tiles_bought', 1, (int) $id);
        $this->event('buy', clienttranslate('${player_name} buys ${tile_name} for ${cost} PP'), [
            'player_id' => $id, 'tile' => $tile, 'tile_name' => $def['name'], 'cost' => $def['level'], 'i18n' => ['tile_name'],
        ]);
    }

    public function doRecruit(array &$state, string $id, string $slot, string $zoneKey, int $units): void
    {
        $this->requireSlot($state, $id, $slot, 'recruit');
        if (!isset($state['zones'][$zoneKey]) || $state['zones'][$zoneKey]['city'] !== $id || $units < 1) throw new UserException(clienttranslate('Choose your city and at least one unit.'));
        $bonus = $this->tileBonus($state, $id, 'recruit');
        $number = min($units + $bonus, 5 - ($state['zones'][$zoneKey]['units'][$id] ?? 0), 12 - $this->unitCount($state, $id));
        if ($number < $units) throw new UserException(clienttranslate('Troop limit or supply exceeded.'));
        $this->spend($state, $id, $units);
        $state['zones'][$zoneKey]['units'][$id] = ($state['zones'][$zoneKey]['units'][$id] ?? 0) + $number;
        $state['players'][$id]['slots'][] = $slot;
        $this->bga->playerStats->inc('units_recruited', $number, (int) $id);
        $this->event('recruit', clienttranslate('${player_name} recruits ${units} unit(s) in ${zone_name}'), [
            'player_id' => $id, 'units' => $number, 'zone' => $zoneKey, 'zone_name' => $this->zoneArg($state, $zoneKey),
        ]);
        $this->battleIfNeeded($state, $id, $zoneKey);
    }

    public function doMove(array &$state, string $id, string $slot, string $from, string $to, int $units, bool $teleport, string $via): void
    {
        $this->requireSlot($state, $id, $slot, 'move');
        if (!isset($state['zones'][$from], $state['zones'][$to]) || $from === $to || $units < 1) throw new UserException(clienttranslate('Choose a valid move.'));
        $source = &$state['zones'][$from];
        $target = &$state['zones'][$to];
        if (($source['units'][$id] ?? 0) < $units) throw new UserException(clienttranslate('Those units are not in the source zone.'));
        if (($target['units'][$id] ?? 0) + $units > 5) throw new UserException(clienttranslate('A troop cannot exceed five units.'));
        $route = $via === '' ? [$to] : [$via, $to];
        $previous = $from;
        if ($teleport) {
            if ($this->controller($source) !== $id || $source['pyramid'] === null || !isset($state['zones'][$route[0]]) || !$state['zones'][$route[0]]['obelisk']) throw new UserException(clienttranslate('Teleport from a controlled pyramid to an obelisk.'));
            $this->spend($state, $id, 2);
            $previous = array_shift($route);
            if ($previous !== $to) {
                foreach ($state['zones'][$previous]['units'] as $other => $count) if ((string) $other !== $id && $count > 0) throw new UserException(clienttranslate('A battle stops movement at the obelisk.'));
            }
        } elseif (count($route) > 1 && $this->tileBonus($state, $id, 'move') < 1) {
            throw new UserException(clienttranslate('You need extra movement to cross two land routes.'));
        }
        $foreignDistricts = 0;
        foreach ($route as $index => $step) {
            if (!isset($state['zones'][$step])) throw new UserException(clienttranslate('The destination is unavailable.'));
            $nextZone = $state['zones'][$step];
            $land = in_array($step, $state['zones'][$previous]['neighbors'], true);
            $harbor = !empty($state['zones'][$previous]['harbor']) && ($nextZone['harbor'] === 'trade' || ($nextZone['harbor'] === 'military' && $nextZone['harborOwner'] === $id));
            if (!$land && !$harbor) throw new UserException(clienttranslate('The route is not connected by land or a legal harbor.'));
            if ($nextZone['kind'] === 'district' && $nextZone['city'] !== $id) {
                // Walls: the troop must start the Move action adjacent to that district, and may enter only one opponent district per move.
                $foreignDistricts++;
                if ($foreignDistricts > 1 || $teleport || $index !== 0 || !in_array($step, $state['zones'][$from]['neighbors'], true)) throw new UserException(clienttranslate('Enter one opponent district per move, starting the move adjacent to its walls.'));
            }
            if ($index < count($route) - 1) foreach ($nextZone['units'] as $other => $count) if ((string) $other !== $id && $count > 0) throw new UserException(clienttranslate('A battle stops movement.'));
            $previous = $step;
        }
        $source['units'][$id] -= $units;
        if ($source['units'][$id] === 0) unset($source['units'][$id]);
        $target['units'][$id] = ($target['units'][$id] ?? 0) + $units;
        unset($source, $target);
        $state['players'][$id]['slots'][] = $slot;
        $this->event('move', $teleport
            ? clienttranslate('${player_name} teleports ${units} unit(s) from ${from_name} to ${zone_name}')
            : clienttranslate('${player_name} moves ${units} unit(s) from ${from_name} to ${zone_name}'), [
            'player_id' => $id, 'units' => $units, 'from' => $from, 'zone' => $to, 'via' => $via,
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
            if (!isset($state['zones'][$zoneKey]) || $state['zones'][$zoneKey]['city'] !== $id || $this->controller($state['zones'][$zoneKey]) !== $id || ($state['zones'][$zoneKey]['units'][$id] ?? 0) >= 5 || $this->unitCount($state, $id) >= 12) throw new UserException(clienttranslate('Choose a free home district with room for a unit.'));
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
                $state['battle'] = ['zone' => $zoneKey, 'attacker' => $attacker, 'defender' => (string) $defender, 'choices' => [], 'winner' => null, 'loser' => null, 'step' => 'cards'];
                foreach ([$attacker, (string) $defender] as $player) $this->bga->playerStats->inc('battles_fought', 1, (int) $player);
                $this->event('battleStart', clienttranslate('${player_name} attacks ${player_name2} in ${zone_name}'), [
                    'player_id' => $attacker, 'player_id2' => (string) $defender, 'zone' => $zoneKey, 'zone_name' => $this->zoneArg($state, $zoneKey),
                ]);
                break;
            }
        }
    }

    /**
     * Records a War Council choice. $di is a comma-separated list of battle DI cards.
     * Returns '' while waiting for the other player, otherwise the winner's id.
     * After resolution $state['battle'] is null when no aftermath choice remains.
     */
    public function battleChoice(array &$state, string $id, int $played, int $discarded, string $di): string
    {
        $battle = &$state['battle'];
        if ($battle === null || $battle['step'] !== 'cards' || isset($battle['choices'][$id]) || !in_array($id, [$battle['attacker'], $battle['defender']], true)) throw new UserException(clienttranslate('It is not your battle choice.'));
        $hand = $state['players'][$id]['battleHand'];
        if ($played === $discarded || !in_array($played, $hand, true) || !in_array($discarded, $hand, true)) throw new UserException(clienttranslate('Choose two different battle cards from your hand.'));
        $cards = $di === '' ? [] : explode(',', $di);
        $available = array_count_values($state['players'][$id]['diHand']);
        $defs = PrototypeRules::di();
        $cost = 0;
        foreach (array_count_values($cards) as $card => $count) {
            $card = (string) $card;
            if (!isset($defs[$card]) || $defs[$card]['phase'] !== 'battle' || ($available[$card] ?? 0) < $count) throw new UserException(clienttranslate('Choose battle DI cards from your hand.'));
            $cost += $defs[$card]['cost'] * $count;
        }
        if ($state['players'][$id]['pp'] < $cost) throw new UserException(clienttranslate('Not enough prayer for those DI cards.'));
        $battle['choices'][$id] = ['played' => $played, 'discarded' => $discarded, 'di' => $cards];
        if (count($battle['choices']) < 2) return '';
        unset($battle);
        return $this->resolveBattle($state);
    }

    private function resolveBattle(array &$state): string
    {
        $battle = &$state['battle'];
        $zone = &$state['zones'][$battle['zone']];
        $cardsDef = PrototypeRules::battleCards();
        $diDef = PrototypeRules::di();
        $totals = [];
        foreach ([$battle['attacker'], $battle['defender']] as $id) {
            $choice = $battle['choices'][$id];
            $card = $cardsDef[$choice['played']];
            $attacking = $id === $battle['attacker'];
            foreach ($choice['di'] as $di) {
                $this->spend($state, $id, $diDef[$di]['cost']);
                $key = array_search($di, $state['players'][$id]['diHand'], true);
                array_splice($state['players'][$id]['diHand'], $key, 1);
                $state['diDiscard'][] = $di;
            }
            $di = array_count_values($choice['di']);
            $totals[$id] = [
                'strength' => ($zone['units'][$id] ?? 0) + $card['strength'] + $this->tileBonus($state, $id, 'strength') + $this->tileBonus($state, $id, $attacking ? 'attack' : 'defend') + (int) ($zone['city'] === $id) + ($di['fury'] ?? 0) * 2,
                'damage' => $card['damage'] + $this->tileBonus($state, $id, 'damage'),
                'pierce' => $card['pierce'] + ($di['wound'] ?? 0),
                'shield' => $card['shield'] + $this->tileBonus($state, $id, 'shield') + ($di['wall'] ?? 0) * 2,
            ];
            $player = &$state['players'][$id];
            $player['battleHand'] = array_values(array_diff($player['battleHand'], [$choice['played'], $choice['discarded']]));
            $player['battleDiscard'][] = $choice['played'];
            $player['battleDiscard'][] = $choice['discarded'];
            $player['battlePlayed'][] = $choice['played'];
            if (!$player['battleHand']) {
                $player['battleHand'] = array_keys($cardsDef);
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
        // Rewards: an attacker who wins and keeps a troop gains 1 Battle FP. A winning defender
        // gains 1 Veteran, and every player who gained no FP gains 1 Veteran (so 2 for a winning defender).
        if ($winner === $a && ($zone['units'][$a] ?? 0) > 0) {
            $state['players'][$a]['permanent']++;
            $this->bga->playerStats->inc('battle_fp', 1, (int) $a);
            $this->event('log', clienttranslate('${player_name} gains 1 Battle FP'), ['player_id' => $a]);
        } else {
            $state['players'][$a]['veterans']++;
        }
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
            if (($target['units'][$loser] ?? 0) + $units > 5) continue;
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
            $this->gain($state, $id, 2 + $this->tileBonus($state, $id, 'night'));
        }
        foreach ($state['order'] as $id) {
            $choice = $state['night']['choices'][$id];
            $state['players'][$id]['veterans'] -= $choice['pray'];
            $this->gain($state, $id, $choice['pray']);
            $draw = min($choice['draw'], $state['players'][$id]['veterans']);
            $state['players'][$id]['veterans'] -= $draw;
            $this->drawDi($state, $id, 1 + $draw);
            $recruit = min($choice['recruit'], $state['players'][$id]['veterans'], 12 - $this->unitCount($state, $id));
            $district = $choice['district'];
            if ($recruit > 0 && isset($state['zones'][$district]) && $state['zones'][$district]['city'] === $id && $this->controller($state['zones'][$district]) === $id) {
                $recruit = min($recruit, 5 - ($state['zones'][$district]['units'][$id] ?? 0));
                $state['zones'][$district]['units'][$id] = ($state['zones'][$district]['units'][$id] ?? 0) + $recruit;
                if ($recruit > 0) $this->event('recruit', clienttranslate('${player_name} recruits ${units} unit(s) in ${zone_name}'), [
                    'player_id' => $id, 'units' => $recruit, 'zone' => $district, 'zone_name' => $this->zoneArg($state, $district),
                ]);
            }
            $state['players'][$id]['veterans'] = 0;
            $state['players'][$id]['slots'] = [];
            $state['players'][$id]['bought'] = [];
        }
        // Destiny: fewest FP chooses a turn-order space first; ties go to the player who was leftmost.
        $old = array_flip($state['order']);
        $queue = $state['order'];
        usort($queue, fn($a, $b) => $this->score($state, $a) <=> $this->score($state, $b) ?: $old[$a] <=> $old[$b]);
        $state['night'] = null;
        $state['destiny'] = ['queue' => $queue, 'index' => 0, 'positions' => array_fill(0, count($queue), null)];
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
