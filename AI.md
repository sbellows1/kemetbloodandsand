# Agent instructions for Kemet: Blood and Sand (BGA)

Board Game Arena adaptation of *Kemet: Blood and Sand* (Matagot, 2–5 players). It is a playable, server-validated prototype; `README.md` lists what is implemented and the known prototype boundaries.

## Architecture and constraints

- Target PHP 8.4 with `declare(strict_types=1);`. Use the modern `Bga\GameFramework` APIs: `Table`, `States\GameState` with class-return transitions, `#[PossibleAction]`, `StateType`, `UserException`, and `$this->bga->notify` / `$this->bga->playerScore`. Check signatures in `_ide_helper.php` (server) and `bga-framework.d.ts` (client) before using an unfamiliar API. Both are framework-provided; refresh them from Studio rather than editing them.
- All game state is one JSON document in the `kemet_state` table (`dbmodel.sql`), read with `CoreGame::loadState()` and written with `saveState()`, which also updates player scores. Use BGA DB helpers (`DbQuery`, `getUniqueValueFromDB`, `escapeStringForDB`); never open raw `mysqli`/PDO connections. A schema change needs a new table in Studio, or a migration in `upgradeTableDb()` for live tables.
- The client is a single ES module, `modules/js/Game.js`, hand-maintained JavaScript (no Dojo `declare`, no `bga-cards`/`bga-animations`). Styles are in `kemetbloodandsands.css`. Edit these files directly.
- There is no working build. `package.json`/`rollup.config.mjs` point at `src/ts` and `src/scss`, but that template code was moved to `src-disabled/` and is stale. `npm run build` would overwrite `Game.js` and the CSS with the template; do not run it. Adopt TypeScript only as a deliberate, complete migration.
- Never introduce `eval()`, direct `$_GET`/`$_POST` access, external CDNs or `localStorage`. Keep secrets and private keys out of Git.

## Where things live

