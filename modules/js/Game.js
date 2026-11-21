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

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.phase = '';
        this.active = false;
        this.args = {};
        this.sel = null;
        for (const name of ['PlayerTurn', 'BattleChoice', 'BattleAftermath', 'NightChoice', 'Destiny']) {
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
        this.hand = { diHand: mine?.diHand || [], battleHand: mine?.battleHand || [] };

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
    teleportCost() { return this.cost(Math.max(0, 2 - this.tileBonus('teleport_discount'))); }
    tileCost(tile) { return this.cost(Math.max(0, tile.level - this.tileBonus('buy_discount'))); }
    unitTotal() { return Object.values(this.state.zones).reduce((sum, z) => sum + (z.units[this.me] || 0), 0); }
    homeDistricts() { return Object.keys(this.state.zones).filter(k => this.state.zones[k].city === this.me); }
    tooltip(id, html) { if (document.getElementById(id)) this.bga.gameui.addTooltipHtml(id, html); }

    // ---------------------------------------------------------------- rules mirrors (server re-validates)

    slotAllowed(slot) {
        const used = this.me_().slots;
        if (used.includes(slot) || used.length >= 5) return false;
        const tiers = new Set([...used, slot].map(s => this.defs.slots[s].tier));
        return 5 - used.length - 1 >= 3 - tiers.size;
    }
    slotUsable(slot) {
        if (!this.slotAllowed(slot)) return false;
        const action = this.defs.slots[slot].action;
        if (action === 'raise') return this.raiseZones().length > 0;
        if (action === 'buy') return this.buyableTiles(slot).length > 0;
        if (action === 'recruit') return this.me_().pp >= this.recruitCost(1) && this.unitTotal() < this.supply();
        if (action === 'move') return Object.values(this.state.zones).some(z => (z.units[this.me] || 0) > 0);
        return true;
    }
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
    buyableTiles(slot) {
        const p = this.me_();
        const slotColor = slot.slice(4);
        const ownedNames = p.tiles.map(t => this.defs.tiles[t].name);
        return Object.entries(this.defs.tiles).filter(([key, tile]) => {
            if (!this.state.market[key] || p.bought.includes(tile.color) || p.pp < this.tileCost(tile) || ownedNames.includes(tile.name)) return false;
            let own = false, foreign = false;
            for (const z of Object.values(this.state.zones)) {
                if (this.controller(z) !== this.me || z.pyramid?.color !== tile.color || (z.pyramid?.level || 0) < tile.level) continue;
                if (z.city === this.me) own = true; else foreign = true;
            }
            const hasToken = this.usedColors().includes(slotColor);
            return (own && slot === 'buy_' + tile.color) || (foreign && hasToken);
        }).map(([key]) => key);
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
    /** Map of destination zone -> via zone ('' for direct), mirroring CoreGame::doMove. */
    destinations(from, units, teleport) {
        const z = this.state.zones;
        const keys = Object.keys(z);
        const result = new Map();
        const stepOk = (prev, next, index, tp) => {
            const nz = z[next];
            const land = z[prev].neighbors.includes(next);
            const harbor = !!z[prev].harbor && (nz.harbor === 'trade' || (nz.harbor === 'military' && nz.harborOwner === this.me));
            if (!land && !harbor) return false;
            if (nz.kind === 'district' && nz.city !== this.me && (tp || index !== 0 || !z[from].neighbors.includes(next))) return false;
            return true;
        };
        const passable = k => !this.enemyIn(k) && !(z[k].kind === 'district' && z[k].city !== this.me);
        if (!teleport) {
            for (const k of keys) if (k !== from && stepOk(from, k, 0, false)) result.set(k, '');
            if (this.tileBonus('move') > 0) {
                for (const mid of [...result.keys()]) {
                    if (!passable(mid)) continue;
                    for (const k of keys) if (k !== from && !result.has(k) && stepOk(mid, k, 1, false)) result.set(k, mid);
                }
            }
        } else {
            const obelisks = keys.filter(k => z[k].obelisk && k !== from);
            for (const o of obelisks) result.set(o, '');
            for (const o of obelisks) {
                if (this.enemyIn(o)) continue;
                for (const k of keys) if (k !== from && !result.has(k) && stepOk(o, k, 1, true)) result.set(k, o);
            }
        }
        for (const k of [...result.keys()]) if ((z[k].units[this.me] || 0) + units > this.troopLimit()) result.delete(k);
        return result;
    }
    canTeleport(from) {
        const z = this.state.zones[from];
        const source = (!!z.pyramid && this.controller(z) === this.me) || (z.obelisk && this.tileBonus('teleport_obelisk') > 0);
        return source && this.me_().pp >= this.teleportCost();
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
            case 'PlayerTurn': return this.step({ mode: 'choose' });
            case 'BattleChoice': return this.step({ mode: 'battlePlay' });
            case 'BattleAftermath': return this.step({ mode: 'aftermath' });
            case 'NightChoice': return this.step({ mode: 'night', offer: false, delta: false, pray: 0, draw: 0, recruit: 0, district: '' });
            case 'Destiny': return this.step({ mode: 'destiny' });
        }
    }
    step(sel) { this.sel = sel; this.refresh(); }
    cancel() { this.startPhase(); }
    act(name, params) {
        this.bga.actions.performAction(name, params).catch(() => {});
    }

    /** Recomputes selectable elements, status bar title and buttons, then re-renders. */
    refresh() {
        const bar = this.bga.statusBar;
        bar.removeActionButtons();
        this.selectable = { zones: new Set(), slots: new Set(), tiles: new Set(), cards: new Set(), di: new Set(), chosen: new Set() };
        if (this.active && this.sel && this.me_()) this.buildStep(bar);
        this.render();
    }

    buildStep(bar) {
        const s = this.sel, S = this.selectable, p = this.me_();
        const cancel = () => bar.addActionButton(_('Cancel'), () => this.cancel(), { color: 'secondary' });
        switch (s.mode) {
            case 'choose': {
                bar.setTitle(_('${you} must choose an action space'));
                for (const slot of Object.keys(this.defs.slots)) if (this.slotUsable(slot)) S.slots.add(slot);
                this.hand.diHand.forEach((c, i) => { if (this.defs.diCards[c]?.phase === 'day') S.di.add(i); });
                return;
            }
            case 'raiseZone':
                bar.setTitle(_('${you} must choose a district for the pyramid'));
                this.raiseZones().forEach(k => S.zones.add(k));
                return cancel();
            case 'raiseColor':
                bar.setTitle(_('${you} must choose the new pyramid\'s color'));
                for (const c of this.state.colors.filter(c => !this.usedColors().includes(c))) {
                    bar.addActionButton(`<span class="kemet-sym ${c}">${SYMBOLS[c]}</span> ${this.esc(this.colorName(c))}`, () => this.step({ ...s, mode: 'raiseLevel', color: c, from: 0 }));
                }
                return cancel();
            case 'raiseLevel':
                bar.setTitle(_('${you} must choose the pyramid level'));
                for (let level = s.from + 1; level <= 4; level++) {
                    const cost = this.raiseCost(s.from, level);
                    bar.addActionButton(this.fmt(_('Level ${level} (${cost} PP)'), { level, cost }),
                        () => this.act('actRaise', { slot: s.slot, zone: s.zone, color: s.color, level }), { disabled: cost > p.pp });
                }
                return cancel();
            case 'buyTile':
                bar.setTitle(_('${you} must choose a Power tile to buy'));
                this.buyableTiles(s.slot).forEach(k => S.tiles.add(k));
                return cancel();
            case 'recruitZone':
                bar.setTitle(_('${you} must choose a district to recruit in'));
                this.homeDistricts().filter(k => this.recruitMax(k) > 0).forEach(k => S.zones.add(k));
                return cancel();
            case 'recruitUnits': {
                bar.setTitle(_('${you} must choose how many units to recruit'));
                for (let n = 1; n <= this.recruitMax(s.zone); n++) {
                    bar.addActionButton(this.fmt(_('${n} unit(s) for ${cost} PP'), { n, cost: this.recruitCost(n) }),
                        () => this.act('actRecruit', { slot: s.slot, zone: s.zone, units: n }));
                }
                S.chosen.add('zone:' + s.zone);
                return cancel();
            }
            case 'moveFrom':
                bar.setTitle(_('${you} must choose a troop to move'));
                Object.keys(this.state.zones).filter(k => (this.state.zones[k].units[this.me] || 0) > 0).forEach(k => S.zones.add(k));
                return cancel();
            case 'moveUnits': {
                bar.setTitle(_('${you} must choose how many units move'));
                const max = this.state.zones[s.from].units[this.me];
                for (let n = max; n >= 1; n--) bar.addActionButton(n === max ? this.fmt(_('All (${n})'), { n }) : String(n), () => this.step({ ...s, mode: 'moveTo', units: n }));
                S.chosen.add('zone:' + s.from);
                return cancel();
            }
            case 'moveTo': {
                bar.setTitle(s.teleport ? this.fmt(_('${you} must choose where to teleport (${cost} PP)'), { cost: this.teleportCost() }) : _('${you} must choose a destination'));
                this.destinations(s.from, s.units, s.teleport).forEach((via, k) => S.zones.add(k));
                S.chosen.add('zone:' + s.from);
                if (this.canTeleport(s.from)) {
                    bar.addActionButton(s.teleport ? _('Move by land instead') : this.fmt(_('Teleport (${cost} PP)'), { cost: this.teleportCost() }),() => this.step({ ...s, teleport: !s.teleport }), { color: 'secondary' });
                }
                return cancel();
            }
            case 'reinforceZone':
                bar.setTitle(_('${you} must choose a district for the reinforcement'));
                if (this.unitTotal() < this.supply()) this.freeHomeDistricts().forEach(k => S.zones.add(k));
                return cancel();
            case 'battlePlay':
                bar.setTitle(_('War Council: ${you} must choose the battle card to play'));
                this.hand.battleHand.forEach(c => S.cards.add(String(c)));
                return;
            case 'battleDiscard':
                bar.setTitle(_('War Council: ${you} must choose a card to discard facedown'));
                this.hand.battleHand.filter(c => c !== s.played).forEach(c => S.cards.add(String(c)));
                S.chosen.add('card:' + s.played);
                return cancel();
            case 'battleDi': {
                const cost = [...s.di].reduce((sum, i) => sum + this.defs.diCards[this.hand.diHand[i]].cost, 0);
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
                bar.setTitle(_('Night: ${you} have ${left} veteran(s) to spend (PP ${pray}, DI ${draw}, units ${recruit})'), { left, pray: s.pray, draw: s.draw, recruit: s.recruit });
                if (this.args.canOffer) bar.addActionButton(s.offer ? _('✓ Sanctuary offering (2 units → 1 FP)') : _('Sanctuary offering (2 units → 1 FP)'), () => this.step({ ...s, offer: !s.offer }), { color: s.offer ? 'primary' : 'secondary' });
                if (this.args.canDelta) bar.addActionButton(s.delta ? _('✓ Delta offering (1 unit → 5 PP)') : _('Delta offering (1 unit → 5 PP)'), () => this.step({ ...s, delta: !s.delta }), { color: s.delta ? 'primary' : 'secondary' });
                if (vets > 0) {
                    bar.addActionButton(_('+1 PP'), () => this.step({ ...s, pray: s.pray + 1 }), { color: 'secondary', disabled: left < 1 });
                    bar.addActionButton(_('+1 DI card'), () => this.step({ ...s, draw: s.draw + 1 }), { color: 'secondary', disabled: left < 1 });
                    bar.addActionButton(_('+1 unit'), () => this.step({ ...s, recruit: s.recruit + 1 }), { color: 'secondary', disabled: left < 1 || !this.freeHomeDistricts().length });
                    bar.addActionButton(_('Reset veterans'), () => this.step({ ...s, pray: 0, draw: 0, recruit: 0, district: '' }), { color: 'secondary' });
                }
                if (s.recruit > 0) {
                    this.freeHomeDistricts().forEach(k => S.zones.add(k));
                    if (s.district) S.chosen.add('zone:' + s.district);
                }
                bar.addActionButton(s.recruit > 0 && !s.district ? _('Choose a district for your units') : _('Confirm night'), () => this.act('actNightChoice', {
                    offer: s.offer, delta: s.delta, pray: s.pray, draw: s.draw, recruit: s.recruit, district: s.district,
                }), { disabled: s.recruit > 0 && !s.district });
                return;
            }
            case 'destiny': {
                bar.setTitle(_('${you} must choose a turn order space (space 1 plays first)'));
                (this.state.destiny?.positions || []).forEach((owner, i) => {
                    if (owner === null) bar.addActionButton(this.fmt(_('Space ${n}'), { n: i + 1 }), () => this.act('actDestiny', { position: i }));
                });
                return;
            }
        }
    }

    // ---------------------------------------------------------------- clicks

    onRootClick(event) {
        const el = event.target.closest('[data-zone],[data-slot],[data-tile],[data-card],[data-di]');
        if (!el || !el.classList.contains('selectable') || !this.active || !this.sel) return;
        const s = this.sel;
        if (el.dataset.slot) return this.clickSlot(el.dataset.slot);
        if (el.dataset.tile) return this.act('actBuy', { slot: s.slot, tile: el.dataset.tile });
        if (el.dataset.di !== undefined) return this.clickDi(Number(el.dataset.di));
        if (el.dataset.card) return this.clickCard(Number(el.dataset.card));
        if (el.dataset.zone) return this.clickZone(el.dataset.zone);
    }
    clickSlot(slot) {
        const action = this.defs.slots[slot].action;
        if (action === 'pray') return this.act('actPray', { slot });
        const next = { raise: 'raiseZone', buy: 'buyTile', recruit: 'recruitZone', move: 'moveFrom' }[action];
        this.step({ mode: next, slot });
    }
    clickZone(key) {
        const s = this.sel;
        switch (s.mode) {
            case 'raiseZone': {
                const p = this.state.zones[key].pyramid;
                return p ? this.step({ ...s, mode: 'raiseLevel', zone: key, color: p.color, from: p.level }) : this.step({ ...s, mode: 'raiseColor', zone: key });
            }
            case 'recruitZone': return this.step({ ...s, mode: 'recruitUnits', zone: key });
            case 'moveFrom': {
                const units = this.state.zones[key].units[this.me];
                return this.step({ ...s, from: key, mode: units > 1 ? 'moveUnits' : 'moveTo', units, teleport: false });
            }
            case 'moveTo': {
                const via = this.destinations(s.from, s.units, s.teleport).get(key) ?? '';
                return this.act('actMove', { slot: s.slot, from: s.from, to: key, units: s.units, teleport: s.teleport, via });
            }
            case 'reinforceZone': return this.act('actDayCard', { card: 'reinforce', zone: key });
            case 'aftermath': return this.act('actAftermath', { choice: 'retreat', retreatTo: key });
            case 'night': return this.step({ ...s, district: key });
        }
    }
    clickCard(card) {
        const s = this.sel;
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
        if (s.mode === 'battleDi') {
            const di = new Set(s.di);
            di.has(index) ? di.delete(index) : di.add(index);
            this.step({ ...s, di });
        }
    }

    // ---------------------------------------------------------------- rendering

    cls(kind, key) {
        const S = this.selectable || {};
        const set = { zone: S.zones, slot: S.slots, tile: S.tiles, card: S.cards, di: S.di }[kind];
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
            const troopHtml = troops.map(([id, n]) => `<b class="kemet-troop" style="--player:#${this.colorHex(id)}" data-initial="${this.esc(this.name(id).charAt(0))}">${n}</b>`).join('');
            const pyr = z.pyramid ? `<i class="kemet-pyramid ${z.pyramid.color}"><span class="kemet-sym">${SYMBOLS[z.pyramid.color]}</span>${z.pyramid.level}</i>` : '';
            const battle = s.battle?.zone === key ? ' battle' : '';
            return `<button type="button" id="kemet-zone-${key}" class="kemet-pin ${z.kind}${battle}${troops.length ? '' : ' empty'}${this.cls('zone', key)}" data-zone="${key}"
                style="left:${this.pct(z.x, W)};top:${this.pct(z.y, H)};--owner:${owner ? '#' + this.colorHex(owner) : (z.city ? '#' + this.colorHex(z.city) : '#765b37')}"
                aria-label="${this.esc(this.zoneName(key))}">${troopHtml}${pyr}</button>`;
        }).join('');
        // Round / phase marker: sun by day, moon by night, ankh during Destiny; the number is the round.
        const icon = s.destiny ? '☥' : (s.night ? '☾' : '☀');
        const dayStep = s.night || s.destiny ? 0 : Math.min(5, Math.floor(s.turnIndex / s.order.length) + 1);
        const pips = [1, 2, 3, 4, 5].map(i => `<i class="${i < dayStep ? 'done' : (i === dayStep ? 'now' : '')}"></i>`).join('');
        const marker = `<div id="kemet-round" class="kemet-round-marker${s.night || s.destiny ? ' night' : ''}"><span>${icon}</span><b>${s.round}</b><span class="kemet-pips">${pips}</span></div>`;
        document.getElementById('kemet-map').innerHTML = closed + pins + marker;
        for (const key of Object.keys(s.zones)) this.tooltip('kemet-zone-' + key, this.zoneTooltip(key));
        this.tooltip('kemet-round', this.esc(this.phaseText()));
    }
    zoneTooltip(key) {
        const z = this.state.zones[key];
        const lines = [`<strong>${this.esc(this.zoneName(key))}</strong>`];
        const troops = Object.entries(z.units).filter(([, n]) => n > 0);
        lines.push(troops.length ? troops.map(([id, n]) => this.esc(this.fmt(_('${player}: ${n} unit(s)'), { player: this.name(id), n }))).join('<br>') : this.esc(_('No troops')));
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
        const note = { none: _('Not yet implemented in this prototype: this tile has no effect.'), partial: _('Only partly implemented in this prototype.') }[tile.impl];
        return `<div class="kemet-tile-tip"><span class="kemet-tile-art" style="${this.tileArt(t)}"></span><div><strong>${this.esc(_(tile.name))}</strong><br>`
            + `${this.esc(this.fmt(_('${color} level ${level} · cost ${level} PP'), { color: this.colorName(tile.color), level: tile.level }))}<br>${this.esc(_(tile.text))}`
            + `${note ? `<br><em>${this.esc(note)}</em>` : ''}</div></div>`;
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
        const html = spaces.map(([slot, x, y, extra, token]) => {
            const used = p.slots.includes(slot);
            const sel = mine ? this.cls('slot', slot) : '';
            return `<button type="button" id="kemet-slot-${id}-${slot}" class="kemet-space${extra}${used ? ' used' : ''}${sel}" data-slot="${mine ? slot : ''}" style="left:${x}%;top:${y}%"
                aria-label="${this.esc(this.slotLabel(slot))}">${token}${used ? '<i class="kemet-action-token"></i>' : ''}</button>`;
        }).join('');
        const left = 5 - p.slots.length;
        const reserve = Array.from({ length: Math.max(0, left) }, () => '<i class="kemet-action-token"></i>').join('');
        el.className = `kemet-pboard art-${this.art(id)}${mine ? ' mine' : ''}`;
        el.style.setProperty('--player', '#' + this.colorHex(id));
        el.innerHTML = `${html}
            <div id="kemet-pp-track-${id}" class="kemet-pp-marker">${p.pp}</div>
            <div id="kemet-reserve-${id}" class="kemet-reserve">${reserve}</div>`;
        for (const [slot] of spaces) this.tooltip(`kemet-slot-${id}-${slot}`, this.esc(this.slotLabel(slot)) + '<br>' + this.esc(_('Equilibrium: by the end of the day each player needs an Action token on each of the three levels of the pyramid.')));
        this.tooltip('kemet-pp-track-' + id, this.esc(this.fmt(_('${player}: ${n} prayer points (max 11)'), { player: this.name(id), n: p.pp })));
        this.tooltip('kemet-reserve-' + id, this.esc(this.fmt(_('${n} Action token(s) left today'), { n: left })));
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
            return `<button type="button" id="kemet-card-${c}" class="kemet-card battle${this.cls('card', c)}" data-card="${c}" aria-label="${this.esc(_(d.name))}">
                <span class="kemet-card-stats"><span>⚔${d.strength}</span>${d.damage ? `<span>✦${d.damage}</span>` : ''}${d.pierce ? `<span>➶${d.pierce}</span>` : ''}${d.shield ? `<span>⛨${d.shield}</span>` : ''}</span></button>`;
        }).join('');
        const di = this.hand.diHand.map((c, i) => {
            const d = this.defs.diCards[c];
            return `<button type="button" id="kemet-di-card-${i}" class="kemet-card di ${d.phase}${this.cls('di', i)}" data-di="${i}" aria-label="${this.esc(_(d.name))}">
                <span class="kemet-card-icon">${d.phase === 'day' ? '☀' : '⚔'}</span>${d.cost ? `<span class="kemet-card-cost">☥${d.cost}</span>` : ''}</button>`;
        }).join('');
        el.innerHTML = cards + di;
        for (const c of this.hand.battleHand) {
            const d = this.defs.battleCards[c];
            this.tooltip('kemet-card-' + c, `<strong>${this.esc(_(d.name))}</strong><br>` + this.esc(this.fmt(_('Strength ${s} · Damage ${d} · Unblockable ${u} · Defense ${b}'), { s: d.strength, d: d.damage, u: d.pierce, b: d.shield })));
        }
        this.hand.diHand.forEach((c, i) => {
            const d = this.defs.diCards[c];
            this.tooltip('kemet-di-card-' + i, `<strong>${this.esc(_(d.name))}</strong><br>${this.esc(d.phase === 'day' ? _('Day card') : _('Battle card'))}<br>${this.esc(_(d.text))}<br>${this.esc(this.fmt(_('Cost: ${n} PP'), { n: d.cost }))}`);
        });
    }

    renderMarket() {
        const s = this.state;
        // One row per color in play, ascending level; only tiles in this game's market (2 players: no Act of God, no duplicates).
        const rows = s.colors.map(color => {
            const tiles = Object.entries(this.defs.tiles).filter(([k, t]) => t.color === color && k in s.market).map(([k, t]) => s.market[k]
                ? `<button type="button" id="kemet-tile-${k}" class="kemet-tile ${color}${this.cls('tile', k)}" data-tile="${k}" style="${this.tileArt(k)}" aria-label="${this.esc(_(t.name))}"><span class="kemet-sym">${SYMBOLS[color]}</span></button>`
                : `<span class="kemet-tile gone ${color}" style="${this.tileArt(k)}" aria-hidden="true"></span>`).join('');
            return `<div class="kemet-tile-row ${color}">${tiles}</div>`;
        }).join('');
        document.getElementById('kemet-market').innerHTML = rows;
        for (const k of Object.keys(s.market)) if (s.market[k]) this.tooltip('kemet-tile-' + k, this.tileTooltip(k));
    }

    // ---------------------------------------------------------------- notifications

    notif_kemetState(args) {
        this.state = args.kemet;
        if (this.active && this.sel) this.refresh(); else this.render();
    }
    notif_hand(args) {
        this.hand = { diHand: args.diHand, battleHand: args.battleHand };
        this.render();
    }
    async notif_move(args) {
        await this.animateTroop(args.mover ?? args.player_id, args.from, args.zone, args.units);
    }
    async notif_battleStart(args) { await this.pulse(args.zone, 'kemet-flash-battle'); }
    async notif_battleResolved(args) { await this.pulse(args.zone, 'kemet-flash-battle'); }
    async notif_recruit(args) { await this.pulse(args.zone, 'kemet-flash'); }
    async notif_raise(args) { await this.pulse(args.zone, 'kemet-flash'); }
    async notif_recall(args) { await this.pulse(args.zone, 'kemet-flash'); }
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
