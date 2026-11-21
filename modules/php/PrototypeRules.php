<?php
declare(strict_types=1);

namespace Bga\Games\kemetbloodandsands;

/**
 * Prototype components and board zones placed on the printed Kemet map.
 * Player-facing names and texts are wrapped in clienttranslate() so they can be translated;
 * the client displays them with _().
 */
final class PrototypeRules
{
    public const COLORS = ['ruby', 'sapphire', 'diamond', 'onyx'];

    public const SLOTS = [
        'raise' => ['tier' => 1, 'action' => 'raise'],
        'move_a' => ['tier' => 1, 'action' => 'move'],
        'recruit_a' => ['tier' => 1, 'action' => 'recruit'],
        'buy_ruby' => ['tier' => 1, 'action' => 'buy'],
        'buy_sapphire' => ['tier' => 1, 'action' => 'buy'],
        'buy_diamond' => ['tier' => 1, 'action' => 'buy'],
        'buy_onyx' => ['tier' => 1, 'action' => 'buy'],
        'move_b' => ['tier' => 2, 'action' => 'move'],
        'recruit_b' => ['tier' => 2, 'action' => 'recruit'],
        'pray_b' => ['tier' => 2, 'action' => 'pray'],
        'move_c' => ['tier' => 3, 'action' => 'move'],
        'pray_c' => ['tier' => 3, 'action' => 'pray'],
    ];

    public static function colorNames(): array
    {
        return [
            'ruby' => clienttranslate('Ruby'),
            'sapphire' => clienttranslate('Sapphire'),
            'diamond' => clienttranslate('Diamond'),
            'onyx' => clienttranslate('Onyx'),
        ];
    }

    public static function actionNames(): array
    {
        return [
            'pray' => clienttranslate('Pray'),
            'raise' => clienttranslate('Raise a Pyramid'),
            'buy' => clienttranslate('Buy a Power tile'),
            'recruit' => clienttranslate('Recruit'),
            'move' => clienttranslate('Move'),
        ];
    }

    public static function battleCards(): array
    {
        return [
            1 => ['name' => clienttranslate('Charge'), 'strength' => 4, 'damage' => 0, 'pierce' => 0, 'shield' => 0],
            2 => ['name' => clienttranslate('Assault'), 'strength' => 3, 'damage' => 2, 'pierce' => 0, 'shield' => 0],
            3 => ['name' => clienttranslate('Ambush'), 'strength' => 3, 'damage' => 0, 'pierce' => 1, 'shield' => 0],
            4 => ['name' => clienttranslate('Guard'), 'strength' => 2, 'damage' => 0, 'pierce' => 0, 'shield' => 2],
            5 => ['name' => clienttranslate('Strike'), 'strength' => 2, 'damage' => 2, 'pierce' => 0, 'shield' => 0],
            6 => ['name' => clienttranslate('Skirmish'), 'strength' => 2, 'damage' => 1, 'pierce' => 0, 'shield' => 1],
            7 => ['name' => clienttranslate('Shield Wall'), 'strength' => 1, 'damage' => 0, 'pierce' => 0, 'shield' => 3],
            8 => ['name' => clienttranslate('Sacrifice'), 'strength' => 1, 'damage' => 0, 'pierce' => 2, 'shield' => 0],
        ];
    }

    public static function di(): array
    {
        return [
            'blessing' => ['name' => clienttranslate('Divine Blessing'), 'phase' => 'day', 'cost' => 0, 'text' => clienttranslate('Gain 2 prayer points.')],
            'reinforce' => ['name' => clienttranslate('Reinforcement'), 'phase' => 'day', 'cost' => 0, 'text' => clienttranslate('Recruit 1 unit in a free home district.')],
            'fury' => ['name' => clienttranslate('War Fury'), 'phase' => 'battle', 'cost' => 1, 'text' => clienttranslate('+2 strength.')],
            'wall' => ['name' => clienttranslate('Bronze Wall'), 'phase' => 'battle', 'cost' => 1, 'text' => clienttranslate('+2 defense.')],
            'wound' => ['name' => clienttranslate('Divine Wound'), 'phase' => 'battle', 'cost' => 1, 'text' => clienttranslate('+1 unblockable damage.')],
            'diversion' => ['name' => clienttranslate('Diversion'), 'phase' => 'battle', 'cost' => 0, 'text' => clienttranslate('Bluff: no effect.')],
        ];
    }

