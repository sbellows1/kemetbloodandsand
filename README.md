# Kemet: Blood and Sand — BGA prototype

This is a playable, server-validated prototype with artwork converted from the PDFs in `img/`.
It follows the core turn structure in the [Blood and Sand rulebook](https://cdn.1j1ju.com/medias/af/f0/59-kemet-blood-and-sand-rulebook.pdf). A local copy is `rules-reference.pdf`.

## Implemented

- Two to five players, printed-board city layouts and three districts per active city, starting with two five-unit troops, pyramids, prayer points, a starter power, two DI cards, and eight battle cards.
- Five turns per player in each Day Phase. Action spaces are used once per day and the three-tier equilibrium rule is enforced.
- Pray, buy a power, raise a pyramid, recruit, and move (including mapped land routes, harbor travel, pyramid-to-obelisk teleportation, and extra land movement from a power).
- Battle war council with private played and discarded card choices, optional DI card, strength, tie to defender, casualties, veteran rewards, battle VP, and loser/winner aftermath choices.
- Temple and level-four pyramid temporary VP, permanent VP from battle and powers, night offerings, temple income, veteran conversion, action reset, and the start-of-turn nine-VP victory condition.

## Prototype boundaries

The clickable board now places every active district and shared zone over the matching printed artwork. The six printed city sites follow the rulebook's two-to-five-player configurations; storm-covered and out-of-play districts are marked grey. Land links are traced from visible territory boundaries, and the harbor rules allow departure from any harbor to a trading harbor or the military harbor outside your own city. The current map still uses a reduced route graph: it models one or two waypoints per move, does not model every harbor and wall nuance, and assigns starting cities automatically. The power market uses the 64 printed Power tiles with the player-count rules (2 players: 2 colors, 26 tiles, no Act of God and one copy of each duplicate; 3–4 players: 3 colors, 48 tiles; 5 players: 4 colors, 64 tiles). Tile cost equals its level, and a player cannot buy a second tile with the same name. Most passive and "When acquired" effects are implemented as data-driven modifiers (`mods` in `PrototypeRules::tiles()`); tiles that need creatures, Special Battle cards, Divine Will, the Gold/Silver Action tokens or Act of God are in the market but have no effect yet (`impl: none`, flagged in their tooltip), and a few are only partly implemented (`impl: partial`). Each player starts with one level-1 tile of their starting pyramid's color as a prototype stand-in for the printed setup. The DI deck is a reduced set with text effects; its cards are prototype stand-ins. Recruit currently places units in one district per action. Action spaces follow the printed Individual board (top: Move, Recruit; middle: Raise, Move, Pray; bottom: Pray and the Buy a Power Tile spaces); the Divine Will gold space and Gold/Silver Action tokens are not implemented.

Edit `modules/php/PrototypeRules.php` to change the graph and catalog. The PHP game state lives in `modules/php/CoreGame.php`, with state actions in `modules/php/States/`. The interface is `modules/js/Game.js` and `kemetbloodandsands.css`. Restart a BGA table after deploying the new `dbmodel.sql` schema.

## Artwork

The interface uses compressed JPEGs derived from the supplied PDFs: the game board front as the interactive map, the five player board fronts as each player's action board (`img/player-boards.jpg`, with action tokens drawn on the printed spaces) and as panel portraits, and the punchboard power tile faces (`img/power-tiles.jpg`, all 64 tiles). `python tools/build-art.py` rebuilds the board and tile sprites from the PDFs (needs Pillow and PyMuPDF). These files are under 1 MB each.

The supplied PDFs do not include standalone battle-card face sheets, a complete Divine Intervention card face sheet, troop or creature miniature sprites, or pyramid model artwork. Those pieces remain text and CSS placeholders. Each Power tile in the catalog points at its own face in `img/power-tiles.jpg` (`art` index), shown in the market, the player panels and the tile tooltips. The player aid, rulebook, board back, and player board backs remain reference material rather than gameplay assets.
