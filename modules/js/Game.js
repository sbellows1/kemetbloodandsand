/**
 * Kemet: Blood and Sand — BGA client.
 *
 * Interaction model: the player clicks highlighted elements (action spaces, map zones,
 * market tiles, cards) and confirms with status-bar buttons. Each multi-step choice is a
 * local "step" (this.sel); the server validates every action.
 */
class KemetState {
    constructor(game, name) { this.game = game; this.name = name; }
    onEnteringState(args, active) { this.game.onEnter(this.name, args || {}, active); }
    onPlayerActivationChange(args, active) { this.game.onEnter(this.name, args || {}, active); }
    onLeavingState() { this.game.onLeave(); }
}

const SYMBOLS = { ruby: '▲', sapphire: '●', diamond: '◆', onyx: '■' };
// Divinity artwork index (img/prototype-players.jpg portraits, img/player-boards.jpg rows) per player color.
const PORTRAITS = { 'ff0000': 0, 'ffa500': 1, '0000ff': 2, '008000': 3, '000000': 4 };
// Action-space centres on the Individual board art, in % of the board image (Player Board Front).
const BOARD_SPACES = {
    move_a: [68.17, 44.19], recruit_a: [79.33, 44.19],
    raise: [62.5, 60], move_b: [73.83, 60], pray_b: [85.17, 60],
    pray_c: [90.67, 75.81],
};
// The Buy a Power Tile spaces fill the bottom level from the Pray space leftwards, in game color order.
const BUY_SPACES = [[81.67, 75.81], [72, 75.81], [62.33, 75.81], [52.67, 75.81]];
// The Divine Power space (gold Action token) at the top of the board.
const GOLD_SPACE = [73.7, 28];
// Golden Action ids (PrototypeRules::tiles 'golden') -> the actions they allow.
const GOLDEN_ACTIONS = { move_recruit: ['move', 'recruit'], acquire: ['buy'], move_noteleport: ['move'], move_surprise: ['move'] };

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.phase = '';
        this.active = false;
        this.args = {};
        this.sel = null;
        for (const name of ['StarterTile', 'PlayerTurn', 'BattleChoice', 'BattleWound', 'BattleAftermath', 'NightChoice', 'NightPowers', 'Destiny']) {
            this.bga.states.register(name, new KemetState(this, name));
        }
    }

    // ---------------------------------------------------------------- setup

    setup(gamedatas) {
        this.me = String(this.bga.players.getCurrentPlayerId());
        this.players = gamedatas.players;
        this.state = gamedatas.kemet;
        this.defs = gamedatas;
        const mine = this.state.players[this.me];
        this.spectator = !mine;
        this.hand = { diHand: mine?.diHand || [], battleHand: mine?.battleHand || [], spyCard: this.state.battle?.spyCard ?? null };

        const others = this.boardOrder().filter(id => id !== this.me);
        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend', `
            <div id="kemet-root">
                <div class="kemet-layout">
                    <div id="kemet-map-wrap"><div id="kemet-map" role="group" aria-label="${this.esc(_('Game board'))}"></div></div>
                    <div id="kemet-side">
                        ${this.spectator ? '' : `<div id="kemet-pboard-${this.me}" class="kemet-pboard mine" role="group" aria-label="${this.esc(_('Your action board'))}"></div><div id="kemet-hand" class="kemet-hand"></div>`}
                        <div class="kemet-others">${others.map(id => `<div id="kemet-pboard-${id}" class="kemet-pboard" role="group" aria-label="${this.esc(this.fmt(_('${player}\'s action board'), { player: this.name(id) }))}"></div>`).join('')}</div>
                    </div>
                </div>
                <div id="kemet-market" class="kemet-market" role="group" aria-label="${this.esc(_('Power tiles'))}"></div>
            </div>`);
        for (const id of Object.keys(this.state.players)) {
            this.bga.playerPanels.getElement(Number(id)).insertAdjacentHTML('beforeend', `<div id="kemet-panel-${id}" class="kemet-panel"></div>`);
        }
        document.getElementById('kemet-root').addEventListener('click', e => this.onRootClick(e));
        this.bga.notifications.setupPromiseNotifications();
        this.render();
    }

    // ---------------------------------------------------------------- helpers

    esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    }
    fmt(text, args) { return text.replace(/\$\{(\w+)\}/g, (m, key) => args[key] ?? m); }
    name(id) { return this.players[id]?.name || ''; }
    colorHex(id) { return this.players[id]?.color || '777777'; }
    zoneName(key) {
        const z = this.state.zones[key];
        return z ? (z.district ? _(z.name) + ' ' + z.district : _(z.name)) : '';
    }
    colorName(color) { return _(this.defs.colorNames[color] || color); }
    me_() { return this.state.players[this.me]; }
    pct(v, total) { return (v / total * 100).toFixed(3) + '%'; }
    enemyIn(key, id = this.me) { return Object.entries(this.state.zones[key].units).some(([p, n]) => p !== id && n > 0); }
    controller(zone) {
        const owner = Object.entries(zone.units).find(([, n]) => n > 0);
        return owner ? owner[0] : (zone.kind === 'district' ? zone.city : null);
    }
    /** Sum of one Power tile modifier over my tiles (mirrors CoreGame::tileBonus; troop_limit takes the max). */
    tileBonus(mod) {
        const values = (this.me_()?.tiles || []).map(t => this.defs.tiles[t]?.mods?.[mod] || 0);
        return mod === 'troop_limit' ? Math.max(0, ...values) : values.reduce((a, b) => a + b, 0);
    }
    troopLimit() { return Math.max(5, this.tileBonus('troop_limit')); }
    supply() { return 12 + (this.me_()?.extraUnits || 0); }
    /** Final PP cost of one effect after Priest of Ra. */
    cost(n) { return n >= 1 ? Math.max(0, n - this.tileBonus('spend_discount')) : Math.max(0, n); }
    recruitCost(n) { return this.cost(Math.max(0, n - this.tileBonus('recruit_free'))); }
    tileCost(tile) { return this.cost(Math.max(0, tile.level - this.tileBonus('buy_discount'))); }
    unitTotal() { return Object.values(this.state.zones).reduce((sum, z) => sum + (z.units[this.me] || 0), 0); }
    homeDistricts() { return Object.keys(this.state.zones).filter(k => this.state.zones[k].city === this.me); }
    tooltip(id, html) { if (document.getElementById(id)) this.bga.gameui.addTooltipHtml(id, html); }

    // ---------------------------------------------------------------- rules mirrors (server re-validates)

    turnInfo() { return this.args.turn || { main: false, silver: false, gold: false, pending: null }; }
    /** Space of a slot string: 'silver:pray_b' -> 'pray_b'. */
    space(slot) { return slot.startsWith('silver:') ? slot.slice(7) : slot; }
    /** Mirrors CoreGame::requireSlot for colored and silver tokens (Equilibrium included). */
    slotAllowed(slot) {
        const p = this.me_(), turn = this.turnInfo();
        const silver = slot.startsWith('silver:'), space = this.space(slot);
        const occupied = [...p.slots, ...(p.silverSlots || [])];
        if (!this.defs.slots[space] || occupied.includes(space)) return false;
        let left;
        if (silver) {
            if (turn.silver || !this.args.silver) return false;
            left = 5 - p.slots.length;
        } else {
            if (turn.main || p.slots.length >= 5) return false;
            left = 5 - p.slots.length - 1;
        }
        const tiers = new Set([...occupied, space].map(s => this.defs.slots[s].tier));
        return left >= 3 - tiers.size;
    }
    slotUsable(slot) {
        if (!this.slotAllowed(slot)) return false;
        return this.actionUsable(this.defs.slots[this.space(slot)].action, slot);
    }
    actionUsable(action, slot) {
        if (action === 'raise') return this.raiseZones().length > 0;
        if (action === 'buy') return this.buyableTiles(slot).length > 0;
        if (action === 'recruit') return this.me_().pp >= this.recruitCost(1) && this.unitTotal() < this.supply() && this.recruitZones().length > 0;
        if (action === 'move') return Object.values(this.state.zones).some(z => (z.units[this.me] || 0) > 0);
        return true;
    }
    goldenOf(slot) { return slot.startsWith('gold:') ? this.defs.tiles[slot.slice(5)]?.golden || '' : ''; }
    usedColors() { return Object.values(this.state.zones).filter(z => z.pyramid?.owner === this.me).map(z => z.pyramid.color); }
    raiseZones() {
        const free = this.state.colors.filter(c => !this.usedColors().includes(c));
        return this.homeDistricts().filter(k => {
            const p = this.state.zones[k].pyramid;
            return p ? p.owner === this.me && p.level < 4 : free.length > 0;
        });
    }
    raiseCost(from, to) {
        const discount = this.tileBonus('raise_discount');
        let c = 0;
        for (let i = from + 1; i <= to; i++) c += Math.max(0, i - discount);
        return this.cost(c);
    }
    /** Twin Ceremony (gold:<tile> with Golden Action 'acquire'): +1 PP, no Acquire space, color may repeat. */
    buyCost(tile, slot) { return this.cost(Math.max(0, tile.level - this.tileBonus('buy_discount')) + (this.goldenOf(slot) === 'acquire' ? 1 : 0)); }
    buyableTiles(slot) {
        const p = this.me_();
        const twin = this.goldenOf(slot) === 'acquire';
        const space = this.space(slot);
        const slotColor = space.slice(4);
        const ownedNames = p.tiles.map(t => this.defs.tiles[t].name);
        return Object.entries(this.defs.tiles).filter(([key, tile]) => {
            if (!this.state.market[key] || (!twin && p.bought.includes(tile.color)) || p.pp < this.buyCost(tile, slot) || ownedNames.includes(tile.name)) return false;
            let own = false, foreign = false;
            for (const z of Object.values(this.state.zones)) {
                if (this.controller(z) !== this.me || z.pyramid?.color !== tile.color || (z.pyramid?.level || 0) < tile.level) continue;
                if (z.city === this.me) own = true; else foreign = true;
            }
            if (twin) return own || foreign;
            const hasToken = this.usedColors().includes(slotColor);
            return (own && space === 'buy_' + tile.color) || (foreign && hasToken);
        }).map(([key]) => key);
    }
    /** Districts of my City, plus every zone with one of my Troops with Local Recruitment. */
    recruitZones() {
        const keys = new Set(this.homeDistricts());
        if (this.tileBonus('recruit_troops') > 0) for (const [k, z] of Object.entries(this.state.zones)) if ((z.units[this.me] || 0) > 0) keys.add(k);
        return [...keys].filter(k => this.recruitMax(k) > 0);
    }
    recruitMax(key) {
        const z = this.state.zones[key];
        let n = Math.min(this.troopLimit() - (z.units[this.me] || 0), this.supply() - this.unitTotal());
        while (n > 0 && this.recruitCost(n) > this.me_().pp) n--;
        return Math.max(0, n);
    }
    freeHomeDistricts() {
        return this.homeDistricts().filter(k => this.controller(this.state.zones[k]) === this.me && (this.state.zones[k].units[this.me] || 0) < this.troopLimit());
    }

    // Creatures: players[id].creatures = { tile: zone | null }.
    creatureAt(id, zone) {
        const entry = Object.entries(this.state.players[id]?.creatures || {}).find(([, at]) => at === zone);
        return entry ? entry[0] : null;
    }
    supplyCreatures() { return Object.entries(this.me_()?.creatures || {}).filter(([, at]) => at === null).map(([t]) => t); }
    creatureZones() { return this.homeDistricts().filter(k => (this.state.zones[k].units[this.me] || 0) > 0); }

    /**
     * Legal next steps of a Move path, mirroring CoreGame::doMove. Returns Map zone -> 'land' | 'teleport'.
     * s: { from, path: [{ zone, teleport }], units, creature, golden }
     */
    moveSteps(s) {
        const z = this.state.zones;
        const tied = this.creatureAt(this.me, s.from);
        const tiedDef = tied ? this.defs.tiles[tied].creature : null;
        const moving = s.creature ? tiedDef : null;
        const landMoves = 1 + this.tileBonus('move') + (tiedDef?.move || 0);
        const land = s.path.filter(st => !st.teleport).length;
        const teleports = s.path.filter(st => st.teleport).length;
        const foreign = s.path.filter(st => z[st.zone].kind === 'district' && z[st.zone].city !== this.me).length;
        const prevKey = s.path.length ? s.path[s.path.length - 1].zone : s.from;
        const prev = z[prevKey];
        const out = new Map();
        // A battle stops movement.
        if (s.path.length && this.enemyIn(prevKey)) return out;
        const anyWall = !!moving?.phoenix || s.golden === 'move_surprise';
        const wallOk = (key, teleport) => {
            const nz = z[key];
            if (nz.kind !== 'district' || nz.city === this.me) return true;
            return foreign === 0 && !teleport && (anyWall || z[s.from].neighbors.includes(key));
        };
        if (land < landMoves) {
            for (const key of Object.keys(z)) {
                if (key === prevKey) continue;
                const nz = z[key];
                const byLand = prev.neighbors.includes(key);
                const harbor = !!prev.harbor && (nz.harbor === 'trade' || (nz.harbor === 'military' && nz.harborOwner === this.me));
                if ((byLand || harbor) && wallOk(key, false)) out.set(key, 'land');
            }
        }
        if (s.golden !== 'move_noteleport' && teleports === 0) {
            const obeliskStart = prev.obelisk && (this.tileBonus('teleport_obelisk') > 0 || !!moving?.griffin);
            const pyramidStart = prev.kind === 'district' && !!prev.pyramid && this.controller(prev) === this.me;
            if ((obeliskStart || pyramidStart) && this.me_().pp >= this.pathCost(s) + this.teleportStepCost(moving)) {
                for (const key of Object.keys(z)) if (key !== prevKey && z[key].obelisk && !out.has(key) && wallOk(key, true)) out.set(key, 'teleport');
            }
        }
        return out;
    }
    teleportStepCost(moving) { return moving?.griffin ? 0 : this.cost(Math.max(0, 2 - this.tileBonus('teleport_discount'))); }
    /** PP spent along a path: teleports, plus 1 PP per zone controlled by an opposing Khnum's Sphinx. */
    pathCost(s) {
        const tied = this.creatureAt(this.me, s.from);
        const moving = s.creature && tied ? this.defs.tiles[tied].creature : null;
        let total = 0;
        for (const st of s.path) {
            if (st.teleport) total += this.teleportStepCost(moving);
            for (const id of Object.keys(this.state.players)) {
                if (id === this.me) continue;
                const c = this.creatureAt(id, st.zone);
                if (c && this.defs.tiles[c].creature?.khnum) total += this.cost(1);
            }
        }
        return total;
    }
    moveEndOk(s) {
        if (!s.path.length) return false;
        const end = s.path[s.path.length - 1].zone;
        if (end === s.from) return false;
        if ((this.state.zones[end].units[this.me] || 0) + s.units > this.troopLimit()) return false;
        if (s.creature && this.creatureAt(this.me, end)) return false;
        return this.pathCost(s) <= this.me_().pp;
    }

    // ---------------------------------------------------------------- state flow

    onEnter(name, args, active) {
        this.phase = name;
        this.args = args;
        this.active = active && !this.spectator;
        this.sel = null;
        if (this.active) this.startPhase();
        else this.refresh();
    }
    onLeave() {
        this.sel = null;
        this.active = false;
        this.bga.statusBar.removeActionButtons();
    }
    startPhase() {
        switch (this.phase) {
            case 'StarterTile': return this.step({ mode: 'starter' });
            case 'PlayerTurn':
                if (this.turnInfo().pending?.type === 'removeCard') return this.step({ mode: 'removeCard' });
                return this.step({ mode: 'choose', silver: false });
            case 'BattleChoice': return this.step({ mode: 'battlePlay' });
            case 'BattleWound': return this.step({ mode: 'wound', di: new Set() });
            case 'BattleAftermath': return this.step({ mode: 'aftermath' });
            case 'NightChoice': return this.step({ mode: 'night', offer: false, delta: false, pray: 0, draw: 0, recruit: 0, district: '' });
            case 'NightPowers': return this.step(this.args.task?.task === 'vision' ? { mode: 'vision', di: new Set() } : { mode: 'handOfGod' });
            case 'Destiny': return this.step({ mode: 'destiny' });
        }
    }
    step(sel) { this.sel = sel; this.refresh(); }
    cancel() { this.startPhase(); }
    act(name, params) {
        this.bga.actions.performAction(name, params).catch(error => {
            console.error('Kemet action failed', { action: name, error });
            this.bga.statusBar.setTitle(_('The action could not be completed. Check your choices and try again.'));
        });
    }

    /** Recomputes selectable elements, status bar title and buttons, then re-renders. */
    refresh() {
        const bar = this.bga.statusBar;
        bar.removeActionButtons();
        this.selectable = { zones: new Set(), slots: new Set(), tiles: new Set(), cards: new Set(), di: new Set(), creatures: new Set(), powers: new Set(), colors: new Set(), levels: new Map(), units: new Set(), night: new Set(), destiny: new Set(), chosen: new Set() };
        if (this.active && this.sel && this.me_()) this.buildStep(bar);
        this.render();
    }

    /** Starts the sub-steps of an action taken from a slot string ('<space>', 'silver:<space>' or 'gold:<tile>'). */
    startAction(action, slot) {
        if (action === 'pray') {
            if (this.tileBonus('pray_units') > 0 && this.unitTotal() < this.supply()) return this.step({ mode: 'prayUnits', slot, targets: [] });
            return this.act('actPray', { slot, reinforce: '' });
        }
        const next = { raise: 'raiseZone', buy: 'buyTile', recruit: 'recruitZone', move: 'moveFrom' }[action];
        this.step({ mode: next, slot, golden: this.goldenOf(slot) });
    }

    buildStep(bar) {
        const s = this.sel, S = this.selectable, p = this.me_();
        const cancel = () => bar.addActionButton(_('Cancel'), () => this.cancel(), { color: 'alert' });
        switch (s.mode) {
            case 'starter':
                bar.setTitle(_('${you} must choose a Level 1 Power tile of one of your Pyramid colors'));
                (this.args.options || []).forEach(k => S.tiles.add(k));
                return;
            case 'choose': {
                const turn = this.turnInfo();
                if (s.silver) {
                    bar.setTitle(_('${you} must choose an action space for your silver Action token'));
                    for (const slot of Object.keys(this.defs.slots)) if (this.slotUsable('silver:' + slot)) S.slots.add(slot);
                    return cancel();
                }
                bar.setTitle(turn.main ? _('${you} may play a bonus Action token or end your turn') : _('${you} must choose an action space'));
                for (const slot of Object.keys(this.defs.slots)) if (this.slotUsable(slot)) S.slots.add(slot);
                this.hand.diHand.forEach((c, i) => { if (this.defs.diCards[c]?.phase === 'day') S.di.add(i); });
                if (this.creatureZones().length) Object.keys(p.creatures || {}).forEach(t => S.creatures.add(t));
                if (this.args.silver > 0 && Object.keys(this.defs.slots).some(slot => this.slotUsable('silver:' + slot))) {
                    S.silver = true;
                }
                for (const tile of this.args.golden || []) {
                    const golden = this.defs.tiles[tile].golden;
                    if ((GOLDEN_ACTIONS[golden] || []).some(action => this.actionUsable(action, 'gold:' + tile))) S.powers.add(tile);
                }
                if (turn.main) bar.addActionButton(_('End turn'), () => this.act('actEndTurn', {}));
                return;
            }
            case 'goldAction':
                bar.setTitle(_('${you} must choose a highlighted action space for your Golden Action'));
                for (const [slot, def] of Object.entries(this.defs.slots)) {
                    if ((GOLDEN_ACTIONS[this.defs.tiles[s.tile].golden] || []).includes(def.action) && this.actionUsable(def.action, 'gold:' + s.tile)) S.slots.add(slot);
                }
                S.chosen.add('power:' + s.tile);
                return cancel();
            case 'creature': {
                const at = p.creatures[s.tile];
                bar.setTitle(this.fmt(_('${you} must choose a Troop in your Districts for ${tile}'), { tile: _(this.defs.tiles[s.tile].name) }));
                this.creatureZones().filter(k => k !== at).forEach(k => S.zones.add(k));
                S.chosen.add('creature:' + s.tile);
                if (at) bar.addActionButton(_('Return to supply'), () => this.act('actPlaceCreature', { tile: s.tile, zone: '' }), { color: 'secondary' });
                return cancel();
            }
            case 'removeCard':
                bar.setTitle(this.fmt(_('${you} must remove 1 Battle card from the game to add ${card}'), { card: _(this.defs.battleCards[this.turnInfo().pending.card].name) }));
                this.hand.battleHand.forEach(c => S.cards.add(String(c)));
                return;
            case 'prayUnits': {
                bar.setTitle(this.fmt(_('Reinforcement Prayer: ${you} may add up to ${n} Unit(s) in your Districts'), { n: this.tileBonus('pray_units') - s.targets.length }));
                if (s.targets.length < this.tileBonus('pray_units') && this.unitTotal() + s.targets.length < this.supply()) {
                    this.homeDistricts().filter(k => (this.state.zones[k].units[this.me] || 0) + s.targets.filter(t => t === k).length < this.troopLimit()).forEach(k => S.zones.add(k));
                }
                s.targets.forEach(k => S.chosen.add('zone:' + k));
                bar.addActionButton(s.targets.length ? this.fmt(_('Pray and add ${n} Unit(s)'), { n: s.targets.length }) : _('Pray without Units'),
                    () => this.act('actPray', { slot: s.slot, reinforce: s.targets.join(',') }));
                return cancel();
            }
            case 'raiseZone':
                bar.setTitle(_('${you} must choose a district for the pyramid'));
                this.raiseZones().forEach(k => S.zones.add(k));
                return cancel();
            case 'raiseColor':
                bar.setTitle(_('${you} must choose the new pyramid\'s color'));
                this.state.colors.filter(c => !this.usedColors().includes(c)).forEach(c => S.colors.add(c));
                S.chosen.add('zone:' + s.zone);
                return cancel();
            case 'raiseLevel':
                bar.setTitle(_('${you} must choose the pyramid level'));
                for (let level = s.from + 1; level <= 4; level++) {
                    const cost = this.raiseCost(s.from, level);
                    S.levels.set(level, cost <= p.pp ? cost : null);
                }
                S.chosen.add('zone:' + s.zone);
                return cancel();
            case 'buyTile':
                bar.setTitle(s.golden === 'acquire' ? _('Twin Ceremony: ${you} must choose a Power tile (+1 PP)') : _('${you} must choose a Power tile to buy'));
                this.buyableTiles(s.slot).forEach(k => S.tiles.add(k));
                return cancel();
            case 'recruitZone':
                bar.setTitle(this.tileBonus('recruit_troops') > 0 ? _('${you} must choose a district or one of your troops to recruit in') : _('${you} must choose a district to recruit in'));
                this.recruitZones().forEach(k => S.zones.add(k));
                return cancel();
            case 'recruitUnits': {
                bar.setTitle(_('${you} must choose how many units to recruit'));
                const home = this.state.zones[s.zone].city === this.me;
                for (let n = 1; n <= this.recruitMax(s.zone); n++) S.units.add(n);
                // A Creature from the supply may join Units recruited in a District without one.
                if (home && !this.creatureAt(this.me, s.zone)) {
                    for (const t of this.supplyCreatures()) S.creatures.add(t);
                }
                if (s.creature) S.chosen.add('creature:' + s.creature);
                S.chosen.add('zone:' + s.zone);
                return cancel();
            }
            case 'moveFrom':
                bar.setTitle(s.golden === 'move_noteleport' ? _('Forced March: ${you} must choose a troop to move (no Teleportation)')
                    : s.golden === 'move_surprise' ? _('Surprise Attack: ${you} must choose a troop to move') : _('${you} must choose a troop to move'));
                Object.keys(this.state.zones).filter(k => (this.state.zones[k].units[this.me] || 0) > 0).forEach(k => S.zones.add(k));
                return cancel();
            case 'moveUnits': {
                bar.setTitle(_('${you} must choose how many units move'));
                const max = this.state.zones[s.from].units[this.me];
                for (let n = 1; n <= max; n++) S.units.add(n);
                S.chosen.add('zone:' + s.from);
                return cancel();
            }
            case 'movePath': {
                const steps = this.moveSteps(s);
                const cost = this.pathCost(s);
                bar.setTitle(s.path.length ? _('${you} may continue the move or confirm it') : _('${you} must choose where the troop moves'));
                steps.forEach((how, k) => S.zones.add(k));
                S.chosen.add('zone:' + s.from);
                s.path.forEach(st => S.chosen.add('zone:' + st.zone));
                const tied = this.creatureAt(this.me, s.from);
                if (tied && !s.path.length) {
                    S.creatures.add(tied);
                    if (s.creature) S.chosen.add('creature:' + tied);
                }
                if (s.path.length) {
                    bar.addActionButton(cost ? this.fmt(_('Confirm move (${cost} PP)'), { cost }) : _('Confirm move'), () => this.act('actMove', {
                        slot: s.slot, from: s.from, path: s.path.map(st => (st.teleport ? '*' : '') + st.zone).join(','), units: s.units, creature: !!s.creature,
                    }), { disabled: !this.moveEndOk(s) });
                    bar.addActionButton(_('Undo step'), () => this.step({ ...s, path: s.path.slice(0, -1) }), { color: 'alert' });
                }
                return cancel();
            }
            case 'reinforceZone':
                bar.setTitle(_('${you} must choose a district for the reinforcement'));
                if (this.unitTotal() < this.supply()) this.freeHomeDistricts().forEach(k => S.zones.add(k));
                return cancel();
            case 'battlePlay': {
                const spy = this.hand.spyCard;
                bar.setTitle(spy ? this.fmt(_('War Council (Spy: your opponent plays ${card}): ${you} must choose the battle card to play'), { card: _(this.defs.battleCards[spy].name) })
                    : _('War Council: ${you} must choose the battle card to play'));
                this.hand.battleHand.forEach(c => S.cards.add(String(c)));
                return;
            }
            case 'battleDiscard':
                bar.setTitle(_('War Council: ${you} must choose a card to discard facedown'));
                this.hand.battleHand.filter(c => c !== s.played).forEach(c => S.cards.add(String(c)));
                S.chosen.add('card:' + s.played);
                return cancel();
            case 'battleDi': {
                const cost = [...s.di].reduce((sum, i) => sum + this.cost(this.defs.diCards[this.hand.diHand[i]].cost), 0);
                bar.setTitle(_('War Council: ${you} may add battle Divine Intervention cards, then confirm'));
                this.hand.diHand.forEach((c, i) => { if (this.defs.diCards[c]?.phase === 'battle') S.di.add(i); });
                s.di.forEach(i => S.chosen.add('di:' + i));
                S.chosen.add('card:' + s.played);
                S.chosen.add('discard:' + s.discarded);
                bar.addActionButton(cost ? this.fmt(_('Confirm (${cost} PP)'), { cost }) : _('Confirm'), () => this.act('actBattleChoice', {
                    played: s.played, discarded: s.discarded, di: [...s.di].map(i => this.hand.diHand[i]).join(','),
                }), { disabled: cost > p.pp });
                return cancel();
            }
            case 'wound':
            case 'vision': {
                const need = s.mode === 'vision' ? this.args.task?.count || 0 : null;
                bar.setTitle(s.mode === 'vision' ? this.fmt(_('Vision: ${you} must discard ${n} DI card(s)'), { n: need })
                    : _('Divine Wound: ${you} may discard DI cards for +1 Strength each'));
                this.hand.diHand.forEach((c, i) => { if (c !== 'diversion') S.di.add(i); });
                s.di.forEach(i => S.chosen.add('di:' + i));
                const cards = [...s.di].map(i => this.hand.diHand[i]).join(',');
                if (s.mode === 'vision') {
                    bar.addActionButton(this.fmt(_('Discard ${n} card(s)'), { n: s.di.size }), () => this.act('actNightPower', { choice: cards }), { disabled: s.di.size !== need });
                } else {
                    bar.addActionButton(s.di.size ? this.fmt(_('Discard ${n} card(s): +${n} Strength'), { n: s.di.size }) : _('Discard nothing'), () => this.act('actWound', { cards }));
                }
                return;
            }
            case 'handOfGod': {
                bar.setTitle(_('Hand of God: ${you} may raise one of your Pyramids by 1 level for free'));
                Object.keys(this.args.options || {}).forEach(k => S.zones.add(k));
                bar.addActionButton(_('Skip'), () => this.act('actNightPower', { choice: '' }), { color: 'secondary' });
                return;
            }
            case 'handOfGodColor':
                bar.setTitle(_('Hand of God: ${you} must choose the new pyramid\'s color'));
                (this.args.options[s.zone] || []).forEach(c => S.colors.add(c));
                S.chosen.add('zone:' + s.zone);
                return cancel();
            case 'aftermath': {
                const step = this.args.step;
                if (step === 'loser') {
                    bar.setTitle(this.args.canRetreat ? _('${you} lost the battle: recall or retreat your troop') : _('${you} lost the battle and must recall your troop'));
                    bar.addActionButton(_('Recall troop'), () => this.act('actAftermath', { choice: 'recall', retreatTo: '' }));
                    if (this.args.canRetreat) bar.addActionButton(_('Retreat'), () => this.act('actAftermath', { choice: 'retreat', retreatTo: '' }), { color: 'secondary' });
                } else if (step === 'retreat') {
                    bar.setTitle(_('${you} must choose where the losing troop retreats'));
                    (this.args.retreatOptions || []).forEach(k => S.zones.add(k));
                } else {
                    bar.setTitle(_('${you} won the battle: keep your troop or recall it'));
                    bar.addActionButton(_('Stay'), () => this.act('actAftermath', { choice: 'stay', retreatTo: '' }));
                    bar.addActionButton(_('Recall troop'), () => this.act('actAftermath', { choice: 'recall', retreatTo: '' }), { color: 'secondary' });
                }
                return;
            }
            case 'night': {
                const vets = this.args.veterans ?? p.veterans;
                const left = vets - s.pray - s.draw - s.recruit;
                bar.setTitle(this.fmt(_('Night: ${you} have ${left} veteran(s) to spend (PP ${pray}, DI ${draw}, units ${recruit})'), { left, pray: s.pray, draw: s.draw, recruit: s.recruit }));
                if (this.args.canOffer) S.zones.add('sanctuary');
                if (this.args.canDelta) S.zones.add('delta');
                if (s.offer) S.chosen.add('zone:sanctuary');
                if (s.delta) S.chosen.add('zone:delta');
                if (vets > 0) {
                    if (left > 0) { S.night.add('pray'); S.night.add('draw'); if (this.freeHomeDistricts().length) S.night.add('recruit'); }
                    if (s.pray + s.draw + s.recruit) S.night.add('reset');
                }
                if (s.recruit > 0) {
                    this.freeHomeDistricts().forEach(k => S.zones.add(k));
                    if (s.district) S.chosen.add('zone:' + s.district);
                }
                bar.addActionButton(_('Confirm night'), () => this.act('actNightChoice', {
                    offer: s.offer, delta: s.delta, pray: s.pray, draw: s.draw, recruit: s.recruit, district: s.district,
                }), { disabled: s.recruit > 0 && !s.district });
                return;
            }
            case 'destiny': {
                bar.setTitle(_('${you} must choose a turn order space (space 1 plays first)'));
                (this.state.destiny?.positions || []).forEach((owner, i) => {
                    if (owner === null) S.destiny.add(i);
                });
                return;
            }
        }
    }

    // ---------------------------------------------------------------- clicks

    onRootClick(event) {
        const el = event.target.closest('[data-zone],[data-slot],[data-tile],[data-card],[data-di],[data-creature],[data-power],[data-color],[data-level],[data-units],[data-night],[data-destiny],[data-silver]');
        if (!el || !el.classList.contains('selectable') || !this.active || !this.sel) return;
        const s = this.sel;
        if (el.dataset.silver !== undefined) return this.step({ mode: 'choose', silver: true });
        if (el.dataset.power) {
            const tile = el.dataset.power;
            const actions = GOLDEN_ACTIONS[this.defs.tiles[tile].golden].filter(action => this.actionUsable(action, 'gold:' + tile));
            return actions.length === 1 ? this.startAction(actions[0], 'gold:' + tile) : this.step({ mode: 'goldAction', tile });
        }
        if (el.dataset.color) return s.mode === 'handOfGodColor'
            ? this.act('actNightPower', { choice: s.zone + ':' + el.dataset.color })
            : this.step({ ...s, mode: 'raiseLevel', color: el.dataset.color, from: 0 });
        if (el.dataset.level) return this.act('actRaise', { slot: s.slot, zone: s.zone, color: s.color, level: Number(el.dataset.level) });
        if (el.dataset.units) return s.mode === 'recruitUnits'
            ? this.act('actRecruit', { slot: s.slot, zone: s.zone, units: Number(el.dataset.units), creature: s.creature || '' })
            : this.step({ ...s, mode: 'movePath', units: Number(el.dataset.units), path: [], creature: !!this.creatureAt(this.me, s.from) });
        if (el.dataset.night) return this.clickNight(el.dataset.night);
        if (el.dataset.destiny !== undefined) return this.act('actDestiny', { position: Number(el.dataset.destiny) });
        if (el.dataset.slot) return this.clickSlot(el.dataset.slot);
        if (el.dataset.tile) return s.mode === 'starter' ? this.act('actStarterTile', { tile: el.dataset.tile }) : this.act('actBuy', { slot: s.slot, tile: el.dataset.tile });
        if (el.dataset.creature) {
            const tile = el.dataset.creature;
            if (s.mode === 'recruitUnits') return this.step({ ...s, creature: s.creature === tile ? '' : tile });
            if (s.mode === 'movePath') return this.step({ ...s, creature: !s.creature });
            return this.step({ mode: 'creature', tile });
        }
        if (el.dataset.di !== undefined) return this.clickDi(Number(el.dataset.di));
        if (el.dataset.card) return this.clickCard(Number(el.dataset.card));
        if (el.dataset.zone) return this.clickZone(el.dataset.zone);
    }
    clickSlot(slot) {
        const full = this.sel.mode === 'goldAction' ? 'gold:' + this.sel.tile : (this.sel.silver ? 'silver:' + slot : slot);
        this.startAction(this.defs.slots[slot].action, full);
    }
    clickNight(choice) {
        const s = this.sel;
        if (choice === 'reset') return this.step({ ...s, pray: 0, draw: 0, recruit: 0, district: '' });
        return this.step({ ...s, [choice]: s[choice] + 1 });
    }
    clickZone(key) {
        const s = this.sel;
        switch (s.mode) {
            case 'creature': return this.act('actPlaceCreature', { tile: s.tile, zone: key });
            case 'prayUnits': return this.step({ ...s, targets: [...s.targets, key] });
            case 'raiseZone': {
                const p = this.state.zones[key].pyramid;
                return p ? this.step({ ...s, mode: 'raiseLevel', zone: key, color: p.color, from: p.level }) : this.step({ ...s, mode: 'raiseColor', zone: key });
            }
            case 'recruitZone': return this.step({ ...s, mode: 'recruitUnits', zone: key, creature: '' });
            case 'moveFrom': {
                const units = this.state.zones[key].units[this.me];
                return this.step({ ...s, from: key, mode: units > 1 ? 'moveUnits' : 'movePath', units, path: [], creature: !!this.creatureAt(this.me, key) });
            }
            case 'movePath': {
                const how = this.moveSteps(s).get(key);
                return this.step({ ...s, path: [...s.path, { zone: key, teleport: how === 'teleport' }] });
            }
            case 'reinforceZone': return this.act('actDayCard', { card: 'reinforce', zone: key });
            case 'aftermath': return this.act('actAftermath', { choice: 'retreat', retreatTo: key });
            case 'night': {
                if (key === 'sanctuary') return this.step({ ...s, offer: !s.offer });
                if (key === 'delta') return this.step({ ...s, delta: !s.delta });
                return this.step({ ...s, district: key });
            }
            case 'handOfGod': {
                const colors = this.args.options[key] || [];
                return colors.length === 1 ? this.act('actNightPower', { choice: key + ':' + colors[0] }) : this.step({ mode: 'handOfGodColor', zone: key });
            }
        }
    }
    clickCard(card) {
        const s = this.sel;
        if (s.mode === 'removeCard') return this.act('actRemoveCard', { card });
        if (s.mode === 'battlePlay') return this.step({ mode: 'battleDiscard', played: card });
        if (s.mode === 'battleDiscard') return this.step({ mode: 'battleDi', played: s.played, discarded: card, di: new Set() });
    }
    clickDi(index) {
        const s = this.sel;
        const card = this.hand.diHand[index];
        if (s.mode === 'choose') {
            if (card === 'blessing') return this.act('actDayCard', { card, zone: '' });
            if (card === 'reinforce') return this.step({ mode: 'reinforceZone' });
        }
        if (s.mode === 'battleDi' || s.mode === 'wound' || s.mode === 'vision') {
            const di = new Set(s.di);
            di.has(index) ? di.delete(index) : di.add(index);
            this.step({ ...s, di });
        }
    }

    // ---------------------------------------------------------------- rendering

    cls(kind, key) {
        const S = this.selectable || {};
        const set = { zone: S.zones, slot: S.slots, tile: S.tiles, card: S.cards, di: S.di, creature: S.creatures, power: S.powers, color: S.colors, units: S.units, night: S.night, destiny: S.destiny }[kind];
        const val = kind === 'di' ? Number(key) : (kind === 'card' ? String(key) : key);
        return (set?.has(val) ? ' selectable' : '') + (S.chosen?.has(kind + ':' + key) ? ' selected' : '') + (S.chosen?.has('discard:' + key) && kind === 'card' ? ' discarded' : '');
    }

    render() {
        if (!this.state || !document.getElementById('kemet-root')) return;
        this.renderMap();
        this.renderPanels();
        for (const id of Object.keys(this.state.players)) this.renderBoard(id);
        this.renderHand();
        this.renderMarket();
    }
    boardOrder() { return this.state.order.filter(id => this.state.players[id]); }
    art(id) { return PORTRAITS[this.colorHex(id).toLowerCase()] ?? 0; }
    phaseText() {
        const s = this.state;
        if (s.starter) return _('Setup: each player chooses a Level 1 Power tile');
        if (s.nightPowers) return this.fmt(_('Round ${n}: Night Powers'), { n: s.round });
        if (s.destiny) return this.fmt(_('Round ${n}: Destiny (choose the turn order)'), { n: s.round });
        if (s.night) return this.fmt(_('Round ${n}: Night'), { n: s.round });
        return this.fmt(_('Round ${n}: Day, action ${a} of 5'), { n: s.round, a: Math.min(5, Math.floor(s.turnIndex / s.order.length) + 1) });
    }

    renderMap() {
        const s = this.state;
        const W = 1381, H = 1700;
        const closed = Object.values(s.citySites || {}).filter(site => site.status !== 'active')
            .map(site => site.districts.map(([x, y]) => `<span class="kemet-closed" style="left:${this.pct(x, W)};top:${this.pct(y, H)}" aria-hidden="true"></span>`).join('')).join('');
        const pins = Object.entries(s.zones).map(([key, z]) => {
            const troops = Object.entries(z.units).filter(([, n]) => n > 0);
            const owner = troops[0]?.[0];
            const troopHtml = troops.map(([id, n]) => `<b class="kemet-troop" style="--player:#${this.colorHex(id)}" data-player-number="${s.order.indexOf(id) + 1}" aria-label="${this.esc(this.fmt(_('${player}: ${n} units'), { player: this.name(id), n }))}">${n}</b>`).join('');
            const pyr = z.pyramid ? `<i class="kemet-pyramid ${z.pyramid.color}"><span class="kemet-sym">${SYMBOLS[z.pyramid.color]}</span>${z.pyramid.level}</i>` : '';
            const battle = s.battle?.zone === key ? ' battle' : '';
            // Creatures tied to a Troop: a disc of their Power tile art.
            const creatures = Object.keys(s.players).map(id => [id, this.creatureAt(id, key)]).filter(([, t]) => t)
                .map(([id, t], i) => `<i class="kemet-creature" style="${this.tileArt(t)};--player:#${this.colorHex(id)};--i:${i}" aria-hidden="true"></i>`).join('');
            return `<button type="button" id="kemet-zone-${key}" class="kemet-pin ${z.kind}${battle}${troops.length ? '' : ' empty'}${this.cls('zone', key)}" data-zone="${key}"
                style="left:${this.pct(z.x, W)};top:${this.pct(z.y, H)};--owner:${owner ? '#' + this.colorHex(owner) : (z.city ? '#' + this.colorHex(z.city) : '#765b37')}"
                aria-label="${this.esc(this.zoneName(key))}">${troopHtml}${pyr}${creatures}</button>`;
        }).join('');
        // Round / phase marker: sun by day, moon by night, ankh during Destiny; the number is the round.
        const icon = s.destiny ? '☥' : (s.night ? '☾' : '☀');
        const dayStep = s.night || s.destiny ? 0 : Math.min(5, Math.floor(s.turnIndex / s.order.length) + 1);
        const pips = [1, 2, 3, 4, 5].map(i => `<i class="${i < dayStep ? 'done' : (i === dayStep ? 'now' : '')}"></i>`).join('');
        const marker = `<div id="kemet-round" class="kemet-round-marker${s.night || s.destiny ? ' night' : ''}"><span>${icon}</span><b>${s.round}</b><span class="kemet-pips">${pips}</span></div>`;
        const components = this.renderMapComponents();
        const destiny = s.destiny ? `<div class="kemet-destiny-track" role="group" aria-label="${this.esc(_('Turn order'))}">${s.destiny.positions.map((owner, i) =>
            `<button type="button" id="kemet-destiny-${i}" class="kemet-destiny-space${this.cls('destiny', i)}" data-destiny="${i}" style="${owner === null ? '' : '--player:#' + this.colorHex(owner)}" aria-label="${this.esc(this.fmt(_('Turn order space ${n}'), { n: i + 1 }))}">${owner === null ? i + 1 : `<i class="kemet-action-token"></i><span>${i + 1}</span>`}</button>`).join('')}</div>` : '';
        document.getElementById('kemet-map').innerHTML = closed + pins + marker + components + destiny;
        for (const key of Object.keys(s.zones)) this.tooltip('kemet-zone-' + key, this.zoneTooltip(key));
        this.tooltip('kemet-round', this.esc(this.phaseText()));
        if (s.destiny) s.destiny.positions.forEach((owner, i) => this.tooltip('kemet-destiny-' + i, this.esc(owner === null
            ? this.fmt(_('Choose turn order space ${n}'), { n: i + 1 })
            : this.fmt(_('Turn order space ${n}: ${player}'), { n: i + 1, player: this.name(owner) }))));
        if (this.mapComponentTips) for (const [id, tip] of this.mapComponentTips) this.tooltip(id, tip);
    }
    renderMapComponents() {
        this.mapComponentTips = [];
        if (!this.active || !this.sel) return '';
        const s = this.sel, S = this.selectable;
        const zone = s.mode === 'moveUnits' ? s.from : s.zone;
        if (!zone || !this.state.zones[zone]) return '';
        let pieces = '';
        if (s.mode === 'raiseColor' || s.mode === 'handOfGodColor') {
            pieces = [...S.colors].map(color => {
                const id = 'kemet-color-' + color;
                this.mapComponentTips.push([id, this.esc(this.fmt(_('${color} pyramid'), { color: this.colorName(color) }))]);
                return `<button type="button" id="${id}" class="kemet-component pyramid ${color}${this.cls('color', color)}" data-color="${color}" aria-label="${this.esc(this.colorName(color))}"><span class="kemet-sym">${SYMBOLS[color]}</span></button>`;
            }).join('');
        } else if (s.mode === 'raiseLevel') {
            pieces = [...S.levels].map(([level, cost]) => {
                const id = 'kemet-level-' + level;
                this.mapComponentTips.push([id, this.esc(this.fmt(_('Raise to level ${level} for ${cost} PP'), { level, cost: cost ?? this.raiseCost(s.from, level) }))]);
                return `<button type="button" id="${id}" class="kemet-component pyramid ${s.color}${cost === null ? ' unavailable' : ' selectable'}" data-level="${level}" ${cost === null ? 'disabled' : ''} aria-label="${this.esc(this.fmt(_('Level ${level}, ${cost} PP'), { level, cost: cost ?? this.raiseCost(s.from, level) }))}"><span class="kemet-sym">${SYMBOLS[s.color]}</span><b>${level}</b><small>☥${cost ?? this.raiseCost(s.from, level)}</small></button>`;
            }).join('');
        } else if (s.mode === 'recruitUnits' || s.mode === 'moveUnits') {
            pieces = [...S.units].map(n => {
                const id = 'kemet-units-' + n;
                const cost = s.mode === 'recruitUnits' ? this.recruitCost(n) : null;
                this.mapComponentTips.push([id, this.esc(s.mode === 'recruitUnits'
                    ? this.fmt(_('Recruit ${n} units for ${cost} PP'), { n, cost })
                    : this.fmt(_('Move ${n} units'), { n }))]);
                return `<button type="button" id="${id}" class="kemet-component unit${this.cls('units', n)}" data-units="${n}" aria-label="${this.esc(s.mode === 'recruitUnits' ? this.fmt(_('Recruit ${n} units'), { n }) : this.fmt(_('Move ${n} units'), { n }))}"><i class="kemet-action-token"></i><b>${n}</b>${cost === null ? '' : `<small>☥${cost}</small>`}</button>`;
            }).join('');
        }
        if (!pieces) return '';
        const z = this.state.zones[zone];
        const x = Math.max(23, Math.min(77, z.x / 1381 * 100));
        const above = z.y / 1700 > .75;
        return `<div class="kemet-map-components${above ? ' above' : ''}" style="left:${x}%;top:${this.pct(z.y, 1700)}" role="group" aria-label="${this.esc(this.zoneName(zone))}">${pieces}</div>`;
    }
    zoneTooltip(key) {
        const z = this.state.zones[key];
        const lines = [`<strong>${this.esc(this.zoneName(key))}</strong>`];
        const troops = Object.entries(z.units).filter(([, n]) => n > 0);
        lines.push(troops.length ? troops.map(([id, n]) => {
            const c = this.creatureAt(id, key);
            return this.esc(this.fmt(_('${player}: ${n} unit(s)'), { player: this.name(id), n })) + (c ? ' + ' + this.esc(_(this.defs.tiles[c].name)) : '');
        }).join('<br>') : this.esc(_('No troops')));
        if (z.pyramid) lines.push(this.esc(this.fmt(_('${color} pyramid, level ${level}'), { color: this.colorName(z.pyramid.color), level: z.pyramid.level })));
        if (z.kind === 'temple') lines.push(this.esc(z.prayer ? this.fmt(_('Temple: 1 FP while controlled, +${n} PP each night'), { n: z.prayer }) : _('Delta Temple: 1 FP while controlled; sacrifice 1 unit at night for 5 PP')));
        if (z.kind === 'sanctuary') lines.push(this.esc(_('Sacrifice 2 units at night for 1 Divine FP')));
        if (z.obelisk) lines.push(this.esc(_('Obelisk: teleport destination')));
        if (z.harbor === 'trade') lines.push(this.esc(_('Trading harbor')));
        if (z.harbor === 'military') lines.push(this.esc(_('Military harbor')));
        const b = this.state.battle;
        if (b?.zone === key) {
            lines.push(this.esc(this.fmt(_('Battle: ${attacker} attacks ${defender}'), { attacker: this.name(b.attacker), defender: this.name(b.defender) })));
            if (b.totals) for (const [id, t] of Object.entries(b.totals)) lines.push(this.esc(this.fmt(_('${player}: strength ${n}'), { player: this.name(id), n: t.strength })));
        }
        return lines.join('<br>');
    }

    renderPanels() {
        const s = this.state;
        for (const [id, p] of Object.entries(s.players)) {
            const el = document.getElementById('kemet-panel-' + id);
            if (!el) continue;
            const pos = s.destiny ? s.destiny.positions.indexOf(id) : s.order.indexOf(id);
            const current = !s.night && !s.destiny && s.order[s.turnIndex % s.order.length] === id;
            const tiles = p.tiles.map(t => `<span id="kemet-ptile-${id}-${t}" class="kemet-mini-tile ${this.defs.tiles[t].color}" style="${this.tileArt(t)}"><span class="kemet-sym">${SYMBOLS[this.defs.tiles[t].color]}</span></span>`).join('');
            el.innerHTML = `
                <span class="kemet-portrait art-${this.art(id)}" aria-hidden="true"><b id="kemet-order-${id}" class="kemet-order-pos${current ? ' current' : ''}">${pos >= 0 ? pos + 1 : '·'}</b></span>
                <div class="kemet-stats">
                    <span id="kemet-pp-${id}" class="kemet-stat pp">☥ ${p.pp}</span>
                    <span id="kemet-fp-${id}" class="kemet-stat fp">★ ${p.permanent}</span>
                    <span id="kemet-vet-${id}" class="kemet-stat vet">⚑ ${p.veterans}</span>
                    <span id="kemet-di-${id}" class="kemet-stat di">🂠 ${p.diCount}</span>
                    <span id="kemet-bc-${id}" class="kemet-stat bc">⚔ ${p.battleCount}</span>
                </div>
                <div class="kemet-mini-tiles">${tiles}</div>`;
            this.tooltip('kemet-order-' + id, this.esc(s.destiny
                ? (pos >= 0 ? this.fmt(_('Destiny: chose turn order space ${n}'), { n: pos + 1 }) : _('Destiny: still to choose a turn order space'))
                : this.fmt(_('Turn order: ${n}'), { n: pos + 1 })));
            this.tooltip('kemet-pp-' + id, this.esc(_('Prayer points (max 11)')));
            this.tooltip('kemet-fp-' + id, this.esc(this.fmt(_('Permanent FP: ${n} (total score ${score})'), { n: p.permanent, score: p.score })));
            this.tooltip('kemet-vet-' + id, this.esc(_('Veteran tokens, spent during the Night')));
            this.tooltip('kemet-di-' + id, this.esc(_('Divine Intervention cards in hand')));
            const revealed = (p.battlePlayed || []).map(c => _(this.defs.battleCards[c].name)).join(', ') || _('none');
            this.tooltip('kemet-bc-' + id, this.esc(this.fmt(_('Battle cards in hand. Revealed this cycle: ${cards}'), { cards: revealed })));
            for (const t of p.tiles) this.tooltip(`kemet-ptile-${id}-${t}`, this.tileTooltip(t));
        }
    }
    /** Inline background position of a tile in img/power-tiles.jpg (8 x 8 grid). */
    tileArt(t) {
        const art = this.defs.tiles[t].art;
        return `background-position:${(art % 8) * 100 / 7}% ${Math.floor(art / 8) * 100 / 7}%`;
    }
    tileTooltip(t) {
        const tile = this.defs.tiles[t];
        const extra = [];
        const c = tile.creature;
        if (c) {
            const stats = [this.fmt(_('+${n} Land move'), { n: c.move }), this.fmt(_('+${n} Strength'), { n: c.strength })];
            if (c.damage) stats.push(this.fmt(_('+${n} Damage'), { n: c.damage }));
            if (c.shield) stats.push(this.fmt(_('+${n} Shield'), { n: c.shield }));
            extra.push(this.fmt(_('Creature: ${stats}. It starts in your supply: before an action, tie it to a Troop in one of your Districts.'), { stats: stats.join(', ') }));
        }
        if (tile.golden) extra.push(_('Golden Action: play your gold Action token (once per Round) during your turn, in addition to your colored token.'));
        if (tile.mods?.silver) extra.push(_('Silver Action token: during your turn, play it on an empty action space in addition to your colored token, once per Round.'));
        if (tile.card) {
            const d = this.defs.battleCards[tile.card];
            extra.push(this.fmt(_('Special Battle card ${card}: Strength ${s} · Damage ${d} · Unblockable ${u} · Defense ${b}'), { card: _(d.name), s: d.strength, d: d.damage, u: d.pierce, b: d.shield })
                + (d.unverified ? ' ' + _('(provisional values: the card is not printed in the supplied rules)') : ''));
        }
        return `<div class="kemet-tile-tip"><span class="kemet-tile-art" style="${this.tileArt(t)}"></span><div><strong>${this.esc(_(tile.name))}</strong><br>`
            + `${this.esc(this.fmt(_('${color} level ${level} · cost ${level} PP'), { color: this.colorName(tile.color), level: tile.level }))}<br>${this.esc(_(tile.text))}`
            + extra.map(line => `<br><em>${this.esc(line)}</em>`).join('') + '</div></div>';
    }
    battleCardTooltip(c) {
        const d = this.defs.battleCards[c];
        return (d.tile ? `<span class="kemet-tile-art" style="${this.tileArt(d.tile)}"></span><br>` : '') + `<strong>${this.esc(_(d.name))}</strong><br>`
            + this.esc(this.fmt(_('Strength ${s} · Damage ${d} · Unblockable ${u} · Defense ${b}'), { s: d.strength, d: d.damage, u: d.pierce, b: d.shield }))
            + (d.unverified ? `<br><em>${this.esc(_('Provisional values: this Special Battle card is not printed in the supplied rules.'))}</em>` : '');
    }

    /** One Individual board: the divinity's art with action tokens on the spaces they occupy. */
    renderBoard(id) {
        const el = document.getElementById('kemet-pboard-' + id);
        const p = this.state.players[id];
        if (!el || !p) return;
        const mine = id === this.me;
        const owned = Object.values(this.state.zones).filter(z => z.pyramid?.owner === id).map(z => z.pyramid.color);
        const spaces = [];
        for (const [slot, [x, y]] of Object.entries(BOARD_SPACES)) spaces.push([slot, x, y, '', '']);
        this.state.colors.forEach((color, i) => {
            const [x, y] = BUY_SPACES[i];
            spaces.push(['buy_' + color, x, y, ' buy ' + color, owned.includes(color) ? `<i class="kemet-buy-token ${color}"><span class="kemet-sym">${SYMBOLS[color]}</span></i>` : '']);
        });
        const silverSlots = p.silverSlots || [];
        const html = spaces.map(([slot, x, y, extra, token]) => {
            const used = p.slots.includes(slot), silver = silverSlots.includes(slot);
            const sel = mine ? this.cls('slot', slot) : '';
            return `<button type="button" id="kemet-slot-${id}-${slot}" class="kemet-space${extra}${used || silver ? ' used' : ''}${sel}" data-slot="${mine ? slot : ''}" style="left:${x}%;top:${y}%"
                aria-label="${this.esc(this.slotLabel(slot))}">${token}${used ? '<i class="kemet-action-token"></i>' : ''}${silver ? '<i class="kemet-action-token silver"></i>' : ''}</button>`;
        }).join('');
        const left = 5 - p.slots.length;
        const silverLeft = Math.max(0, p.tiles.reduce((n, t) => n + (this.defs.tiles[t].mods?.silver || 0), 0) - silverSlots.length);
        const reserve = Array.from({ length: Math.max(0, left) }, () => '<i class="kemet-action-token"></i>').join('')
            + Array.from({ length: silverLeft }, (_, i) => `<button type="button" class="kemet-reserve-silver${mine && this.selectable?.silver && i === 0 ? ' selectable' : ''}" data-silver aria-label="${this.esc(_('Use silver Action token'))}"><i class="kemet-action-token silver"></i></button>`).join('');
        const night = mine && this.sel?.mode === 'night' ? `<div class="kemet-night-tokens" role="group" aria-label="${this.esc(_('Spend veteran tokens'))}">${[
            ['pray', '☥', s => s.pray, _('Gain 1 prayer point')],
            ['draw', '🂠', s => s.draw, _('Draw 1 Divine Intervention card')],
            ['recruit', '⚑', s => s.recruit, _('Recruit 1 unit in a district')],
            ['reset', '↶', () => 0, _('Reset veteran choices')],
        ].map(([key, icon, count, tip]) => `<button type="button" id="kemet-night-${key}" class="kemet-night-token${this.cls('night', key)}" data-night="${key}" aria-label="${this.esc(tip)}">${icon}${count(this.sel) ? `<b>${count(this.sel)}</b>` : ''}</button>`).join('')}</div>` : '';
        // Gold Action token: on the Divine Power space while unused this Round.
        const gold = `<span id="kemet-gold-${id}" class="kemet-space gold${p.goldUsed ? ' used' : ''}" style="left:${GOLD_SPACE[0]}%;top:${GOLD_SPACE[1]}%">${p.goldUsed ? '' : '<i class="kemet-action-token gold"></i>'}</span>`;
        el.className = `kemet-pboard art-${this.art(id)}${mine ? ' mine' : ''}`;
        el.style.setProperty('--player', '#' + this.colorHex(id));
        el.innerHTML = `${html}${gold}${night}
            <div id="kemet-pp-track-${id}" class="kemet-pp-marker">${p.pp}</div>
            <div id="kemet-reserve-${id}" class="kemet-reserve">${reserve}</div>`;
        for (const [slot] of spaces) this.tooltip(`kemet-slot-${id}-${slot}`, this.esc(this.slotLabel(slot)) + '<br>' + this.esc(_('Equilibrium: by the end of the day each player needs an Action token on each of the three levels of the pyramid.')));
        this.tooltip('kemet-pp-track-' + id, this.esc(this.fmt(_('${player}: ${n} prayer points (max 11)'), { player: this.name(id), n: p.pp })));
        this.tooltip('kemet-reserve-' + id, this.esc(this.fmt(_('${n} Action token(s) left today'), { n: left })) + (silverLeft ? '<br>' + this.esc(this.fmt(_('${n} silver Action token(s) left this Round'), { n: silverLeft })) : ''));
        const golden = p.tiles.filter(t => this.defs.tiles[t].golden).map(t => _(this.defs.tiles[t].name));
        this.tooltip('kemet-gold-' + id, this.esc(p.goldUsed ? _('Gold Action token: already played this Round') : _('Gold Action token: once per Round, resolve the Golden Action of one of your Power tiles'))
            + '<br>' + this.esc(golden.length ? this.fmt(_('Golden Actions: ${tiles}'), { tiles: golden.join(', ') }) : _('No Golden Action tile yet')));
        if (night) for (const [key, tip] of [['pray', _('Spend one veteran to gain 1 PP')], ['draw', _('Spend one veteran to draw 1 DI card')], ['recruit', _('Spend one veteran to recruit 1 unit')], ['reset', _('Reset veteran choices')]]) this.tooltip('kemet-night-' + key, this.esc(tip));
    }
    slotLabel(slot) {
        const d = this.defs.slots[slot];
        const level = { 3: _('top level'), 2: _('middle level'), 1: _('bottom level') }[d.tier];
        let text = this.fmt(_('${action} (${level})'), { action: _(this.defs.actionNames[d.action]), level });
        if (d.action === 'buy') text += ' · ' + this.fmt(_('${color} space'), { color: this.colorName(slot.slice(4)) });
        return text;
    }

    renderHand() {
        const el = document.getElementById('kemet-hand');
        if (!el) return;
        const cards = this.hand.battleHand.map(c => {
            const d = this.defs.battleCards[c];
            // Special Battle cards use the art of the Power tile that grants them.
            const art = d.tile ? ` special" style="${this.tileArt(d.tile)}` : '';
            return `<button type="button" id="kemet-card-${c}" class="kemet-card battle${art}${this.cls('card', c)}" data-card="${c}" aria-label="${this.esc(_(d.name))}">
                <span class="kemet-card-stats"><span>⚔${d.strength}</span>${d.damage ? `<span>✦${d.damage}</span>` : ''}${d.pierce ? `<span>➶${d.pierce}</span>` : ''}${d.shield ? `<span>⛨${d.shield}</span>` : ''}</span></button>`;
        }).join('');
        // Creature tray: every Creature I own, dimmed while tied to a Troop on the map.
        const creatures = Object.entries(this.me_()?.creatures || {}).map(([t, at]) =>
            `<button type="button" id="kemet-creature-${t}" class="kemet-creature-tile${at ? ' placed' : ''}${this.cls('creature', t)}" data-creature="${t}" style="${this.tileArt(t)}" aria-label="${this.esc(_(this.defs.tiles[t].name))}"></button>`).join('');
        const di = this.hand.diHand.map((c, i) => {
            const d = this.defs.diCards[c];
            return `<button type="button" id="kemet-di-card-${i}" class="kemet-card di ${d.phase}${this.cls('di', i)}" data-di="${i}" aria-label="${this.esc(_(d.name))}">
                <span class="kemet-card-icon">${d.phase === 'day' ? '☀' : '⚔'}</span>${d.cost ? `<span class="kemet-card-cost">☥${d.cost}</span>` : ''}</button>`;
        }).join('');
        const powers = (this.me_()?.tiles || []).filter(t => this.defs.tiles[t].golden).map(t =>
            `<button type="button" id="kemet-power-${t}" class="kemet-power-tile${this.cls('power', t)}" data-power="${t}" style="${this.tileArt(t)}" aria-label="${this.esc(_(this.defs.tiles[t].name))}"></button>`).join('');
        el.innerHTML = cards + di + creatures + powers;
        for (const c of this.hand.battleHand) this.tooltip('kemet-card-' + c, this.battleCardTooltip(c));
        for (const [t, at] of Object.entries(this.me_()?.creatures || {})) {
            this.tooltip('kemet-creature-' + t, this.tileTooltip(t) + '<br>' + this.esc(at ? this.fmt(_('Tied to your Troop in ${zone}'), { zone: this.zoneName(at) }) : _('In your supply')));
        }
        for (const t of this.me_()?.tiles || []) if (this.defs.tiles[t].golden) this.tooltip('kemet-power-' + t, this.tileTooltip(t));
        this.hand.diHand.forEach((c, i) => {
            const d = this.defs.diCards[c];
            this.tooltip('kemet-di-card-' + i, `<strong>${this.esc(_(d.name))}</strong><br>${this.esc(d.phase === 'day' ? _('Day card') : _('Battle card'))}<br>${this.esc(_(d.text))}<br>${this.esc(this.fmt(_('Cost: ${n} PP'), { n: d.cost }))}`);
        });
    }

    renderMarket() {
        const s = this.state;
        // One row per color in play, ascending level; only tiles in this game's market (2 players: no Act of God, no duplicates).
        // Duplicate level-1 tiles share one stack with a count of the copies still available.
        const stacks = new Map();
        for (const [k, t] of Object.entries(this.defs.tiles)) {
            if (!(k in s.market) || !s.colors.includes(t.color)) continue;
            if (!stacks.has(t.stack)) stacks.set(t.stack, []);
            stacks.get(t.stack).push(k);
        }
        const tips = [];
        const rows = s.colors.map(color => {
            const tiles = [...stacks.entries()].filter(([stack]) => this.defs.tiles[stack].color === color).map(([stack, keys]) => {
                const left = keys.filter(k => s.market[k]);
                const count = `<b class="kemet-tile-count">${left.length}</b>`;
                if (!left.length) return `<span class="kemet-tile gone ${color}" style="${this.tileArt(stack)}" aria-hidden="true">${count}</span>`;
                const k = left.find(key => this.cls('tile', key)) || left[0];
                tips.push(k);
                return `<button type="button" id="kemet-tile-${k}" class="kemet-tile ${color}${this.cls('tile', k)}" data-tile="${k}" style="${this.tileArt(k)}" aria-label="${this.esc(_(this.defs.tiles[k].name))}"><span class="kemet-sym">${SYMBOLS[color]}</span>${count}</button>`;
            }).join('');
            return `<div class="kemet-tile-row ${color}">${tiles}</div>`;
        }).join('');
        document.getElementById('kemet-market').innerHTML = rows;
        for (const k of tips) this.tooltip('kemet-tile-' + k, this.tileTooltip(k) + `<br>${this.esc(this.fmt(_('Available: ${n}'), { n: stacks.get(this.defs.tiles[k].stack).filter(key => s.market[key]).length }))}`);
    }

    // ---------------------------------------------------------------- notifications

    notif_kemetState(args) {
        this.state = args.kemet;
        if (this.active && this.sel) this.refresh(); else this.render();
    }
    notif_hand(args) {
        this.hand = { diHand: args.diHand, battleHand: args.battleHand, spyCard: args.spyCard ?? null };
        if (this.active && this.sel) this.refresh(); else this.render();
    }
    async notif_move(args) {
        await this.animateTroop(args.mover ?? args.player_id, args.from, args.zone, args.units);
    }
    async notif_battleStart(args) { await this.pulse(args.zone, 'kemet-flash-battle'); }
    async notif_battleResolved(args) { await this.pulse(args.zone, 'kemet-flash-battle'); }
    async notif_recruit(args) { await this.pulse(args.zone, 'kemet-flash'); }
    async notif_raise(args) { await this.pulse(args.zone, 'kemet-flash'); }
    async notif_recall(args) { await this.pulse(args.zone, 'kemet-flash'); }
    async notif_creature(args) { if (args.zone) await this.pulse(args.zone, 'kemet-flash'); }
    notif_pray() {}
    notif_buy() {}
    notif_dayCard() {}
    notif_log() {}

    wait(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
    animationsOn() { return this.bga.gameui.bgaAnimationsActive(); }
    async pulse(zone, cls) {
        const el = document.getElementById('kemet-zone-' + zone);
        if (!el || !this.animationsOn()) return;
        el.classList.add(cls);
        await this.wait(600);
        el.classList.remove(cls);
    }
    async animateTroop(playerId, from, to, units) {
        const map = document.getElementById('kemet-map');
        const a = document.getElementById('kemet-zone-' + from), b = document.getElementById('kemet-zone-' + to);
        if (!map || !a || !b || !this.animationsOn()) return;
        const ghost = document.createElement('div');
        ghost.className = 'kemet-ghost';
        ghost.style.setProperty('--player', '#' + this.colorHex(playerId));
        ghost.textContent = units;
        ghost.style.left = a.style.left;
        ghost.style.top = a.style.top;
        map.appendChild(ghost);
        await this.wait(20);
        ghost.style.left = b.style.left;
        ghost.style.top = b.style.top;
        await this.wait(650);
        ghost.remove();
    }
}