    public static function tiles(): array
    {
        return [
            'ruby_charge' => ['name' => clienttranslate('Charge!'), 'color' => 'ruby', 'level' => 1, 'text' => clienttranslate('+1 strength when attacking.'), 'effect' => 'attack'],
            'ruby_spear' => ['name' => clienttranslate('War Spear'), 'color' => 'ruby', 'level' => 1, 'text' => clienttranslate('+1 damage in battle.'), 'effect' => 'damage'],
            'ruby_blade' => ['name' => clienttranslate('Blade of Neith'), 'color' => 'ruby', 'level' => 2, 'text' => clienttranslate('+1 strength in battle.'), 'effect' => 'strength'],
            'ruby_march' => ['name' => clienttranslate('Desert March'), 'color' => 'ruby', 'level' => 2, 'text' => clienttranslate('+1 land movement.'), 'effect' => 'move'],
            'ruby_blood' => ['name' => clienttranslate('Blood Rite'), 'color' => 'ruby', 'level' => 3, 'text' => clienttranslate('+1 damage in battle.'), 'effect' => 'damage'],
            'ruby_dominion' => ['name' => clienttranslate('Domination'), 'color' => 'ruby', 'level' => 4, 'text' => clienttranslate('Gain 1 permanent point.'), 'effect' => 'point'],
            'sapphire_guard' => ['name' => clienttranslate('Bronze Armor'), 'color' => 'sapphire', 'level' => 1, 'text' => clienttranslate('+1 defense in battle.'), 'effect' => 'shield'],
            'sapphire_recruit' => ['name' => clienttranslate('Conscription'), 'color' => 'sapphire', 'level' => 2, 'text' => clienttranslate('Recruit 1 extra unit for free on Recruit.'), 'effect' => 'recruit'],
            'sapphire_fort' => ['name' => clienttranslate('Fortification'), 'color' => 'sapphire', 'level' => 3, 'text' => clienttranslate('+1 strength while defending.'), 'effect' => 'defend'],
            'sapphire_sphinx' => ['name' => clienttranslate('Sphinx'), 'color' => 'sapphire', 'level' => 4, 'text' => clienttranslate('Gain 1 permanent point.'), 'effect' => 'point'],
            'diamond_priest' => ['name' => clienttranslate('Priest'), 'color' => 'diamond', 'level' => 1, 'text' => clienttranslate('+1 prayer when you Pray.'), 'effect' => 'pray'],
            'diamond_scribe' => ['name' => clienttranslate('Scribe'), 'color' => 'diamond', 'level' => 2, 'text' => clienttranslate('Draw 1 DI card immediately.'), 'effect' => 'draw'],
            'diamond_oracle' => ['name' => clienttranslate('Oracle'), 'color' => 'diamond', 'level' => 3, 'text' => clienttranslate('+1 prayer each night.'), 'effect' => 'night'],
            'diamond_glory' => ['name' => clienttranslate('Divine Glory'), 'color' => 'diamond', 'level' => 4, 'text' => clienttranslate('Gain 1 permanent point.'), 'effect' => 'point'],
            'onyx_edge' => ['name' => clienttranslate('Dark Edge'), 'color' => 'onyx', 'level' => 1, 'text' => clienttranslate('+1 damage in battle.'), 'effect' => 'damage'],
            'onyx_guard' => ['name' => clienttranslate('Dark Guard'), 'color' => 'onyx', 'level' => 2, 'text' => clienttranslate('+1 defense in battle.'), 'effect' => 'shield'],
            'onyx_march' => ['name' => clienttranslate('Night March'), 'color' => 'onyx', 'level' => 3, 'text' => clienttranslate('+1 land movement.'), 'effect' => 'move'],
            'onyx_glory' => ['name' => clienttranslate('Dark Glory'), 'color' => 'onyx', 'level' => 4, 'text' => clienttranslate('Gain 1 permanent point.'), 'effect' => 'point'],
        ];
    }

