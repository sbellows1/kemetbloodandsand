/** Kemet: Blood and Sand tabletop prototype client. */
class KemetState {
    constructor(game, name) { this.game = game; this.name = name; }
    onEnteringState(args, active) { this.game.enterState(this.name, args, active); }
    onLeavingState() {}
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.active = false;
        this.phase = '';
        this.selectedZone = '';
        this.moveSourcePicked = false;
        this.retreatOptions = [];
        for (const name of ['PlayerTurn', 'BattleChoice', 'BattleAftermath', 'NightChoice', 'Destiny']) {
            this.bga.states.register(name, new KemetState(this, name));
        }
    }

    setup(gamedatas) {
        this.me = String(this.bga.players.getCurrentPlayerId());
        this.players = gamedatas.players;
        this.state = gamedatas.kemet;
        this.defs = {
            slots: gamedatas.slots,
            tiles: gamedatas.tiles,
            battleCards: gamedatas.battleCards,
            diCards: gamedatas.diCards
        };
        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend',
            '<main id="kemet-root"><header class="kemet-title"><div><small>ILLUSTRATED TABLETOP PROTOTYPE</small><h1>Kemet · Blood and Sand</h1><p>Choose districts and territories directly on the printed board.</p></div><div id="kemet-phase"></div></header><section id="kemet-players"></section><div class="kemet-layout"><section><h2>Land of Kemet</h2><p class="kemet-hint">Select a marker to inspect its troop, pyramid, and legal routes. Select a troop zone, then a destination to fill the Move action.</p><div id="kemet-map-scroll"><div id="kemet-map" role="group" aria-label="Interactive Kemet game board"></div></div><div id="kemet-map-detail" aria-live="polite"></div></section><aside><section id="kemet-action"></section><section id="kemet-market"></section><section id="kemet-log"></section></aside></div></main>');
        this.bga.notifications.setupPromiseNotifications({});
        this.render();
    }

    enterState(name, args, active) {
        this.phase = name;
        this.active = active;
        if (args.kemet) this.state = args.kemet;
        for (const key of ['slots', 'tiles', 'battleCards', 'diCards']) if (args[key]) this.defs[key] = args[key];
        this.retreatOptions = args.retreatOptions || [];
        const titles = {
            Destiny: active ? _('Choose your turn order space') : _('Players are choosing turn order'),
            PlayerTurn: active ? _('Choose one action') : _('A god is taking an action'),
            BattleChoice: active ? _('Choose two battle cards secretly') : _('War council is in progress'),
            BattleAftermath: active ? _('Choose your battle aftermath') : _('Battle aftermath is in progress'),
            NightChoice: active ? _('Make your night decisions') : _('Night decisions are in progress')
        };
        this.bga.statusBar.setTitle(titles[name] || _('Kemet'));
        this.render();
    }

    notif_kemetChanged(args) {
        const own = this.state?.players?.[this.me];
        this.state = args.kemet;
        if (own && this.state.players[this.me]) {
            for (const key of ['diHand', 'battleHand']) if (own[key]) this.state.players[this.me][key] = own[key];
        }
        this.render();
    }

    escape(value) {
        return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    }
    name(id) { return this.players[id]?.name || this.players[id]?.player_name || 'Player ' + id; }
    playerColor(id) { return '#' + (this.players[id]?.color || this.players[id]?.player_color || '777777'); }
    portraitIndex(id, fallback) {
        const color = this.playerColor(id).toLowerCase();
        return ({'#ff0000':0, '#ffa500':1, '#ffff00':1, '#0000ff':2, '#008000':3, '#000000':4})[color] ?? fallback % 5;
    }
    asset(name) { return g_gamethemeurl + 'img/' + name; }
    options(items, selected) {
        return items.map(([value, label]) => '<option value="' + this.escape(value) + '"' + (value === selected ? ' selected' : '') + '>' + this.escape(label) + '</option>').join('');
    }
    select(id, items) { return '<select id="' + id + '">' + this.options(items) + '</select>'; }
    field(label, html) { return '<label class="kemet-field"><span>' + this.escape(label) + '</span>' + html + '</label>'; }
    button(label, action, secondary = false) { return '<button type="button" data-kemet-action="' + action + '"' + (secondary ? ' class="secondary"' : '') + '>' + this.escape(label) + '</button>'; }

    render() {
        if (!this.state || !document.getElementById('kemet-root')) return;
        const s = this.state;
        document.getElementById('kemet-phase').innerHTML = '<strong>Round ' + s.round + '</strong><span>' + (s.destiny ? 'Destiny' : (s.night ? 'Night' : 'Day') + ' · ' + (s.night ? s.night.index + 1 : Math.floor(s.turnIndex / s.order.length) + 1) + ' / 5') + '</span>';
        document.getElementById('kemet-players').innerHTML = s.order.map((id, index) => {
            const p = s.players[id];
            return '<div class="kemet-player" style="--player:' + this.playerColor(id) + '"><span class="kemet-player-art art-' + this.portraitIndex(id, index) + '" aria-hidden="true"></span><div class="kemet-player-copy"><strong>' + this.escape(this.name(id)) + '</strong><span>' + p.score + ' VP · ' + p.pp + ' PP · ' + p.veterans + ' veterans</span><small>' + this.escape(p.tiles.map(t => this.defs.tiles[t]?.name || t).join(', ')) + '</small></div></div>';
        }).join('');
        this.renderMap();
        const market = Object.entries(this.defs.tiles || {}).filter(([key]) => s.market[key]).map(([key, tile]) => '<div class="kemet-tile ' + tile.color + '"><span class="kemet-tile-art ' + tile.color + '" aria-hidden="true"></span><div class="kemet-tile-copy"><strong>' + this.escape(tile.name) + '</strong><span>' + this.escape(tile.color) + ' · level ' + tile.level + ' · ' + this.escape(tile.text) + '</span></div></div>').join('');
        document.getElementById('kemet-market').innerHTML = '<h2>Power market</h2><p class="kemet-hint">Punchboard illustrations identify each color; tile names and effects remain prototype text.</p><div class="kemet-tiles">' + (market || '<p>All tiles acquired.</p>') + '</div>';
        document.getElementById('kemet-log').innerHTML = '<h2>Recent events</h2><ol>' + s.log.slice().reverse().map(line => '<li>' + this.escape(line) + '</li>').join('') + '</ol>';
        document.getElementById('kemet-action').innerHTML = this.actionHtml();
        document.querySelectorAll('[data-kemet-action]').forEach(el => el.addEventListener('click', () => this.handleAction(el.dataset.kemetAction)));
    }

    renderMap() {
        const s = this.state;
        const selected = s.zones[this.selectedZone];
        const routes = selected ? new Set(selected.neighbors) : new Set();
        if (selected?.harbor) {
            for (const [key, zone] of Object.entries(s.zones)) {
                if (zone.harbor === 'trade' || (zone.harbor === 'military' && zone.harborOwner === this.me)) routes.add(key);
            }
        }
        const sites = Object.values(s.citySites || {}).map(site => {
            const owner = site.owner ? this.name(site.owner) : '';
            const badge = '<div class="kemet-city-site ' + this.escape(site.status) + '" style="left:' + (site.x / 1381 * 100).toFixed(3) + '%;top:' + (site.y / 1700 * 100).toFixed(3) + '%" title="' + this.escape(site.name + (owner ? ' · ' + owner : ' · ' + site.status)) + '">' + this.escape(site.abbr) + '</div>';
            const closed = site.status === 'active' ? '' : site.districts.map(([x, y], i) => '<span class="kemet-inactive-district" style="left:' + (x / 1381 * 100).toFixed(3) + '%;top:' + (y / 1700 * 100).toFixed(3) + '%" title="' + this.escape(site.name + ' district ' + (i + 1) + ' · ' + site.status) + '">' + this.escape(site.abbr) + (i + 1) + '</span>').join('');
            return badge + closed;
        }).join('');
        document.getElementById('kemet-map').innerHTML = sites + Object.entries(s.zones).map(([key, zone]) => {
            const troop = Object.entries(zone.units).filter(([, count]) => count > 0);
            const count = troop.reduce((sum, [, n]) => sum + n, 0);
            const owner = troop[0]?.[0] || zone.city;
            const pin = (zone.kind === 'district' ? zone.abbr : zone.kind === 'temple' ? '◆' : zone.kind === 'sanctuary' ? '✦' : zone.obelisk ? '♜' : '●');
            const label = zone.name + (count ? ' · ' + count + ' units' : '') + (zone.pyramid ? ' · ' + zone.pyramid.color + ' pyramid level ' + zone.pyramid.level : '');
            return '<button type="button" class="kemet-map-pin ' + zone.kind + (this.selectedZone === key ? ' selected' : '') + (routes.has(key) ? ' route' : '') + '" data-zone="' + this.escape(key) + '" style="left:' + (zone.x / 1381 * 100).toFixed(3) + '%;top:' + (zone.y / 1700 * 100).toFixed(3) + '%;--owner:' + (owner ? this.playerColor(owner) : '#765b37') + '" title="' + this.escape(label) + '" aria-label="' + this.escape(label) + '"><span>' + this.escape(pin) + '</span>' + (count ? '<b>' + count + '</b>' : '') + (zone.pyramid ? '<i class="' + this.escape(zone.pyramid.color) + '" title="Pyramid level ' + zone.pyramid.level + '">' + zone.pyramid.level + '</i>' : '') + '</button>';
        }).join('');
        document.querySelectorAll('#kemet-map [data-zone]').forEach(el => el.addEventListener('click', () => this.zoneClick(el.dataset.zone)));
        const detail = document.getElementById('kemet-map-detail');
        if (!selected) {
            detail.innerHTML = '<strong>Board key</strong><span>Round markers are city districts and deserts. Diamonds are temples; the star is the Sanctuary. A number shows troop strength. Grey city badges are closed for this player count.</span>';
            return;
        }
        const troopText = Object.entries(selected.units).filter(([, n]) => n > 0).map(([id, n]) => this.escape(this.name(id)) + ' ×' + n).join(', ') || 'No troops';
        const pyramid = selected.pyramid ? ' · ' + this.escape(selected.pyramid.color) + ' pyramid level ' + selected.pyramid.level : '';
        const routeText = [...routes].filter(key => key !== this.selectedZone && s.zones[key]).map(key => this.escape(s.zones[key].name)).join(', ') || 'None';
        detail.innerHTML = '<strong>' + this.escape(selected.name) + '</strong><span>' + troopText + pyramid + (selected.obelisk ? ' · Obelisk' : '') + (selected.prayer ? ' · +' + selected.prayer + ' PP at night' : '') + '</span><small>Routes: ' + routeText + '</small>';
    }

    actionHtml() {
        if (!this.active) return '<h2>Actions</h2><p>Waiting for the active player.</p>';
        const s = this.state;
        const p = s.players[this.me];
        if (!p) return '<h2>Actions</h2><p>Waiting for player data.</p>';
        if (this.phase === 'BattleChoice') {
            const hand = p.battleHand || [];
            const cards = hand.map(id => [String(id), this.defs.battleCards[id].name + ' · S' + this.defs.battleCards[id].strength + ' D' + this.defs.battleCards[id].damage + ' P' + this.defs.battleCards[id].pierce + ' B' + this.defs.battleCards[id].shield]);
            const di = (p.diHand || []).filter(c => this.defs.diCards[c]?.phase === 'battle').map(c => this.field(this.defs.diCards[c].name + ' (' + this.defs.diCards[c].cost + ' PP) · ' + this.defs.diCards[c].text, '<input type="checkbox" class="battle-di" value="' + this.escape(c) + '">')).join('');
            return '<h2>War council</h2><p>Choose one card to play and a different card to discard facedown. You may hide any number of battle DI cards under your played card.</p>' +
                this.field('Play', this.select('battle-play', cards)) + this.field('Discard', this.select('battle-discard', cards)) + (di ? '<h3>Divine intervention</h3>' + di : '') + this.button('Commit secret choice', 'battle');
        }
        if (this.phase === 'BattleAftermath') {
            const b = s.battle;
            if (!b || !s.zones[b.zone]) return '<h2>Battle aftermath</h2><p>Resolving battle…</p>';
            const zone = s.zones[b.zone];
            let html = '<h2>Battle aftermath</h2><p>' + this.escape(this.name(b.winner)) + ' won in ' + this.escape(zone.name) + '.</p>' +
                '<p>Strength: ' + Object.entries(b.totals || {}).map(([id,t]) => this.escape(this.name(id)) + ' ' + t.strength).join(' · ') + '</p>';
            if (b.step === 'loser') {
                const winnerStays = (zone.units[b.winner] || 0) > 0;
                html += '<p>' + (winnerStays ? 'Recall your troop (gain PP equal to units − 1) or retreat; the winner chooses where you retreat.' : 'The winner has no troop left, so you must recall.') + '</p>' +
                    this.button('Recall troop', 'recall') + (winnerStays ? this.button('Retreat', 'retreat-request', true) : '');
            } else if (b.step === 'retreat') {
                html += '<p>Choose an adjacent free zone for ' + this.escape(this.name(b.loser)) + '\'s retreating troop.</p>' +
                    this.field('Retreat destination', this.select('retreat-to', this.retreatOptions.map(k => [k, s.zones[k].name]))) + this.button('Retreat them here', 'retreat');
            } else {
                html += '<p>Keep your troop in the zone or recall it.</p>' + this.button('Stay', 'stay') + this.button('Recall troop', 'recall', true);
            }
            return html;
        }
        if (this.phase === 'Destiny') {
            const d = s.destiny;
            if (!d) return '<h2>Destiny</h2><p>Resolving turn order…</p>';
            return '<h2>Destiny</h2><p>Choose an empty turn order space. Space 1 plays first.</p>' +
                d.positions.map((owner, i) => owner === null ? this.button('Space ' + (i + 1), 'destiny:' + i) : '<span class="kemet-used"><span>Space ' + (i + 1) + ': ' + this.escape(this.name(owner)) + '</span></span>').join(' ');
        }
        if (this.phase === 'NightChoice') {
            const homes = Object.entries(s.zones).filter(([,z]) => z.city === this.me).map(([k,z]) => [k,z.name]);
            return '<h2>Night</h2><p>Choose offerings and how to use veteran tokens. Then temples pay prayer, each player gains 2 PP and draws a DI card.</p>' +
                this.field('Sanctuary: sacrifice 2 units for 1 permanent VP', '<input id="night-offer" type="checkbox">') +
                this.field('Delta: sacrifice 1 unit for 5 PP', '<input id="night-delta" type="checkbox">') +
                this.field('Veterans for prayer points', '<input id="night-pray" type="number" min="0" max="20" value="0">') +
                this.field('Veterans for extra DI cards (1 each)', '<input id="night-draw" type="number" min="0" max="20" value="0">') +
                this.field('Veterans for units', '<input id="night-recruit" type="number" min="0" max="10" value="0">') +
                this.field('Recruit into', this.select('night-district', homes)) + this.button('Confirm night', 'night');
        }
        const used = p.slots || [];
        const slots = Object.entries(this.defs.slots).filter(([key]) => !used.includes(key));
        const slotOptions = action => slots.filter(([,v]) => v.action === action).map(([key,v]) => [key, 'Tier ' + v.tier + ' · ' + key.replaceAll('_', ' ')]);
        const homes = Object.entries(s.zones).filter(([,z]) => z.city === this.me).map(([k,z]) => [k,z.name]);
        const from = Object.entries(s.zones).filter(([,z]) => (z.units[this.me] || 0) > 0).map(([k,z]) => [k,z.name + ' (' + z.units[this.me] + ')']);
        const allZones = Object.entries(s.zones).map(([k,z]) => [k,z.name]);
        const tiles = Object.entries(this.defs.tiles).filter(([k]) => s.market[k]).map(([k,t]) => [k,t.name + ' · ' + t.color + ' ' + t.level + ' · ' + t.text]);
        const colors = s.colors.map(c => [c,c]);
        const dayCards = (p.diHand || []).filter(c => this.defs.diCards[c]?.phase === 'day').map(c => [c, this.defs.diCards[c].name + ' · ' + this.defs.diCards[c].text]);
        const form = (title, fields, action) => '<div class="kemet-action-form"><h3>' + title + '</h3>' + fields + this.button(title, action) + '</div>';
        let html = '<h2>Your actions</h2><p>' + used.length + ' / 5 tokens placed. Cover all three tiers by day end.</p><div class="kemet-used">' + used.map(x => '<span>' + this.escape(x) + '</span>').join('') + '</div>';
        if (slotOptions('pray').length) html += form('Pray', this.field('Space', this.select('pray-slot',slotOptions('pray'))), 'pray');
        if (slotOptions('raise').length) html += form('Raise pyramid', this.field('Space', this.select('raise-slot',slotOptions('raise'))) + this.field('District',this.select('raise-zone',homes)) + this.field('Color',this.select('raise-color',colors)) + this.field('New level','<input id="raise-level" type="number" min="1" max="4" value="3">'), 'raise');
        if (slotOptions('buy').length) html += form('Buy power', this.field('Space',this.select('buy-slot',slotOptions('buy'))) + this.field('Tile',this.select('buy-tile',tiles)), 'buy');
        if (slotOptions('recruit').length) html += form('Recruit', this.field('Space',this.select('recruit-slot',slotOptions('recruit'))) + this.field('District',this.select('recruit-zone',homes)) + this.field('Units','<input id="recruit-units" type="number" min="1" max="5" value="1">'), 'recruit');
        if (slotOptions('move').length) html += form('Move troop', this.field('Space',this.select('move-slot',slotOptions('move'))) + this.field('From',this.select('move-from',from)) + this.field('Via (optional)',this.select('move-via',[['','Direct'],...allZones])) + this.field('To',this.select('move-to',allZones)) + this.field('Units','<input id="move-units" type="number" min="1" max="5" value="1">') + this.field('Teleport (2 PP, pyramid to obelisk)','<input id="move-teleport" type="checkbox">'), 'move');
        if (dayCards.length) html += form('Play day DI', this.field('Card',this.select('day-card',dayCards)) + this.field('District for Reinforcement',this.select('day-zone',homes)), 'day');
        return html;
    }

    zoneClick(key) {
        this.selectedZone = key;
        this.renderMap();
        const from = document.getElementById('move-from');
        const to = document.getElementById('move-to');
        if (!from || !to) return;
        if (!this.moveSourcePicked && this.state.zones[key].units[this.me]) {
            from.value = key;
            this.moveSourcePicked = true;
        } else {
            to.value = key;
            this.moveSourcePicked = false;
        }
    }
    value(id) { return document.getElementById(id)?.value || ''; }
    number(id) { return Number(this.value(id)); }
    checked(id) { return Boolean(document.getElementById(id)?.checked); }
    handleAction(kind) {
        const actions = {
            pray: ['actPray', {slot: this.value('pray-slot')}],
            raise: ['actRaise', {slot: this.value('raise-slot'),zone: this.value('raise-zone'),color: this.value('raise-color'),level: this.number('raise-level')}],
            buy: ['actBuy', {slot: this.value('buy-slot'),tile: this.value('buy-tile')}],
            recruit: ['actRecruit', {slot: this.value('recruit-slot'),zone: this.value('recruit-zone'),units: this.number('recruit-units')}],
            move: ['actMove', {slot: this.value('move-slot'),from: this.value('move-from'),to: this.value('move-to'),units: this.number('move-units'),teleport: this.checked('move-teleport'),via: this.value('move-via')}],
            day: ['actDayCard', {card: this.value('day-card'),zone: this.value('day-zone')}],
            battle: ['actBattleChoice', {played: this.number('battle-play'),discarded: this.number('battle-discard'),di: [...document.querySelectorAll('.battle-di:checked')].map(el => el.value).join(',')}],
            stay: ['actAftermath', {choice:'stay',retreatTo:''}],
            recall: ['actAftermath', {choice:'recall',retreatTo:''}],
            'retreat-request': ['actAftermath', {choice:'retreat',retreatTo:''}],
            retreat: ['actAftermath', {choice:'retreat',retreatTo:this.value('retreat-to')}],
            night: ['actNightChoice', {offer:this.checked('night-offer'),delta:this.checked('night-delta'),pray:this.number('night-pray'),draw:this.number('night-draw'),recruit:this.number('night-recruit'),district:this.value('night-district')}]
        };
        if (kind.startsWith('destiny:')) {
            this.bga.actions.performAction('actDestiny', {position: Number(kind.slice(8))});
            return;
        }
        const [name, params] = actions[kind];
        this.bga.actions.performAction(name, params);
    }
}
