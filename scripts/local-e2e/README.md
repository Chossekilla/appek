# Lokální E2E testy pokladny (vyzvednutí) a rozvozu

Běží proti LOKÁLNÍ instalaci (DB `appek`, XAMPP), ne proti demu (náhledový účet na demu nic neuloží).

    /Applications/XAMPP/xamppfiles/bin/php -S 127.0.0.1:8765 -t ~/projects/appek.cz &
    T_EMAIL=<lokální admin> T_PASS=<heslo> python3 scripts/local-e2e/test_pickup.py   # vyzvednutí: 26 kontrol
    T_EMAIL=… T_PASS=… python3 scripts/local-e2e/test_rozvoz.py                      # kurýr + rozvoz + Wolt webhook: 21 kontrol

⚠️ POS endpointy vyžadují balíček Restaurace (`nastaveni.packages_enabled.restaurace=true`) — před testem zapnout,
po testu vrátit. `test_rozvoz.py` přepíše lokální integraci Wolt (testovací secret) — zazálohuj `courier_integrations` řádek `wolt` a obnov.