    /** Coordinates use the 1381 x 1700 cropped game-board image in img/. */
    public static function citySiteDefs(): array
    {
        return [
            'nw' => ['name' => clienttranslate('Northwest City'), 'abbr' => 'NW', 'x' => 92, 'y' => 68, 'districts' => [[151, 149], [255, 281], [150, 432]], 'approach' => 'desert_nw'],
            'mw' => ['name' => clienttranslate('West Middle City'), 'abbr' => 'WM', 'x' => 347, 'y' => 893, 'districts' => [[477, 950], [477, 1083], [641, 1154]], 'approach' => 'desert_w_south'],
            'sw' => ['name' => clienttranslate('Southwest City'), 'abbr' => 'SW', 'x' => 88, 'y' => 1269, 'districts' => [[164, 1351], [170, 1533], [385, 1520]], 'approach' => 'desert_sw'],
            'ne' => ['name' => clienttranslate('Northeast City'), 'abbr' => 'NE', 'x' => 1044, 'y' => 67, 'districts' => [[1234, 145], [1120, 260], [1241, 311]], 'approach' => 'desert_ne'],
            'me' => ['name' => clienttranslate('East Middle City'), 'abbr' => 'EM', 'x' => 760, 'y' => 630, 'districts' => [[825, 721], [978, 731], [943, 879]], 'approach' => 'desert_e_south'],
            'se' => ['name' => clienttranslate('Southeast City'), 'abbr' => 'SE', 'x' => 1150, 'y' => 1308, 'districts' => [[1240, 1374], [996, 1521], [1214, 1535]], 'approach' => 'desert_se'],
        ];
    }

    public static function commonZones(): array
    {
        return [
            'sanctuary' => ['name' => clienttranslate('Sanctuary of All Gods'), 'kind' => 'sanctuary', 'abbr' => 'SAN', 'x' => 744, 'y' => 164, 'obelisk' => true, 'prayer' => 0, 'harbor' => 'trade'],
            'delta' => ['name' => clienttranslate('Delta Temple'), 'kind' => 'temple', 'abbr' => 'Δ', 'x' => 563, 'y' => 425, 'obelisk' => true, 'prayer' => 0, 'harbor' => 'trade'],
            'temple_w_bank' => ['name' => clienttranslate('West Bank Temple'), 'kind' => 'temple', 'abbr' => 'T3', 'x' => 463, 'y' => 665, 'obelisk' => true, 'prayer' => 3, 'harbor' => 'trade'],
            'temple_w_desert' => ['name' => clienttranslate('West Desert Temple'), 'kind' => 'temple', 'abbr' => 'T2', 'x' => 131, 'y' => 873, 'obelisk' => true, 'prayer' => 2],
            'desert_nw' => ['name' => clienttranslate('Northwest Desert'), 'kind' => 'desert', 'abbr' => 'NW', 'x' => 299, 'y' => 521, 'obelisk' => false, 'prayer' => 0, 'harbor' => 'military'],
            'desert_w_north' => ['name' => clienttranslate('West Obelisk Desert'), 'kind' => 'desert', 'abbr' => 'OB', 'x' => 222, 'y' => 708, 'obelisk' => true, 'prayer' => 0],
            'desert_w_south' => ['name' => clienttranslate('West Middle Desert'), 'kind' => 'desert', 'abbr' => 'WM', 'x' => 222, 'y' => 1099, 'obelisk' => true, 'prayer' => 0, 'harbor' => 'military'],
            'desert_sw' => ['name' => clienttranslate('Southwest Desert'), 'kind' => 'desert', 'abbr' => 'SW', 'x' => 565, 'y' => 1356, 'obelisk' => false, 'prayer' => 0, 'harbor' => 'military'],
            'temple_e_desert' => ['name' => clienttranslate('East Desert Temple'), 'kind' => 'temple', 'abbr' => 'T2', 'x' => 1244, 'y' => 756, 'obelisk' => true, 'prayer' => 2, 'east' => true],
            'temple_e_bank' => ['name' => clienttranslate('East Bank Temple'), 'kind' => 'temple', 'abbr' => 'T3', 'x' => 918, 'y' => 1190, 'obelisk' => true, 'prayer' => 3, 'harbor' => 'trade', 'east' => true],
            'desert_ne' => ['name' => clienttranslate('Northeast Desert'), 'kind' => 'desert', 'abbr' => 'NE', 'x' => 1064, 'y' => 506, 'obelisk' => true, 'prayer' => 0, 'harbor' => 'military', 'east' => true],
            'desert_e_south' => ['name' => clienttranslate('East Middle Desert'), 'kind' => 'desert', 'abbr' => 'EM', 'x' => 1176, 'y' => 1034, 'obelisk' => true, 'prayer' => 0, 'harbor' => 'military', 'east' => true],
            'desert_se' => ['name' => clienttranslate('Southeast Desert'), 'kind' => 'desert', 'abbr' => 'SE', 'x' => 1054, 'y' => 1344, 'obelisk' => false, 'prayer' => 0, 'harbor' => 'military', 'east' => true],
        ];
    }

