# branding/ — zdroj ikony aplikace (PWA)

Sem dej svou ikonu: **`branding/app-icon.png`**

## Požadavky na zdroj
- **čtverec**, ideálně **1024×1024 px**
- **neprůhledné pozadí** (kvůli iOS apple-touch + Android maskable — průhlednost se sloučí na barvu rohu / brand `#BA7517`)
- hlavní prvek (logo / písmeno) **uprostřed s ~15 % okrajem** — Android „maskable" ořezává rohy do kruhu/squircle

## Vygenerování ikon
```
python3 scripts/gen_pwa_icons.py            # vezme branding/app-icon.png
# nebo přímo cesta:
python3 scripts/gen_pwa_icons.py /cesta/k/ikone.png
```
Vyrobí sadu `icon-192/512/maskable/apple` do `admin/icons`, `b2b/icons`, `pos/icons`.
Pak `bash scripts/build-update.sh X.Y.Z` + commit/push → ikona je živá (Android + iOS install).

## Máš jen SVG?
Pillow SVG nerasterizuje — vyexportuj z editoru do **PNG 1024×1024**, nebo pošli SVG a vyrenderuju ho.