- `modules/php/Game.php` is the table class. It only loads `PrototypeRules.php` and `CoreGame.php` and extends `CoreGame`.
- `modules/php/CoreGame.php` holds setup, `getAllDatas`, `viewState` (per-viewer redaction), scoring and control, and all rule logic: actions (`doPray`, `doRaise`, `doBuy`, `doRecruit`, `doMove`, `playDayCard`), battle (`battleChoice`, `resolveBattle`, `battleAftermath`, `retreatOptions`), night (`resolveNight`, `nightPower`), Destiny (`startDestiny`, `chooseDestiny`), setup tile choice (`chooseStarter`), turn tokens (`requireSlot`, `useSlot`, `afterAction`) and creatures (`placeCreature`, `syncCreatures`).
- `modules/php/PrototypeRules.php` holds the component catalogs as `clienttranslate()`-wrapped static methods (`battleCards()`, `di()`, `tiles()`, `colorNames()`, `actionNames()`, plus the `SLOTS` constant) and the board graph (city sites, shared zones, links, harbors, obelisks, player-count layouts). `tiles()` is the real 64-tile catalog: each tile has `art` (sprite index), `mods` (effects read by `CoreGame::tileBonus()` and the client's `tileBonus()`), `copy`, `stack` (shared market stack), and optionally `creature`, `golden` and `card`; `tilesInPlay()` applies the player-count rules. The battle and DI card catalogs are still stand-ins.
- `modules/php/States/` holds one class per state. There is no `states.inc.php` or `material.inc.php`.

| ID | State | Type | Purpose |
|----|-------|------|---------|
| 5 | `StarterTile` | active, reverse turn order | setup choice of a Level 1 Power tile |
| 10 | `PlayerTurn` | active | colored token, optional silver/gold tokens, creatures, day DI cards; checks the 9-FP win at turn start |
| 20 | `BattleChoice` | multiple active (attacker and defender) | secret War Council choice; with Spy the opponent chooses first |
| 25 | `BattleWound` | active | Divine Wound discards, attacker then defender |
| 30 | `BattleAftermath` | active | steps `loser` → `retreat` (winner picks zone) → `winner` |
| 40 | `NightChoice` | active, in turn order | offerings and veteran spending; then `resolveNight` |
| 45 | `NightPowers` | active | Vision discards, then Hand of God |
| 50 | `Destiny` | active | turn-order choice, fewest FP first |
| 90 | `NextPlayer` | game | advances the turn; after 5 actions each, starts Night |
| 98 | `EndScore` | game | sets the tie-breaker and ends the game |

## Rules source

- The rulebook is `rules-reference.pdf` in the project root (gitignored, local only; online copy linked in `README.md`). Extract text with `pdftotext -layout rules-reference.pdf <out>` in Git Bash. Diagrams are image-only and the extraction misses them, including the individual-board action-space layout on page 3. Flag those as unverified instead of guessing.
- When code departs from the rulebook on purpose, record it under "Prototype boundaries" in `README.md`.

## State, data and interface rules

- Declare actions with `#[PossibleAction]` on the state class. Validate the acting player and every input on the server, and throw `UserException` for illegal moves. Every active state needs a valid exit and a `zombie()` that makes a legal choice.
- Hidden information: hands (`diHand`, `battleHand`) are sent only to their owner through `viewState($state, $viewer)` and `_private` state args. War Council choices stay masked until both players commit, and the facedown discard is never revealed. Any new private data must go through `viewState`.
- Notifications: rule methods queue translated log events with `CoreGame::event($type, clienttranslate(...), $args)`. Passing `player_id`/`player_id2` adds `player_name`/`player_name2`, and zones go in as `zoneArg()` nested log args. `notifyState()` saves the state, sends the queued events (the client animates `move`, `battleStart`, `recruit`, and so on), then a silent public `kemetState` sync and a private `hand` notification to each player. Every client handler must be a `notif_<type>` method.
- `getAllDatas()` plus client `setup()` must rebuild the whole board after F5 without relying on earlier notifications.
- Translation: wrap player-facing PHP strings in `clienttranslate(...)` and client strings in `_()`, using placeholders such as `${player_name}` rather than concatenation. Much existing text does not do this yet, so fix it when you touch it.
- Art comes from the publisher's print PDFs in `img/` (gitignored, never deployed). `tools/build-art.py` builds the board, Power tile, five matching DI card, Acquire token, silver Action token, and shared Battle sample assets. The Reinforcement DI card has no matching printed face and remains a placeholder; full Battle faces and colored/gold Action token faces are absent from the supplied PDFs. Action-space positions on the board art are `BOARD_SPACES`/`BUY_SPACES` in `Game.js`. The play area shows no text: names and explanations go in tooltips, and status-bar prompts carry the instructions.
- BGA UI/UX: players click highlighted (`.selectable`) map zones, action spaces, Power tiles, cards, pyramid and unit pieces, veteran tokens, and turn order spaces. Confirm and Cancel use status-bar buttons (`this.bga.statusBar.addActionButton`). Multi-step choices are local steps in `Game.js` (`this.sel`, built in `buildStep()`), with a Cancel button that returns to the start of the phase. The client mirrors some rules only to decide what to highlight; the server stays authoritative. Use BGA player panels, the BGA game log and `addTooltipHtml` rather than custom copies, and never add forms or a separate text-choice tray. Color symbols are on by default through game preference 100.

## Verify and deploy

PHP is available through WSL. Run these from the project root in PowerShell:

```powershell
wsl php -l modules/php/CoreGame.php
node --check modules/js/Game.js
```

Lint every modified PHP file. There is no test suite in the repo. For rule logic, a throwaway script that stubs `Bga\GameFramework\Table` and `UserException` and calls `CoreGame` methods directly works well; keep it outside the repo.

Every change must reach BGA Studio automatically. `tools/deploy-bga.sh` uploads over SFTP (key `~/.ssh/id_rsa_bga`; override with `BGA_SFTP_*` env vars): with no arguments it sends the whole deployable payload, with paths it sends only the deployable ones among them. The project hook in `.claude/settings.json` runs it after every Edit/Write. Files changed any other way (shell, generated images, `git checkout`) need `bash tools/deploy-bga.sh <files>` by hand. The VS Code SFTP extension (`.vscode/sftp.json`, gitignored) also uploads on save from the editor; never commit that file or its credentials. After changing `dbmodel.sql` or state IDs, start a new Studio table.

The user handles browser testing on Studio. Don't use browser automation or play a Studio table unless asked. For an HTTP 500 or a stalled state, get the error and state from Studio's logs and database tools, and redact logs before sharing.

## Git

Commit only when asked. Commit directly on `main` and push to `origin main` immediately after each commit; don't create feature branches.