    public static function activeSites(int $players): array
    {
        return match ($players) {
            2 => ['nw', 'sw'],
            3 => ['nw', 'mw', 'sw'],
            4 => ['nw', 'sw', 'ne', 'se'],
            default => ['nw', 'mw', 'sw', 'ne', 'se'],
        };
    }

    public static function citySites(array $order): array
    {
        $active = self::activeSites(count($order));
        $owners = array_combine($active, $order);
        $sites = [];
        foreach (self::citySiteDefs() as $key => $def) {
            $sites[$key] = $def + [
                'status' => in_array($key, $active, true) ? 'active' : (count($order) <= 3 && in_array($key, ['ne', 'me', 'se'], true) ? 'outside' : 'storm'),
                'owner' => $owners[$key] ?? null,
            ];
        }
        return $sites;
    }

    /**
     * District zones carry their city name in 'name' and their number in 'district';
     * the client shows them as "<city> <number>".
     */
    public static function board(array $order): array
    {
        $zones = [];
        foreach (self::commonZones() as $key => $def) {
            if (!empty($def['east']) && count($order) <= 3) continue;
            $zones[$key] = self::zone($def['name'], $def['kind'], null, $def['x'], $def['y'], $def['abbr'], $def['obelisk'], $def['prayer'], $def['harbor'] ?? null);
        }
        $active = self::activeSites(count($order));
        $sites = self::citySiteDefs();
        foreach ($order as $index => $id) {
            $id = (string) $id;
            $site = $active[$index];
            $def = $sites[$site];
            $approach = $def['approach'];
            for ($d = 1; $d <= 3; $d++) {
                [$x, $y] = $def['districts'][$d - 1];
                $key = "c{$id}_{$d}";
                $zones[$key] = self::zone($def['name'], 'district', $id, $x, $y, $def['abbr'] . $d);
                $zones[$key]['district'] = $d;
                $zones[$key]['site'] = $site;
                $zones[$key]['approach'] = $approach;
                if ($d === self::riverDistrict($site)) $zones[$key]['harbor'] = 'exit';
                self::link($zones, $key, $approach);
            }
            self::link($zones, "c{$id}_1", "c{$id}_2");
            self::link($zones, "c{$id}_2", "c{$id}_3");
            self::link($zones, "c{$id}_1", "c{$id}_3");
            $zones[$approach]['harborOwner'] = $id;
        }
        foreach ([
            ['sanctuary', 'delta'],
            ['desert_nw', 'desert_w_north'], ['desert_nw', 'temple_w_bank'],
            ['desert_w_north', 'temple_w_bank'], ['desert_w_north', 'temple_w_desert'],
            ['temple_w_bank', 'temple_w_desert'], ['temple_w_bank', 'desert_w_south'],
            ['temple_w_desert', 'desert_w_south'], ['desert_w_south', 'desert_sw'],
            ['temple_w_desert', 'desert_sw'],
            ['desert_ne', 'temple_e_desert'], ['temple_e_desert', 'desert_e_south'],
            ['desert_e_south', 'temple_e_bank'], ['desert_e_south', 'desert_se'],
            ['temple_e_bank', 'desert_se'],
        ] as [$a, $b]) if (isset($zones[$a], $zones[$b])) self::link($zones, $a, $b);
        return $zones;
    }

    private static function zone(string $name, string $kind, ?string $city, int $x, int $y, string $abbr, bool $obelisk = false, int $prayer = 0, ?string $harbor = null): array
    {
        return compact('name', 'kind', 'city', 'x', 'y', 'abbr', 'obelisk', 'prayer', 'harbor') + ['district' => null, 'harborOwner' => null, 'site' => null, 'approach' => null, 'neighbors' => [], 'units' => [], 'pyramid' => null];
    }
    private static function riverDistrict(string $site): int
    {
        return ['nw' => 3, 'mw' => 3, 'sw' => 3, 'ne' => 2, 'me' => 1, 'se' => 2][$site];
    }
    private static function link(array &$zones, string $a, string $b): void
    {
        $zones[$a]['neighbors'][] = $b;
        $zones[$b]['neighbors'][] = $a;
    }
}
