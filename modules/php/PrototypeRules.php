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

    /**
     * Action spaces on the Individual board pyramid (Player Board Front art), tier 3 = top level.
     * Top: Move, Recruit. Middle: Raise, Move, Pray. Bottom: Pray and the Buy a Power Tile spaces.
     * The Divine Power space at the top takes the gold Action token (slot 'gold:<tile>', a Golden Action from an owned tile);
     * a silver Action token is played on any empty space (slot 'silver:<space>').
     */
    public const SLOTS = [
        'move_a' => ['tier' => 3, 'action' => 'move'],
        'recruit_a' => ['tier' => 3, 'action' => 'recruit'],
        'raise' => ['tier' => 2, 'action' => 'raise'],
        'move_b' => ['tier' => 2, 'action' => 'move'],
        'pray_b' => ['tier' => 2, 'action' => 'pray'],
        'pray_c' => ['tier' => 1, 'action' => 'pray'],
        'buy_ruby' => ['tier' => 1, 'action' => 'buy'],
        'buy_sapphire' => ['tier' => 1, 'action' => 'buy'],
        'buy_diamond' => ['tier' => 1, 'action' => 'buy'],
        'buy_onyx' => ['tier' => 1, 'action' => 'buy'],
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
            // Special Battle cards gained from the Wrryt Storm / Shetyw Barrier tiles ('tile' = art source).
            // The supplied PDFs do not print these cards, so their values are provisional ('unverified').
            9 => ['name' => clienttranslate('Wrryt Storm'), 'strength' => 3, 'damage' => 2, 'pierce' => 1, 'shield' => 0, 'special' => true, 'tile' => 'ruby_wrryt_storm', 'unverified' => true],
            10 => ['name' => clienttranslate('Shetyw Barrier'), 'strength' => 3, 'damage' => 0, 'pierce' => 0, 'shield' => 3, 'special' => true, 'tile' => 'sapphire_shetyw_barrier', 'unverified' => true],
        ];
    }

    /** The 8 Battle cards every player starts with. */
    public const BASE_BATTLE_CARDS = [1, 2, 3, 4, 5, 6, 7, 8];

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

    /**
     * The 64 printed Power tiles (Player Aid v DEF pages 1-4), keyed by color, name and copy.
     *   art       index in img/power-tiles.jpg (Punchboard Front order)
     *   mods      passive modifiers summed by CoreGame::tileBonus()
     *   creature  stats of the Creature tied to a Troop: move, strength, damage, shield, plus special flags
     *             (snake: ignores the opposing Creature; phoenix: may enter 1 opposing District without adjacency;
     *             griffin: free Teleportation, also from an Obelisk; khnum: opponents pay 1 PP to enter its zone)
     *   golden    Golden Action resolved with the gold Action token: move_recruit, acquire, move_noteleport, move_surprise
     *   card      Special Battle card id gained when acquired (see battleCards())
     *   aog       Act of God, removed in 2-player games together with second copies (copy 2)
     *   stack     market stack shared by duplicate copies
     * Cost is always the tile level in PP.
     */
    public static function tiles(): array
    {
        $t = fn(string $name, string $color, int $level, int $art, string $text, array $mods = [], array $extra = [], int $copy = 1) =>
            ['name' => $name, 'color' => $color, 'level' => $level, 'art' => $art, 'text' => $text, 'mods' => $mods, 'copy' => $copy] + $extra;
        $creature = fn(int $move, int $strength, int $damage = 0, int $shield = 0, array $flags = []) =>
            ['creature' => ['move' => $move, 'strength' => $strength, 'damage' => $damage, 'shield' => $shield] + $flags];
        $domination = clienttranslate('When acquired: gain 1 Divine FP.');
        $actOfGod = clienttranslate('When acquired: gain 1 silver Action token (play it on any empty action space together with one of your own Action tokens, once per round).');
        $tiles = [
            // Onyx
            'onyx_sword_mercenaries' => $t(clienttranslate('Sword Clan Mercenaries'), 'onyx', 1, 0, clienttranslate('When acquired: the 3 Sword Clan Mercenaries join your faction for the rest of the game. Add them to your Districts.'), ['acquire_units' => 3]),
            // The Player Aid prints both Mercenaries as one tile, so they are a duplicate pair (2 players keep only Sword Clan).
            'onyx_spear_mercenaries' => $t(clienttranslate('Spear Clan Mercenaries'), 'onyx', 1, 1, clienttranslate('When acquired: the 3 Spear Clan Mercenaries join your faction for the rest of the game. Add them to your Districts.'), ['acquire_units' => 3], [], 2),
            'onyx_local_recruitment' => $t(clienttranslate('Local Recruitment'), 'onyx', 1, 2, clienttranslate('Recruit action: add 1 Unit for free, and you may add your Units to your Troops anywhere on the board instead of only your Districts.'), ['recruit_free' => 1, 'recruit_troops' => 1]),
            'onyx_local_recruitment_2' => $t(clienttranslate('Local Recruitment'), 'onyx', 1, 3, clienttranslate('Recruit action: add 1 Unit for free, and you may add your Units to your Troops anywhere on the board instead of only your Districts.'), ['recruit_free' => 1, 'recruit_troops' => 1], [], 2),
            'onyx_honor_in_battle' => $t(clienttranslate('Honor in Battle'), 'onyx', 2, 4, clienttranslate('Battle, end of Casualties step: gain 1 PP for each of your Units removed by opposing Damage.'), ['pp_per_loss' => 1]),
            'onyx_dedication_to_battle' => $t(clienttranslate('Dedication to Battle'), 'onyx', 2, 5, clienttranslate('Battle, Attack, start of War Council step: gain 2 PP.'), ['attack_pp' => 2]),
            'onyx_khnums_sphinx' => $t(clienttranslate('Khnum\'s Sphinx'), 'onyx', 2, 6, clienttranslate('Creature. Tied Troop: Move action +1 Land move; Battle +1 Strength. An opposing Troop must spend 1 additional PP to enter the zone controlled by Khnum\'s Sphinx.'), [], $creature(1, 1, 0, 0, ['khnum' => true])),
            'onyx_twin_ceremony' => $t(clienttranslate('Twin Ceremony'), 'onyx', 2, 7, clienttranslate('Golden Action: take 1 Acquire action by spending 1 additional PP, possibly in a color you already acquired this Round.'), [], ['golden' => 'acquire']),
            'onyx_griffin_sphinx' => $t(clienttranslate('Griffin Sphinx'), 'onyx', 3, 8, clienttranslate('Creature. Tied Troop: if the Griffin Sphinx teleports with its Troop, the Teleportation is free and may start from an Obelisk zone; Battle +2 Strength.'), [], $creature(0, 2, 0, 0, ['griffin' => true])),
            'onyx_forced_march' => $t(clienttranslate('Forced March'), 'onyx', 3, 9, clienttranslate('Golden Action: take 1 Move action during which you cannot use any Teleportation.'), [], ['golden' => 'move_noteleport']),
            'onyx_deadly_trap' => $t(clienttranslate('Deadly Trap'), 'onyx', 3, 10, clienttranslate('Battle, Defense: your Troops gain +1 Strength and +1 Unblockable Damage.'), ['defend_strength' => 1, 'defend_pierce' => 1]),
            'onyx_domination' => $t(clienttranslate('Domination'), 'onyx', 3, 11, $domination, ['divine_fp' => 1]),
            'onyx_amout' => $t(clienttranslate('Amout, the Soul Devourer'), 'onyx', 4, 12, clienttranslate('Creature. Tied Troop: Move action +1 Land move; Battle +2 Strength. Battle, Rewards step: if you win and removed at least 2 opposing Units with your Damage, gain 1 extra Battle FP.'), ['kill2_win_fp' => 1], $creature(1, 2)),
            'onyx_bestial_fury' => $t(clienttranslate('Bestial Fury'), 'onyx', 4, 13, clienttranslate('Move action: your Troops gain +1 Land move. Battle: your Troops gain +1 Strength and +1 Damage.'), ['move' => 1, 'strength' => 1, 'damage' => 1]),
            'onyx_divine_might' => $t(clienttranslate('Divine Might'), 'onyx', 4, 14, clienttranslate('Day and Night: each time another effect makes you gain at least 1 PP, gain +2 PP.'), ['pp_gain_bonus' => 2]),
            'onyx_act_of_god' => $t(clienttranslate('Act of God'), 'onyx', 4, 15, $actOfGod, ['silver' => 1], ['aog' => true]),
            // Sapphire
            'sapphire_recruiting_scribe' => $t(clienttranslate('Recruiting Scribe'), 'sapphire', 1, 16, clienttranslate('Recruit action: add up to 3 Units for free.'), ['recruit_free' => 3]),
            'sapphire_recruiting_scribe_2' => $t(clienttranslate('Recruiting Scribe'), 'sapphire', 1, 17, clienttranslate('Recruit action: add up to 3 Units for free.'), ['recruit_free' => 3], [], 2),
            'sapphire_defense' => $t(clienttranslate('Defense!'), 'sapphire', 1, 18, clienttranslate('Battle, Defense: your Troops gain +1 Strength.'), ['defend_strength' => 1]),
            'sapphire_defense_2' => $t(clienttranslate('Defense!'), 'sapphire', 1, 19, clienttranslate('Battle, Defense: your Troops gain +1 Strength.'), ['defend_strength' => 1], [], 2),
            'sapphire_reinforcement_prayer' => $t(clienttranslate('Reinforcement Prayer'), 'sapphire', 2, 20, clienttranslate('Pray action: you may add up to 2 Units in total for free in your Districts. Battles may then trigger as for Recruit.'), ['pray_units' => 2]),
            'sapphire_ancestral_elephant' => $t(clienttranslate('Ancestral Elephant'), 'sapphire', 2, 21, clienttranslate('Creature. Tied Troop: Move action +1 Land move; Battle +1 Strength and +1 Shield.'), [], $creature(1, 1, 0, 1)),
            'sapphire_shetyw_barrier' => $t(clienttranslate('Shetyw Barrier'), 'sapphire', 2, 22, clienttranslate('When acquired: take back your Battle cards, remove 1 from the game in secret and replace it with the Special Battle card "Shetyw Barrier".'), [], ['card' => 10]),
            'sapphire_shield_of_neith' => $t(clienttranslate('Shield of Neith'), 'sapphire', 2, 23, clienttranslate('Battle: your Troops gain +1 Shield.'), ['shield' => 1]),
            'sapphire_legion' => $t(clienttranslate('Legion'), 'sapphire', 3, 24, clienttranslate('Your Troop Limit becomes 7 Units instead of 5.'), ['troop_limit' => 7]),
            'sapphire_defensive_victory' => $t(clienttranslate('Defensive Victory'), 'sapphire', 3, 25, clienttranslate('Battle, Defense, Rewards step: if you win and still have a Troop in the Battle zone, gain 1 Battle FP.'), ['defend_win_fp' => 1]),
            'sapphire_spy' => $t(clienttranslate('Spy'), 'sapphire', 3, 26, clienttranslate('Battle, War Council step: your opponent shows you the Battle card they play (not the DI cards) before you choose your cards.'), ['spy' => 1]),
            'sapphire_domination' => $t(clienttranslate('Domination'), 'sapphire', 3, 27, $domination, ['divine_fp' => 1]),
            'sapphire_reinforcement' => $t(clienttranslate('Reinforcement'), 'sapphire', 4, 28, clienttranslate('Recruit action: add as many Units as you wish for free.'), ['recruit_free' => 99]),
            'sapphire_sphinx' => $t(clienttranslate('Sphinx'), 'sapphire', 4, 29, clienttranslate('When acquired: gain 1 Divine FP. Creature. Tied Troop: Move action +1 Land move; Battle +2 Strength.'), ['divine_fp' => 1], $creature(1, 2)),
            'sapphire_divine_will' => $t(clienttranslate('Divine Will'), 'sapphire', 4, 30, clienttranslate('Golden Action: take 1 Move action or 1 Recruit action.'), [], ['golden' => 'move_recruit']),
            'sapphire_act_of_god' => $t(clienttranslate('Act of God'), 'sapphire', 4, 31, $actOfGod, ['silver' => 1], ['aog' => true]),
            // Ruby
            'ruby_charge' => $t(clienttranslate('Charge!'), 'ruby', 1, 32, clienttranslate('Battle, Attack: your Troops gain +1 Strength.'), ['attack_strength' => 1]),
            'ruby_charge_2' => $t(clienttranslate('Charge!'), 'ruby', 1, 33, clienttranslate('Battle, Attack: your Troops gain +1 Strength.'), ['attack_strength' => 1], [], 2),
            'ruby_priest_of_mafdet' => $t(clienttranslate('Priest of Mafdet'), 'ruby', 1, 34, clienttranslate('Move action: your Troops gain +1 Land move.'), ['move' => 1]),
            'ruby_priest_of_mafdet_2' => $t(clienttranslate('Priest of Mafdet'), 'ruby', 1, 35, clienttranslate('Move action: your Troops gain +1 Land move.'), ['move' => 1], [], 2),
            'ruby_carnage' => $t(clienttranslate('Carnage'), 'ruby', 2, 36, clienttranslate('Battle: your Troops gain +1 Damage.'), ['damage' => 1]),
            'ruby_wrryt_storm' => $t(clienttranslate('Wrryt Storm'), 'ruby', 2, 37, clienttranslate('When acquired: take back your Battle cards, remove 1 from the game in secret and replace it with the Special Battle card "Wrryt Storm".'), [], ['card' => 9]),
            'ruby_phoenix' => $t(clienttranslate('Phoenix'), 'ruby', 2, 38, clienttranslate('Creature. Tied Troop: Move action +1 Land move, and it may enter 1 opposing District without starting adjacent to it; Battle +1 Strength.'), [], $creature(1, 1, 0, 0, ['phoenix' => true])),
            'ruby_dimensional_portal' => $t(clienttranslate('Dimensional Portal'), 'ruby', 2, 39, clienttranslate('Move action: your Teleportation costs 1 PP less (minimum 0) and may start from an Obelisk zone.'), ['teleport_discount' => 1, 'teleport_obelisk' => 1]),
            'ruby_royal_scarab' => $t(clienttranslate('Royal Scarab'), 'ruby', 3, 40, clienttranslate('Creature. Tied Troop: Move action +2 Land move; Battle +2 Strength.'), [], $creature(2, 2)),
            'ruby_blade_of_neith' => $t(clienttranslate('Blade of Neith'), 'ruby', 3, 41, clienttranslate('Battle: your Troops gain +1 Strength.'), ['strength' => 1]),
            'ruby_divine_wound' => $t(clienttranslate('Divine Wound'), 'ruby', 3, 42, clienttranslate('Battle, end of Confrontation step: you may discard DI cards from your hand; your Troop gains +1 Strength per card discarded.'), ['divine_wound' => 1]),
            'ruby_domination' => $t(clienttranslate('Domination'), 'ruby', 3, 43, $domination, ['divine_fp' => 1]),
            'ruby_giant_scorpion' => $t(clienttranslate('Giant Scorpion'), 'ruby', 4, 44, clienttranslate('Creature. Tied Troop: Move action +1 Land move; Battle +2 Strength and +2 Damage.'), [], $creature(1, 2, 2)),
            'ruby_initiative' => $t(clienttranslate('Initiative'), 'ruby', 4, 45, clienttranslate('Battle, Attack: your Troops gain +1 Strength, +1 Damage and +1 Unblockable Damage. Battle, Defense: your Troops gain +1 Damage and +1 Unblockable Damage.'), ['attack_strength' => 1, 'damage' => 1, 'pierce' => 1]),
            'ruby_surprise_attack' => $t(clienttranslate('Surprise Attack'), 'ruby', 4, 46, clienttranslate('Golden Action: take 1 Move action during which your Troop may enter 1 opposing District without starting adjacent to it.'), [], ['golden' => 'move_surprise']),
            'ruby_act_of_god' => $t(clienttranslate('Act of God'), 'ruby', 4, 47, $actOfGod, ['silver' => 1], ['aog' => true]),
            // Diamond
            'diamond_priest' => $t(clienttranslate('Priest'), 'diamond', 1, 48, clienttranslate('Pray action: gain +1 PP.'), ['pray' => 1]),
            'diamond_priest_2' => $t(clienttranslate('Priest'), 'diamond', 1, 49, clienttranslate('Pray action: gain +1 PP.'), ['pray' => 1], [], 2),
            'diamond_priestess' => $t(clienttranslate('Priestess'), 'diamond', 1, 50, clienttranslate('Acquire action: every Power tile costs 1 PP less (minimum 0).'), ['buy_discount' => 1]),
            'diamond_priestess_2' => $t(clienttranslate('Priestess'), 'diamond', 1, 51, clienttranslate('Acquire action: every Power tile costs 1 PP less (minimum 0).'), ['buy_discount' => 1], [], 2),
            'diamond_magical_support' => $t(clienttranslate('Magical Support'), 'diamond', 2, 52, clienttranslate('Build action: each Level costs 1 PP less (minimum 0), even when you add several Levels at once.'), ['raise_discount' => 1]),
            'diamond_deep_desert_snake' => $t(clienttranslate('Deep Desert Snake'), 'diamond', 2, 53, clienttranslate('Creature. Tied Troop: Move action +1 Land move; in Battle it ignores the effects and bonuses of the Creature tied to the opposing Troop.'), [], $creature(1, 0, 0, 0, ['snake' => true])),
            'diamond_vision' => $t(clienttranslate('Vision'), 'diamond', 2, 54, clienttranslate('Night, Divine Intervention step: draw 4 additional DI cards, then discard 4 DI cards.'), ['vision' => 4]),
            'diamond_divine_boon' => $t(clienttranslate('Divine Boon'), 'diamond', 2, 55, clienttranslate('Night, Divine Intervention step: draw 1 additional DI card.'), ['night_di' => 1]),
            'diamond_hand_of_god' => $t(clienttranslate('Hand of God'), 'diamond', 3, 56, clienttranslate('Night, Awakening step: you may take 1 Build action to add 1 Level for free to 1 of your Pyramids.'), ['hand_of_god' => 1]),
            'diamond_divine_hunger' => $t(clienttranslate('Divine Hunger'), 'diamond', 3, 57, clienttranslate('Battle, end of Casualties step: gain 2 PP per opposing Unit removed by your Damage.'), ['pp_per_kill' => 2]),
            'diamond_priest_of_thoeris' => $t(clienttranslate('Priest of Thoeris'), 'diamond', 3, 58, clienttranslate('Battle, Attack, start of War Council step: draw 1 DI card.'), ['attack_draw' => 1]),
            'diamond_domination' => $t(clienttranslate('Domination'), 'diamond', 3, 59, $domination, ['divine_fp' => 1]),
            'diamond_priest_of_ra' => $t(clienttranslate('Priest of Ra'), 'diamond', 4, 60, clienttranslate('Each time you spend at least 1 PP for an effect, that cost is reduced by 1 PP (minimum 0).'), ['spend_discount' => 1]),
            'diamond_priest_of_amon' => $t(clienttranslate('Priest of Amon'), 'diamond', 4, 61, clienttranslate('Night, Prayers step: gain +7 PP.'), ['night_pp' => 7]),
            'diamond_the_mummy' => $t(clienttranslate('The Mummy'), 'diamond', 4, 62, clienttranslate('Creature. Tied Troop: Move action +1 Land move; Battle +2 Strength. Night, Divine Intervention step: draw 1 additional DI card.'), ['night_di' => 1], $creature(1, 2)),
            'diamond_act_of_god' => $t(clienttranslate('Act of God'), 'diamond', 4, 63, $actOfGod, ['silver' => 1], ['aog' => true]),
        ];
        // Duplicate level-1 tiles share one market stack: the second copy points at the first.
        foreach ($tiles as $key => &$tile) {
            $tile['stack'] = $key === 'onyx_spear_mercenaries' ? 'onyx_sword_mercenaries' : preg_replace('/_2$/', '', $key);
        }
        unset($tile);
        return $tiles;
    }

    /** Tiles in play for this player count and color set (2 players: no Act of God, one copy of each). */
    public static function tilesInPlay(array $colors, int $players): array
    {
        return array_keys(array_filter(self::tiles(), fn($tile) => in_array($tile['color'], $colors, true)
            && ($players > 2 || (empty($tile['aog']) && $tile['copy'] === 1))));
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
