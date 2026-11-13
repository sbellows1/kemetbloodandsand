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

The clickable board now places every active district and shared zone over the matching printed artwork. The six printed city sites follow the rulebook's two-to-five-player configurations; storm-covered and out-of-play districts are marked grey. Land links are traced from visible territory boundaries, and the harbor rules allow departure from any harbor to a trading harbor or the military harbor outside your own city. The current map still uses a reduced route graph: it models one or two waypoints per move, does not model every harbor and wall nuance, and assigns starting cities automatically. The power market and DI deck contain a reduced set with text effects; their labels and numbers are prototype stand-ins, not a complete reproduction of the physical components. Recruit currently places units in one district per action.

Edit `modules/php/PrototypeRules.php` to change the graph and catalog. The PHP game state lives in `modules/php/CoreGame.php`, with state actions in `modules/php/States/`. The interface is `modules/js/Game.js` and `kemetbloodandsands.css`. Restart a BGA table after deploying the new `dbmodel.sql` schema.

## Artwork

The interface uses compressed JPEGs derived from the supplied PDFs: the box sticker for the title banner, poster for the round marker, game board front as the interactive map, five player board fronts as portraits, and punchboard power faces as **representative color art**. These files are under 1 MB each.

The supplied PDFs do not include standalone battle-card face sheets, a complete Divine Intervention card face sheet, troop or creature miniature sprites, or pyramid model artwork. Those pieces remain text and CSS placeholders. The punchboards do contain individual power tile faces and tokens, but the current reduced prototype powers do not correspond one-to-one with those printed tiles; the thumbnails are decorative until the full tile catalog is implemented. The player aid, rulebook, board back, and player board backs remain reference material rather than gameplay assets.
