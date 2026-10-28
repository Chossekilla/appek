# WebKit iPhone testy (engine Safari)

Chrome emulace iPhonu NENÍ Safari. Tyhle skripty běží ve skutečném WebKitu (Playwright) s profilem iPhone 13.

Instalace (macOS 13 → Playwright 1.48, novější WebKit potřebuje macOS 14+):

    mkdir -p /tmp/wk && cd /tmp/wk && npm init -y && npm i playwright@1.48.2
    npx playwright install webkit     # když se rozbalení zasekne: unzip ručně do ~/Library/Caches/ms-playwright/webkit-2083 + touch INSTALLATION_COMPLETE

Spuštění (demo login je předvyplněný → skript jen klikne „Přihlásit se"):

    cd /tmp/wk && NODE_PATH=/tmp/wk/node_modules node <repo>/scripts/webkit-iphone/freeze.js
    PINNED=1 …                         # mobilní připnuté menu (rail místo bottom-nav)
    node …/keep-scroll.js              # zachování pozice + test hlídače zaseknutí (0012)

`freeze.js` měří: max mezeru hlavního vlákna, long tasks, posouvání, co je „pod prstem" (neviditelné vrstvy),
pomalé/neúspěšné požadavky. Limit: neumí reálné dotykové posouvání (momentum) — na to je hlídač 0012 v adminu.

## Audit rozložení (`layout-audit.js`)

    cd /tmp/wk && NODE_PATH=/tmp/wk/node_modules OUT=/tmp/la.json SHOTS=/tmp/la node <repo>/scripts/webkit-iphone/layout-audit.js
    PAGES=faktury,suroviny VP=1280,iPhone390 …   # jen vybrané stránky / velikosti

40 stránek adminu × 1280 / iPhone 430 / iPhone 390: vodorovné přetečení, prvky za okrajem (mimo posuvné/ořezávající
kontejnery), oříznutý text. 2026-10-28: admin čistý (jen šipka accordionu +3 px → opr. v625), B2B čistý,
POS hlavička přetékala o 185–396 px na 1280/iPad/1024 → opr. v625.
